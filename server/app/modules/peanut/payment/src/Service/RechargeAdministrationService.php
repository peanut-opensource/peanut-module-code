<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Model\RechargeOrder;
use PeanutAdmin\Modules\Payment\Model\RefundLog;
use PeanutAdmin\Modules\Payment\Model\RefundRecord;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceMutation;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use DateTimeImmutable;
use app\common\enum\AccountLogEnum;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\contract\idempotency\IdempotencyResult;
use app\common\http\PageResult;
use app\common\exception\BusinessException;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use app\common\value\Money;
use app\common\infrastructure\payment\PaymentRetryLock;
use app\common\contract\payment\RefundGatewayInterface;
use app\common\composition\payment\PaymentServiceFactory;
use app\common\services\XlsxExportService;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use think\facade\Db;

/** 充值记录查询、部分退款和失败重试。 */
class RechargeAdministrationService
{
    private const EXPORT_MAX_ROWS = 25000;
    private const EXPORT_DEFAULT_NAME = '充值记录';

    public function __construct(
        private readonly XlsxExportService $xlsxExport,
        private readonly IdempotentCommandExecutor $refundIdempotency,
        private readonly PaymentRetryLock $retryLocks,
        private readonly PaymentServiceFactory $payments,
        private readonly FileReferences $files,
        private readonly MemberBalanceCommands $memberBalances,
        private readonly MemberQueries $members,
    ) {}

    /**
     * @return PageResult|array
     */
    public function lists(object $context, array $params): PageResult|array
    {
        $count = self::buildListQuery($context, $params)->count();
        $pageSize = max(1, min(
            self::EXPORT_MAX_ROWS,
            (int) ($params['page_size'] ?? $params['limit'] ?? 25),
        ));

        if ((int) ($params['export'] ?? 0) === 1) {
            return self::exportInfo($count, $pageSize);
        }
        if ((int) ($params['export'] ?? 0) === 2) {
            return $this->export($context, $params, $count, $pageSize);
        }

        $pageType = (int) ($params['page_type'] ?? 1);
        $pageNo = $pageType === 0
            ? 1
            : PaginationInput::from($params)->page;
        if ($pageType === 0) {
            $pageSize = self::EXPORT_MAX_ROWS;
        }

        $query = self::buildListQuery($context, $params)->order('ro.id', 'desc');
        $pageResult = $pageType === 0
            ? PageResult::fromPaginator($query->paginate([
                'list_rows' => $pageSize,
                'page' => $pageNo,
                'var_page' => 'page_no',
            ]), $pageNo)
            : PaginationInput::from($params)->result($query);
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
        $rows = self::withRefundedAmounts($context, $pageResult->items);

        return new PageResult(
            $this->formatRows($rows),
            $pageResult->total,
            $pageResult->page,
            $pageResult->pageSize,
            ['extend' => []],
        );
    }

    /**
     * 创建一笔部分或全额退款。资格检查在订单行锁内执行，防止并发超额退款。
     */
    public function refund(
        object $context,
        array $params,
        int $adminId,
        string $idempotencyKey,
    ): string {
        $prepared = Db::transaction(function () use ($context, $params, $adminId, $idempotencyKey): array {
            $idempotency = $this->refundIdempotency;
            $order = RechargeOrder::where([])->lock(true)->findOrEmpty((int) $params['recharge_id']);
            self::assertRefundableOrder($order);

            $requestedAmount = $params['refund_amount'] ?? null;
            $requestedCents = $requestedAmount === null || $requestedAmount === ''
                ? null
                : Money::toCents((string) $requestedAmount);
            $lease = $idempotency->begin(IdempotencyCommand::tenant(
                $context,
                'recharge.refund.create',
                $idempotencyKey,
                self::refundRequestHash((int) $order->id, $requestedCents),
                new DateTimeImmutable('+24 hours'),
            ));
            if (!$lease->isExecutionOwner()) {
                return compact('idempotency', 'lease') + ['replay' => true];
            }

            $amountCents = $this->requestedRefundAmountCents($context, $order, $requestedCents);
            $amount = $amountCents / 100;
            $refundSn = RefundRecord::generateSn();

            $order->refund_status = RechargeOrder::REFUND_STATUS_STARTED;
            $order->save();

            $this->memberBalances->applyInTransaction(
                $context,
                new MemberBalanceMutation(
                    (int) $order->user_id,
                    AccountLogEnum::USER_MONEY_DEC_RECHARGE_REFUND,
                    AccountLogEnum::DEC,
                    $amountCents,
                    $refundSn,
                    '充值订单退款',
                    [],
                    $adminId,
                    -$amountCents,
                    '退款失败:用户余额已不足退款金额',
                ),
            );

            $record = RefundRecord::create([
                'sn' => $refundSn,
                'user_id' => (int) $order->user_id,
                'order_id' => (int) $order->id,
                'order_sn' => (string) $order->sn,
                'order_type' => RefundEnum::ORDER_TYPE_RECHARGE,
                'order_amount' => $amount,
                'refund_amount' => $amount,
                'transaction_id' => (string) ($order->transaction_id ?? ''),
                'refund_way' => RefundEnum::getRefundWayByPayWay((int) $order->pay_way),
                'refund_type' => RefundEnum::TYPE_ADMIN,
                'refund_status' => RefundEnum::REFUND_ING,
                'refund_msg' => '',
            ]);
            $log = self::createRefundLog($context, $order, $record, $amount, $adminId);

            return compact('idempotency', 'lease', 'order', 'record', 'log') + ['replay' => false];
        });

        if ($prepared['replay']) {
            return self::replayIdempotentRefund($prepared['lease']);
        }
        $idempotency = $prepared['idempotency'];
        $lease = $prepared['lease'];
        $order = $prepared['order'];
        $record = $prepared['record'];
        $log = $prepared['log'];

        // 渠道调用必须发生在本地原子业务事务提交后，避免渠道已受理而本地整体回滚。
        return $this->requestGatewayRefund($context, $order, $record, $log, $idempotency, $lease);
    }

    /**
     * 失败退款重试：复用 record，只新建 log，不再调整任何账户金额。
     */
    public function refundAgain(object $context, array $params, int $adminId): string
    {
        $recordId = (int) $params['record_id'];
        try {
            return $this->retryLocks->run($context, $recordId, function () use ($context, $recordId, $adminId): string {
                [$order, $record, $log] = Db::transaction(function () use ($context, $recordId, $adminId): array {
                    $record = RefundRecord::where([])->lock(true)->findOrEmpty($recordId);
                    if ($record->isEmpty()) {
                        throw BusinessException::notFound('REFUND_RECORD_NOT_FOUND', '退款记录不存在');
                    }
                    if ((int) $record->refund_status === RefundEnum::REFUND_SUCCESS) {
                        throw BusinessException::conflict('REFUND_ALREADY_SUCCEEDED', '该退款记录已退款成功');
                    }
                    if ((int) $record->refund_status !== RefundEnum::REFUND_ERROR) {
                        throw BusinessException::conflict('REFUND_IN_PROGRESS', '退款正在处理中，请勿重复操作');
                    }

                    $order = RechargeOrder::where([])->lock(true)->findOrEmpty((int) $record->order_id);
                    if ($order->isEmpty()) {
                        throw BusinessException::notFound('RECHARGE_ORDER_NOT_FOUND', '充值订单不存在');
                    }

                    $record->refund_status = RefundEnum::REFUND_ING;
                    $record->refund_msg = '';
                    $record->save();

                    $log = self::createRefundLog(
                        $context,
                        $order,
                        $record,
                        (float) $record->refund_amount,
                        $adminId,
                    );
                    return [$order, $record, $log];
                });

                // 重试同样先提交 ERROR -> ING 和本次日志，再在事务外请求渠道。
                return $this->requestGatewayRefund($context, $order, $record, $log);
            });
        } catch (AdvisoryLockUnavailable) {
            throw BusinessException::conflict('REFUND_IN_PROGRESS', '退款正在处理中，请勿重复操作');
        }
    }

    private static function assertRefundableOrder(object $order): void
    {
        if ($order->isEmpty()) {
            throw BusinessException::notFound('RECHARGE_ORDER_NOT_FOUND', '充值订单不存在');
        }
        if ((int) $order->pay_status !== RechargeOrder::PAY_STATUS_PAID) {
            throw BusinessException::conflict('RECHARGE_ORDER_NOT_REFUNDABLE', '当前订单不可退款');
        }
    }

    private function requestedRefundAmountCents(object $context, object $order, mixed $requested): int
    {
        $orderCents = Money::toCents((string) $order->order_amount);
        $refundedCents = Money::toCents((string) (RefundRecord::where([])
            ->where('order_type', RefundEnum::ORDER_TYPE_RECHARGE)
            ->where('order_id', (int) $order->id)
            ->sum('refund_amount') ?? 0));
        $remainingCents = $orderCents - $refundedCents;
        if ($remainingCents <= 0) {
            throw BusinessException::conflict('REFUND_AMOUNT_EXHAUSTED', '充值订单可退款金额已用尽');
        }

        $amountCents = $requested === null ? $remainingCents : (int) $requested;
        if ($amountCents <= 0 || $amountCents > $remainingCents) {
            throw BusinessException::invalid('REFUND_AMOUNT_INVALID', '退款金额超过当前可退款金额');
        }

        $member = $this->members->balanceSnapshot($context, (int) $order->user_id);
        if ($member === null || $member->balanceCents < $amountCents) {
            throw BusinessException::conflict('REFUND_MEMBER_BALANCE_INSUFFICIENT', '退款失败:用户余额已不足退款金额');
        }
        return $amountCents;
    }

    private static function refundRequestHash(int $orderId, ?int $amountCents): string
    {
        return hash('sha256', json_encode([
            'recharge_id' => $orderId,
            'refund_amount_cents' => $amountCents,
        ], JSON_THROW_ON_ERROR));
    }

    private static function replayIdempotentRefund(IdempotencyResult $idempotency): string
    {
        if ($idempotency->isReplayable()) {
            $body = $idempotency->responseBody();
            if (!($body['success'] ?? false)) {
                throw BusinessException::conflict('REFUND_REPLAY_REJECTED', (string) ($body['message'] ?? '退款请求已被拒绝'));
            }
            return (string) ($body['message'] ?? '操作成功');
        }
        throw BusinessException::conflict('REFUND_IN_PROGRESS', '退款请求仍在处理中，请稍后查询退款记录');
    }

    private static function finishRefundIdempotency(
        ?IdempotentCommandExecutor $idempotency,
        ?IdempotencyResult $lease,
        bool $success,
        string $message,
    ): void {
        if ($idempotency === null || $lease === null || !$lease->isExecutionOwner()) {
            return;
        }
        $body = ['success' => $success, 'message' => $message];
        if ($success) {
            $idempotency->complete($lease, new IdempotencyReceipt(200, $body));
            return;
        }
        $idempotency->fail($lease, new IdempotencyReceipt(400, $body));
    }

    private static function createRefundLog(
        object $context,
        object $order,
        object $record,
        float $amount,
        int $adminId,
    ): object {
        return RefundLog::create([
            'sn' => RefundLog::generateSn(),
            'record_id' => (int) $record->id,
            'user_id' => (int) $order->user_id,
            'handle_id' => $adminId,
            'order_amount' => (float) $order->order_amount,
            'refund_amount' => round($amount, 2),
            'refund_status' => RefundEnum::REFUND_ING,
            'refund_msg' => '',
        ]);
    }

    private function requestGatewayRefund(
        object $context,
        object $order,
        object $record,
        object $log,
        ?IdempotentCommandExecutor $idempotency = null,
        ?IdempotencyResult $lease = null,
    ): string {
        $result = null;
        $gatewayError = null;
        $gatewayFailure = null;
        try {
            $channel = match ((int) $order->pay_way) {
                RechargeOrder::PAY_WAY_WECHAT => 'wechat',
                RechargeOrder::PAY_WAY_ALIPAY => 'alipay',
                default => throw BusinessException::invalid('PAYMENT_CHANNEL_UNSUPPORTED', '支付方式异常'),
            };
            $result = $this->payments->forTenant($context, $channel)->refund($channel)->refund(
                $order->getData(),
                (string) $record->sn,
                Money::toCents((string) $record->refund_amount),
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : '支付渠道退款失败';
            if ((int) $e->getCode() === RefundGatewayInterface::ERROR_RESULT_UNKNOWN) {
                // 请求可能已被渠道受理，保持退款中交由 refund:reconcile 查询收敛。
                $result = [
                    'status' => RefundGatewayInterface::STATUS_PENDING,
                    'transaction_id' => '',
                    'receipt' => ['message' => $message],
                ];
            } else {
                $gatewayError = $message;
                $gatewayFailure = $e;
            }
        }

        $success = $gatewayError === null;
        $businessMessage = $gatewayError ?? '操作成功';

        // 渠道请求完成后使用新的短事务锁定本次记录和日志，原子落下业务结果和幂等回执。
        Db::transaction(function () use (
            $record,
            $log,
            $order,
            $gatewayError,
            $result,
            $idempotency,
            $lease,
            $success,
            $businessMessage,
        ): void {
            $lockedRecord = RefundRecord::where([])->lock(true)->findOrEmpty((int) $record->id);
            $lockedLog = RefundLog::where([])->lock(true)->findOrEmpty((int) $log->id);
            $lockedOrder = RechargeOrder::where([])->lock(true)->findOrEmpty((int) $order->id);
            if ($lockedRecord->isEmpty() || $lockedLog->isEmpty() || $lockedOrder->isEmpty()) {
                throw BusinessException::conflict('REFUND_RESULT_STATE_INVALID', '退款结果关联数据不存在');
            }

            if ($gatewayError !== null) {
                $lockedLog->refund_status = RefundEnum::REFUND_ERROR;
                $lockedRecord->refund_status = RefundEnum::REFUND_ERROR;
                $message = $gatewayError;
            } else {
                $message = self::encodeGatewayResult($result['receipt'] ?? []);
                if (($result['status'] ?? '') === RefundGatewayInterface::STATUS_SUCCESS) {
                    $lockedLog->refund_status = RefundEnum::REFUND_SUCCESS;
                    $lockedRecord->refund_status = RefundEnum::REFUND_SUCCESS;
                    $lockedOrder->refund_transaction_id = (string) ($result['transaction_id'] ?? '');
                    $lockedOrder->save();
                }
            }

            $lockedLog->refund_msg = $message;
            $lockedRecord->refund_msg = $message;
            $lockedLog->save();
            $lockedRecord->save();
            self::finishRefundIdempotency($idempotency, $lease, $success, $businessMessage);
        });

        if ($gatewayFailure instanceof \Throwable) {
            throw $gatewayFailure;
        }
        return $businessMessage;
    }

    private static function encodeGatewayResult(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** 同库只读会员关联；所属订单Scope与JOIN同时限定tenant，保留历史软删会员和INNER JOIN语义。 */
    private static function buildListQuery(object $context, array $params): \think\db\Query
    {
        $query = RechargeOrder::alias('ro')->where([])
            ->join('member u', 'u.tenant_id = ro.tenant_id AND u.id = ro.user_id')
            ->field(
                'ro.id,ro.sn,ro.order_amount,ro.pay_way,ro.pay_time,'
                . 'ro.pay_status,ro.create_time,ro.refund_status,'
                . 'u.avatar,u.nickname,u.account',
            );

        if (!empty($params['sn'])) {
            $query->where('ro.sn', trim((string) $params['sn']));
        }
        $userInfo = trim((string) ($params['user_info'] ?? $params['keyword'] ?? ''));
        if ($userInfo !== '') {
            $query->where(
                'u.sn|u.nickname|u.mobile|u.account',
                'like',
                '%' . $userInfo . '%',
            );
        }
        if (isset($params['pay_way']) && $params['pay_way'] !== '') {
            $query->where('ro.pay_way', (int) $params['pay_way']);
        }
        $payStatus = $params['pay_status'] ?? $params['status'] ?? '';
        if ($payStatus !== '') {
            $query->where('ro.pay_status', (int) $payStatus);
        }
        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $query->whereBetween('ro.create_time', [
                strtotime((string) $params['start_time']),
                strtotime((string) $params['end_time']),
            ]);
        }

        return $query;
    }

    private static function withRefundedAmounts(object $context, array $rows): array
    {
        $orderIds = array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
        $amounts = $orderIds === [] ? [] : RefundRecord::where([])
            ->where('order_type', RefundEnum::ORDER_TYPE_RECHARGE)
            ->whereIn('order_id', $orderIds)
            ->group('order_id')
            ->column('SUM(refund_amount)', 'order_id');
        foreach ($rows as &$row) {
            $row['refunded_amount'] = $amounts[(int) $row['id']] ?? 0;
        }
        unset($row);
        return $rows;
    }

    private function formatRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['pay_way'] = (int) $row['pay_way'];
            $row['pay_status'] = (int) $row['pay_status'];
            $row['refund_status'] = (int) $row['refund_status'];
            $orderCents = Money::toCents((string) $row['order_amount']);
            $refundedCents = Money::toCents((string) ($row['refunded_amount'] ?? 0));
            $row['refunded_amount'] = Money::fromCents($refundedCents);
            $row['refundable_amount'] = Money::fromCents(max(0, $orderCents - $refundedCents));
            $row['pay_way_text'] = [
                RechargeOrder::PAY_WAY_BALANCE => '余额支付',
                RechargeOrder::PAY_WAY_WECHAT => '微信支付',
                RechargeOrder::PAY_WAY_ALIPAY => '支付宝支付',
            ][$row['pay_way']] ?? '';
            $row['pay_status_text'] = [
                RechargeOrder::PAY_STATUS_UNPAID => '未支付',
                RechargeOrder::PAY_STATUS_PAID => '已支付',
            ][$row['pay_status']] ?? '';
            $row['avatar'] = $this->files->getFileUrl((string) ($row['avatar'] ?? ''));
            $row['pay_time'] = self::formatTime($row['pay_time'] ?? 0);
            $row['create_time'] = self::formatTime($row['create_time'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private static function formatTime(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }
        return is_numeric($value) ? date('Y-m-d H:i:s', (int) $value) : (string) $value;
    }

    private static function exportInfo(int $count, int $pageSize): array
    {
        return ExportPageInfo::from(
            $count,
            $pageSize,
            self::EXPORT_MAX_ROWS,
            self::EXPORT_DEFAULT_NAME,
        )->toArray();
    }

    private function export(object $context, array $params, int $count, int $pageSize): array
    {
        if ($count === 0) {
            throw BusinessException::invalid('RECHARGE_EXPORT_EMPTY', '没有数据，无法导出');
        }

        $pageType = (int) ($params['page_type'] ?? 0);
        if ($pageType === 1) {
            $pageStart = max(1, (int) ($params['page_start'] ?? 1));
            $pageEnd = max($pageStart, (int) ($params['page_end'] ?? $pageStart));
            $offset = ($pageStart - 1) * $pageSize;
            $limit = ($pageEnd - $pageStart + 1) * $pageSize;
            if ($limit > self::EXPORT_MAX_ROWS) {
                throw BusinessException::invalid(
                    'RECHARGE_EXPORT_LIMIT_EXCEEDED',
                    '已超出系统限制数量，请分页查询或导出，当前最多记录数为：25000',
                );
            }
            if ($offset >= $count) {
                throw BusinessException::invalid(
                    'RECHARGE_EXPORT_RANGE_EMPTY',
                    '第' . $pageStart . '页到第' . $pageEnd . '页没有数据，无法导出',
                );
            }
        } else {
            $offset = 0;
            $limit = min($count, self::EXPORT_MAX_ROWS);
        }

        $rows = self::withRefundedAmounts($context, self::buildListQuery($context, $params)
            ->order('ro.id', 'desc')
            ->limit($offset, $limit)
            ->select()
            ->toArray());
        $file = $this->xlsxExport->create(
            (string) ($params['file_name'] ?? self::EXPORT_DEFAULT_NAME),
            ['充值单号', '用户昵称', '充值金额', '支付方式', '支付状态', '支付时间', '下单时间'],
            array_map(static fn(array $row): array => [
                $row['sn'],
                $row['nickname'],
                (float) $row['order_amount'],
                $row['pay_way_text'],
                $row['pay_status_text'],
                $row['pay_time'],
                $row['create_time'],
            ], $this->formatRows($rows)),
        );

        return [
            'url' => $file['url'],
            'file_name' => $file['original_name'],
        ];
    }

}
