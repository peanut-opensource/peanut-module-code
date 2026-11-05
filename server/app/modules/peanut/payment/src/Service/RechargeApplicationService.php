<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Model\PaymentScene;
use PeanutAdmin\Modules\Payment\Model\RechargeOrder;
use PeanutAdmin\Modules\Payment\Model\RechargePaymentAttempt;
use app\common\http\PageResult;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceMutation;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Modules\OAuth\Contract\OAuthQueries;
use PeanutAdmin\Modules\Member\Value\AccountLogEnum;
use PeanutAdmin\Modules\Payment\Value\UserTerminalEnum;
use app\common\exception\BusinessException;
use app\common\contract\audit\AuditResource;
use app\common\execution\CurrentExecutionContext;
use think\facade\Db;
use app\common\value\Money;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantContext;
use PeanutAdmin\Modules\Payment\Contract\PaymentChannelGrantCommands;
use PeanutAdmin\Modules\Payment\Contract\RechargeCommands;
use PeanutAdmin\Modules\Payment\Contract\RechargeQueries;
use app\common\composition\payment\PaymentServiceFactory;
use app\common\persistence\AdvisoryLockExecution;
use app\common\persistence\AdvisoryLockUnavailable;
use PeanutAdmin\Kernel\Tenancy\TenantNamespace;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use app\common\dto\payment\CallbackRequest;
use app\common\dto\payment\PaymentEvent;
use app\common\dto\payment\PrepayRequest;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Audit\AuditOutcome;

/** 用户充值订单和幂等入账状态机。 */
final class RechargeApplicationService implements RechargeCommands, RechargeQueries
{
    private const MAX_AMOUNT_CENTS = 99999999;

    public function __construct(
        private readonly AuditContractHost $audit,
        private readonly MemberQueries $members,
        private readonly MemberBalanceCommands $memberBalances,
        private readonly OAuthQueries $oauth,
        private readonly CurrentExecutionContext $executionContext,
        private readonly RechargeTenantSettingService $rechargeSettings,
        private readonly PaymentChannelGrantCommands $channelGrants,
        private readonly PaymentServiceFactory $payments,
        private readonly AdvisoryLockExecution $locks,
    ) {}

    public function config(object $context, int $memberId, int $terminal): array
    {
        self::assertTerminal($terminal);
        $member = $this->members->balanceSnapshot($context, $memberId);
        if ($member === null) {
            throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
        }

        $setting = $this->rechargeSettings->config($context);
        $scenes = $this->rechargeSettings->enabledScenes($context, $terminal);
        $scenes = array_values(array_filter(
            $scenes,
            fn(array $scene): bool => $this->rechargeSettings->channelConfigured(
                $context,
                (int) $scene['pay_way'],
            ),
        ));

        return [
            'status' => (int) $setting['status'],
            'min_amount' => self::moneyString($setting['min_amount']),
            'balance' => Money::fromCents($member->balanceCents),
            'terminal' => $terminal,
            'channels' => array_map(static fn(array $scene): array => [
                'pay_way' => (int) $scene['pay_way'],
                'name' => PaymentScene::getPayWayDesc((int) $scene['pay_way']),
                'is_default' => (int) $scene['is_default'],
            ], $scenes),
        ];
    }

    public function create(object $context, int $memberId, array $params): array
    {
        $terminal = (int) $params['terminal'];
        self::assertTerminal($terminal);
        $setting = $this->rechargeSettings->config($context);
        if ((int) $setting['status'] !== 1) {
            throw BusinessException::forbidden('RECHARGE_DISABLED', '充值功能未开启');
        }

        $amountCents = self::moneyToCents((string) $params['amount']);
        $minCents = self::moneyToCents((string) $setting['min_amount']);
        if ($amountCents <= 0 || $amountCents < $minCents) {
            throw BusinessException::invalid('RECHARGE_AMOUNT_BELOW_MINIMUM', '充值金额不能低于最低充值金额');
        }
        $configuredMax = self::moneyToCents((string) $setting['max_amount']);
        $maxCents = min(self::MAX_AMOUNT_CENTS, $configuredMax);
        if ($amountCents > $maxCents) {
            throw BusinessException::invalid('RECHARGE_AMOUNT_ABOVE_MAXIMUM', '充值金额超过单次上限');
        }

        if ($this->members->balanceSnapshot($context, $memberId) === null) {
            throw BusinessException::notFound('MEMBER_NOT_FOUND', '用户不存在');
        }
        $defaultScene = $this->rechargeSettings->defaultScene($context, $terminal);
        if ($defaultScene === null
            || !$this->rechargeSettings->channelConfigured($context, (int) $defaultScene['pay_way'])) {
            throw BusinessException::conflict('RECHARGE_CHANNEL_UNAVAILABLE', '当前终端暂无可用支付方式');
        }

        $order = Db::transaction(function () use (
            $context,
            $memberId,
            $defaultScene,
            $amountCents,
            $terminal,
        ): object {
            $order = RechargeOrder::create([
                'sn' => RechargeOrder::generateSn(),
                'user_id' => $memberId,
                'pay_sn' => null,
                'pay_way' => (int) $defaultScene['pay_way'],
                'pay_status' => RechargeOrder::PAY_STATUS_UNPAID,
                'pay_time' => null,
                'order_amount' => self::centsToMoney($amountCents),
                'order_terminal' => $terminal,
                'transaction_id' => null,
                'refund_status' => RechargeOrder::REFUND_STATUS_NONE,
            ]);
            $this->recordPublicFinanceAudit(
                $context,
                'public.recharge.created',
                'recharge.create',
                $order,
                ['business_member_id' => $memberId, 'amount_cents' => $amountCents],
            );
            return $order;
        });

        return self::formatOrder($order->toArray());
    }

    /** 锁定本人未支付订单并固化本次支付渠道、授权和请求号。 */
    private function prepareAttempt(object $context, int $memberId, int $orderId, int $payWay): array
    {
        return Db::transaction(function () use ($context, $memberId, $orderId, $payWay): array {
            $order = RechargeOrder::where([])->lock(true)->findOrEmpty($orderId);
            self::assertOwnedUnpaid($order, $memberId);
            $terminal = (int) $order->order_terminal;
            if (!PaymentScene::supports($terminal, $payWay)) {
                throw BusinessException::conflict('RECHARGE_PAY_WAY_DISABLED', '当前终端未启用该支付方式');
            }
            $scene = $this->rechargeSettings->scene($context, $terminal, $payWay);
            if ($scene === null) {
                throw BusinessException::conflict('RECHARGE_PAY_WAY_DISABLED', '当前终端未启用该支付方式');
            }
            if (!$this->rechargeSettings->channelConfigured($context, $payWay)) {
                throw BusinessException::conflict('PAYMENT_CHANNEL_UNAVAILABLE', '支付渠道未启用或配置不完整');
            }
            $provider = $this->channelGrants->providerForPayWay($payWay);
            $grant = $this->channelGrants->activeGrantForTenant($context, $provider, true);

            $open = RechargePaymentAttempt::where('order_id', $orderId)
                ->where('status', RechargePaymentAttempt::OPEN)->findOrEmpty();
            if (!$open->isEmpty()) {
                if ((int) $open->binding_id !== (int) $grant['external_binding_id']
                    || (int) $open->pay_way !== $payWay) {
                    throw BusinessException::conflict('PAYMENT_ATTEMPT_OPEN', '旧支付单状态未确认');
                }
                return ['order' => $order->toArray(), 'grant' => $grant, 'paymentAttempt' => $open];
            }
            if (((int) $order->payment_binding_id > 0 || (string) $order->pay_sn !== '')
                && RechargePaymentAttempt::where('order_id', $orderId)->count() === 0) {
                throw BusinessException::conflict('PAYMENT_ORIGIN_UNVERIFIED', '原收款商户待核对');
            }

            $paymentAttempt = RechargePaymentAttempt::create([
                'order_id' => $orderId,
                'pay_way' => $payWay,
                'binding_id' => (int) $grant['external_binding_id'],
                'grant_id' => (int) $grant['id'],
                'merchant_identity_hash' => (string) $grant['identity_hash'],
                'merchant_order_sn' => RechargeOrder::generatePaySn(),
                'amount_cents' => self::moneyToCents((string) $order->order_amount),
                'currency' => 'CNY',
                'expires_at' => $payWay === RechargeOrder::PAY_WAY_ALIPAY && $terminal === PrepayRequest::TERMINAL_PC
                    ? time() + 1800 : null,
                'status' => RechargePaymentAttempt::OPEN,
            ]);

            $this->recordPublicFinanceAudit(
                $context,
                'public.recharge.payment-prepared',
                'recharge.prepare-payment',
                $order,
                [
                    'business_member_id' => $memberId,
                    'pay_way' => $payWay,
                    'payment_binding_id' => (int) $grant['external_binding_id'],
                ],
            );
            return [
                'order' => $order->toArray(),
                'grant' => $grant,
                'paymentAttempt' => $paymentAttempt,
            ];
        });
    }

    /** 创建真实渠道预支付参数；渠道调用通过 PaymentServiceFactory 边界完成。 */
    public function prepay(
        object $context,
        int $memberId,
        int $orderId,
        int $payWay,
        string $notifyUrl,
        string $clientIp = '',
        string $openid = '',
    ): array {
        $scope = TenantScope::fromTrustedContext($this->executionContext->tenantId(), $this->executionContext->current()?->requestId() ?? '');
        $lock = TenantNamespace::lockName($scope, 'recharge:prepay:' . $orderId);
        try {
            return $this->locks->run($lock, 0, function () use (
                $context, $memberId, $orderId, $payWay, $notifyUrl, $clientIp, $openid,
            ): array {
                $order = RechargeOrder::where([])->findOrEmpty($orderId);
                self::assertOwnedUnpaid($order, $memberId);
                $terminal = (int) $order->order_terminal;
                if ($payWay === RechargeOrder::PAY_WAY_WECHAT && in_array($terminal, [1, 2], true)) {
                    $openid = $this->oauth->wechatSubjectForMember($context, $memberId, $terminal);
                    if ($openid === '') {
                        throw BusinessException::conflict('PAYMENT_WECHAT_IDENTITY_REQUIRED', '当前微信终端尚未绑定可用身份');
                    }
                }
                if ($payWay === RechargeOrder::PAY_WAY_WECHAT && $terminal === PrepayRequest::TERMINAL_H5
                    && filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
                    throw BusinessException::invalid('PAYMENT_CLIENT_IP_REQUIRED', '微信 H5 支付缺少有效客户端 IP');
                }
                $this->closePreviousAttempt($context, $order, $payWay);
                return $this->prepayLocked($context, $memberId, $orderId, $payWay, $notifyUrl, $clientIp, $openid);
            });
        } catch (AdvisoryLockUnavailable) {
            throw BusinessException::conflict('PAYMENT_IN_PROGRESS', '支付单处理中，请稍后查询');
        }
    }

    private function prepayLocked(
        object $context,
        int $memberId,
        int $orderId,
        int $payWay,
        string $notifyUrl,
        string $clientIp,
        string $openid,
    ): array {
        $attempt = $this->prepareAttempt($context, $memberId, $orderId, $payWay);
        $order = $attempt['order'];
        $grant = $attempt['grant'];
        $paymentAttempt = $attempt['paymentAttempt'];
        $channel = match ($payWay) {
            RechargeOrder::PAY_WAY_WECHAT => 'wechat',
            RechargeOrder::PAY_WAY_ALIPAY => 'alipay',
            default => throw BusinessException::invalid('PAYMENT_CHANNEL_UNSUPPORTED', '支付渠道不受支持'),
        };
        $notifyUrl = rtrim($notifyUrl, '/')
            . '/api/payment/notify/' . $channel . '/' . (string) $grant['callback_key'];
        $request = new PrepayRequest(
            (string) $paymentAttempt->merchant_order_sn,
            (int) $paymentAttempt->amount_cents,
            (int) $order['order_terminal'],
            $notifyUrl,
            '账户充值',
            'CNY',
            $openid,
            $clientIp,
            $paymentAttempt->expires_at === null ? null : (int) $paymentAttempt->expires_at,
        );
        $result = $this->payments->forBinding(
            $channel, $grant['config'], (string) $paymentAttempt->merchant_identity_hash,
        )->prepay($request);
        return [
            'order' => self::formatOrder($order),
            'payment' => $result->toArray(),
        ];
    }

    private function closePreviousAttempt(object $context, RechargeOrder $order, int $payWay): void
    {
        $terminal = (int) $order->order_terminal;
        if (!PaymentScene::supports($terminal, $payWay) || $this->rechargeSettings->scene($context, $terminal, $payWay) === null
            || !$this->rechargeSettings->channelConfigured($context, $payWay)) {
            throw BusinessException::conflict('RECHARGE_PAY_WAY_DISABLED', '当前终端未启用该支付方式');
        }
        $provider = $this->channelGrants->providerForPayWay($payWay);
        $grant = $this->channelGrants->activeGrantForTenant($context, $provider);
        $origin = RechargePaymentAttempt::where('order_id', (int) $order->id)
            ->where('status', RechargePaymentAttempt::OPEN)->findOrEmpty();
        if ($origin->isEmpty()) {
            return;
        }
        $expired = $origin->expires_at !== null && (int) $origin->expires_at <= time();
        $retrySameTrade = !$expired && (int) $origin->pay_way === $payWay
            && (int) $origin->binding_id === (int) $grant['external_binding_id'];
        $channel = $this->channelGrants->channelForPayWay((int) $origin->pay_way);
        $gateway = $this->payments->forHistory(
            (int) $origin->tenant_id, $channel, (int) $origin->binding_id, (string) $origin->merchant_identity_hash,
        );
        $number = (string) $origin->merchant_order_sn;
        try {
            $result = $gateway->queryPayment($number);
        } catch (\RuntimeException $exception) {
            if ($retrySameTrade) {
                // A safe retry keeps the exact same provider order number.
                return;
            }
            throw $exception;
        }
        if ($result['event'] !== null) {
            $this->settleVerifiedCallback((int) $origin->binding_id, $result['event'], (int) $origin->pay_way);
            throw BusinessException::conflict('RECHARGE_ALREADY_PAID', '原支付单已到账');
        }
        if ($retrySameTrade && $result['status'] !== 'closed') {
            return;
        }
        if ($result['status'] === 'unpaid') {
            $gateway->close($number);
        } elseif ($result['status'] !== 'closed' && !($result['status'] === 'absent' && $expired)) {
            throw BusinessException::conflict('PAYMENT_ATTEMPT_OPEN', '旧支付单状态未确认');
        }
        RechargePaymentAttempt::where('id', (int) $origin->id)
            ->where('status', RechargePaymentAttempt::OPEN)->update(['status' => RechargePaymentAttempt::CLOSED]);
    }

    public function parseCallback(string $channel, array $config, CallbackRequest $request): PaymentEvent
    {
        return $this->payments->forConfig($channel, $config)->parse($request);
    }

    public function detail(object $context, int $memberId, int $orderId): array
    {
        $order = RechargeOrder::where([])->where(['id' => $orderId, 'user_id' => $memberId])->findOrEmpty();
        if ($order->isEmpty()) {
            throw BusinessException::notFound('RECHARGE_ORDER_NOT_FOUND', '充值订单不存在');
        }
        return self::formatOrder($order->toArray());
    }

    public function lists(object $context, int $memberId, array $params): PageResult
    {
        $pageNo = max(1, (int) ($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int) ($params['page_size'] ?? 15)));
        $query = RechargeOrder::where([])->where('user_id', $memberId);
        $pageResult = PaginationInput::from($params)->result($query->order('id', 'desc'));
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
        $rows = $pageResult->items;
        return new PageResult(
            array_map([self::class, 'formatOrder'], $rows),
            $pageResult->total,
            $pageResult->page,
            $pageResult->pageSize,
        );
    }

    public function settleVerifiedCallback(int $paymentBindingId, PaymentEvent $event, int $payWay): bool
    {
        $tenantId = $this->executionContext->tenantId();
        if ($tenantId < 1 || $paymentBindingId < 1 || $event->status() !== 'success') {
            throw BusinessException::forbidden('PAYMENT_CALLBACK_GRANT_REQUIRED', '支付结果授权缺失');
        }
        $context = ExternalTenantContext::verified(
            $tenantId,
            'payment.settle',
            'payment:' . hash('sha256', $event->orderSn() . ':' . $paymentBindingId),
        );
        $origin = RechargePaymentAttempt::where('binding_id', $paymentBindingId)
            ->where('merchant_order_sn', $event->orderSn())->findOrEmpty();
        if ($origin->isEmpty()) {
            throw BusinessException::notFound('PAYMENT_ATTEMPT_NOT_FOUND', '原支付尝试待核对');
        }

        return Db::transaction(function () use ($context, $origin, $event, $payWay): bool {
            $order = RechargeOrder::where([])->lock(true)->findOrEmpty((int) $origin->order_id);
            $attempt = RechargePaymentAttempt::where([])->lock(true)->findOrEmpty((int) $origin->id);
            if ($order->isEmpty() || $attempt->isEmpty()) {
                throw BusinessException::notFound('RECHARGE_ORDER_NOT_FOUND', '充值订单不存在');
            }
            $identity = hash('sha256', strtolower($event->appIdentity() . ':' . $event->merchantIdentity()));
            if ((int) $attempt->pay_way !== $payWay || self::channelToPayWay($event->channel()) !== $payWay
                || !hash_equals((string) $attempt->merchant_identity_hash, $identity)) {
                throw BusinessException::conflict('PAYMENT_GRANT_MISMATCH', '支付商户身份不一致');
            }
            if ((int) $attempt->amount_cents !== $event->amount() || (string) $attempt->currency !== $event->currency()
                || self::moneyToCents((string) $order->order_amount) !== $event->amount()) {
                throw BusinessException::conflict('PAYMENT_AMOUNT_MISMATCH', '支付金额或币种不一致');
            }
            if ($attempt->transaction_id !== null) {
                if ((string) $attempt->transaction_id !== $event->transactionId()) {
                    throw BusinessException::conflict('PAYMENT_TRANSACTION_CONFLICT', '支付交易流水冲突');
                }
                return true;
            }

            $attempt->transaction_id = $event->transactionId();
            if ((int) $order->pay_status === RechargeOrder::PAY_STATUS_PAID) {
                $attempt->status = RechargePaymentAttempt::DUPLICATE;
                $attempt->save();
                $this->recordPublicFinanceAudit($context, 'public.recharge.duplicate-payment', 'recharge.settle', $order, [
                    'payment_attempt_id' => (int) $attempt->id,
                    'amount_cents' => $event->amount(),
                ]);
                return true;
            }

            $attempt->status = RechargePaymentAttempt::PAID;
            $attempt->save();
            $this->memberBalances->applyInTransaction(
                $context,
                new MemberBalanceMutation(
                    (int) $order->user_id,
                    AccountLogEnum::USER_MONEY_INC_RECHARGE,
                    AccountLogEnum::INC,
                    $event->amount(),
                    (string) $order->sn,
                    '用户充值',
                    [],
                    0,
                    $event->amount(),
                ),
            );
            $order->pay_status = RechargeOrder::PAY_STATUS_PAID;
            $order->pay_time = time();
            $order->pay_way = $payWay;
            $order->pay_sn = (string) $attempt->merchant_order_sn;
            $order->payment_binding_id = (int) $attempt->binding_id;
            $order->payment_grant_id = (int) $attempt->grant_id;
            $order->payment_merchant_account_ref = (string) $attempt->merchant_identity_hash;
            $order->transaction_id = $event->transactionId();
            $order->save();

            $this->recordPublicFinanceAudit($context, 'public.recharge.settled', 'recharge.settle', $order, [
                'business_member_id' => (int) $order->user_id,
                'amount_cents' => $event->amount(),
                'pay_way' => $payWay,
                'payment_binding_id' => (int) $attempt->binding_id,
            ]);

            return true;
        });
    }

    private static function assertOwnedUnpaid(object $order, int $memberId): void
    {
        if ($order->isEmpty() || (int) $order->user_id !== $memberId) {
            throw BusinessException::notFound('RECHARGE_ORDER_NOT_FOUND', '充值订单不存在');
        }
        if ((int) $order->pay_status !== RechargeOrder::PAY_STATUS_UNPAID) {
            throw BusinessException::conflict('RECHARGE_ORDER_ALREADY_PAID', '充值订单已支付');
        }
    }

    /** @param array<string,mixed> $metadata */
    private function recordPublicFinanceAudit(
        object $context,
        string $eventType,
        string $operation,
        object $order,
        array $metadata,
    ): void {
        $tenantId = $this->executionContext->tenantId();
        $current = $this->executionContext->current();
        $requestId = $current !== null && $current->tenantId() === $tenantId
            ? $current->requestId()
            : trim((string) ($context->requestId ?? $context->operationId ?? ''));
        if ($requestId === '') {
            throw new \DomainException('RECHARGE_AUDIT_REQUEST_ID_REQUIRED');
        }
        $this->audit->recordTenantSystem(
            $tenantId,
            $eventType,
            $operation,
            $requestId,
            ['subject_type' => 'business_member'] + $metadata,
            AuditOutcome::Success,
            null,
            new AuditResource('recharge_order', (string) $order->id),
        );
    }

    private static function assertTerminal(int $terminal): void
    {
        if (!UserTerminalEnum::isValid($terminal)) {
            throw BusinessException::invalid('PAYMENT_TERMINAL_UNSUPPORTED', '支付终端不支持');
        }
    }

    private static function channelToPayWay(string $channel): int
    {
        return match (strtolower(trim($channel))) {
            'wechat' => RechargeOrder::PAY_WAY_WECHAT,
            'alipay' => RechargeOrder::PAY_WAY_ALIPAY,
            default => throw BusinessException::invalid('PAYMENT_CHANNEL_UNSUPPORTED', '支付渠道不受支持'),
        };
    }

    private static function moneyToCents(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.\d{1,2})?$/', $amount)) {
            throw BusinessException::invalid('MONEY_FORMAT_INVALID', '金额格式错误');
        }
        [$yuan, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return ((int) $yuan * 100) + (int) str_pad($fraction, 2, '0');
    }

    private static function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function moneyString(mixed $amount): string
    {
        return self::centsToMoney(self::moneyToCents((string) $amount));
    }

    private static function formatOrder(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'sn' => (string) $row['sn'],
            'pay_way' => (int) $row['pay_way'],
            'pay_way_text' => PaymentScene::getPayWayDesc((int) $row['pay_way']),
            'pay_status' => (int) $row['pay_status'],
            'pay_status_text' => (int) $row['pay_status'] === RechargeOrder::PAY_STATUS_PAID ? '已支付' : '未支付',
            'order_amount' => self::moneyString($row['order_amount']),
            'order_terminal' => (int) $row['order_terminal'],
            'terminal_text' => UserTerminalEnum::getDesc((int) $row['order_terminal']),
            'transaction_id' => (string) ($row['transaction_id'] ?? ''),
            'pay_time' => empty($row['pay_time']) ? '' : date('Y-m-d H:i:s', (int) $row['pay_time']),
            'create_time' => empty($row['create_time']) ? '' : (is_numeric($row['create_time']) ? date('Y-m-d H:i:s', (int) $row['create_time']) : (string) $row['create_time']),
        ];
    }
}
