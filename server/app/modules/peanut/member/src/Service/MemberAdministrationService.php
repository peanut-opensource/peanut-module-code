<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Service;

use PeanutAdmin\Modules\Member\Model\Member;
use PeanutAdmin\Modules\Member\Model\MemberBalanceLog;
use PeanutAdmin\Modules\Member\Model\MemberTag;
use PeanutAdmin\Modules\Member\Model\MemberTagRelation;
use DateTimeImmutable;
use app\common\exception\BusinessException;
use app\common\enum\AccountLogEnum;
use app\common\http\PageResult;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\idempotency\IdempotencyCommand;
use app\common\contract\idempotency\IdempotencyReceipt;
use app\common\enum\MemberChannelEnum;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Member\Contract\Dto\MemberBalanceMutation;
use PeanutAdmin\Modules\Member\Contract\MemberAdministration;
use PeanutAdmin\Modules\Member\Contract\MemberBalanceCommands;
use PeanutAdmin\Modules\Member\Contract\MemberProfileCommands;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use PeanutAdmin\Modules\Member\Contract\MemberTagCommands;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use app\common\value\Money;
use app\common\services\XlsxExportService;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use think\facade\Db;

final class MemberAdministrationService implements MemberAdministration
{
    private const EXPORT_MAX_ROWS = 25000;
    private const EXPORT_DEFAULT_NAME = '用户列表';
    private const BALANCE_LOG_MAX_ROWS = 25000;

    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly XlsxExportService $xlsxExport,
        private readonly MemberQueries $queries,
        private readonly MemberProfileCommands $profiles,
        private readonly MemberTagCommands $tags,
        private readonly MemberBalanceCommands $balances,
        private readonly IdempotentCommandExecutor $idempotency,
        private readonly FileReferences $files,
    ) {}

    /**
     * 用户分页列表；export=1 返回导出信息，export=2 生成 XLSX 并返回 URL。
     *
     * @return PageResult|array<string,mixed>
     */
    public function members(array $params): PageResult|array
    {
        $exportMode = (int) ($params['export'] ?? 0);
        $count = $exportMode === 1 || $exportMode === 2
            ? $this->buildListQuery($params)->count()
            : 0;
        $pageSize = (int) ($params['page_size'] ?? $params['limit'] ?? 15);
        $pageSize = max(1, min(100, $pageSize));

        if ($exportMode === 1) {
            return self::exportInfo($count, $pageSize);
        }
        if ($exportMode === 2) {
            return $this->export($params, $count, $pageSize);
        }

        $pageResult = PaginationInput::from($params)->result($this->buildListQuery($params));
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
        $rows = $pageResult->items;
        $rows = $this->hydrateTags($rows);

        return new PageResult(
            $this->formatRows($rows),
            $pageResult->total,
            $pageResult->page,
            $pageResult->pageSize,
        );
    }

    public function memberDetail(int $id): array
    {
        $data = $this->queries->memberFields($this->executionContext->tenantAdmin(), $id, [
            'id', 'sn', 'account', 'nickname', 'avatar', 'real_name',
            'sex', 'mobile', 'create_time', 'login_time', 'channel',
            'user_money',
        ]);
        if ($data === []) {
            return [];
        }

        $data['id'] = (int) $data['id'];
        $data['sex'] = (int) $data['sex'];
        $data['channel'] = MemberChannelEnum::getDesc((int) $data['channel']);
        $data['avatar'] = $this->files->getFileUrl((string) ($data['avatar'] ?? ''));
        $data['create_time'] = self::formatTime($data['create_time']);
        $data['login_time'] = self::formatTime($data['login_time']);
        $data['user_money'] = (float) $data['user_money'];
        $data['balance'] = $data['user_money'];
        return $data;
    }

    private function buildListQuery(array $params)
    {
        $query = Member::where([]);
        if (!empty($params['keyword'])) {
            $keyword = trim((string) $params['keyword']);
            $query->where('sn|nickname|mobile|account', 'like', '%' . $keyword . '%');
        }
        if (!empty($params['channel'])) {
            $query->where('channel', (int) $params['channel']);
        }
        if (!empty($params['create_time_start'])) {
            $query->where('create_time', '>=', strtotime((string) $params['create_time_start']));
        }
        if (!empty($params['create_time_end'])) {
            $query->where('create_time', '<=', strtotime((string) $params['create_time_end']));
        }

        // Peanut 原有状态筛选作为兼容扩展保留，不替代参考筛选字段。
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }
        return $query->order('id', 'desc');
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

    private function export(array $params, int $count, int $pageSize): array
    {
        if ($count === 0) {
            throw BusinessException::invalid('MEMBER_EXPORT_EMPTY', '没有数据，无法导出');
        }

        $pageType = (int) ($params['page_type'] ?? 0);
        if ($pageType === 1) {
            $pageStart = max(1, (int) ($params['page_start'] ?? 1));
            $pageEnd = max($pageStart, (int) ($params['page_end'] ?? $pageStart));
            $offset = ($pageStart - 1) * $pageSize;
            $limit = ($pageEnd - $pageStart + 1) * $pageSize;
            if ($limit > self::EXPORT_MAX_ROWS) {
                throw BusinessException::invalid('MEMBER_EXPORT_LIMIT_EXCEEDED', '已超出系统导出限制，当前最多导出25000条记录');
            }
            if ($offset >= $count) {
                throw BusinessException::invalid('MEMBER_EXPORT_RANGE_EMPTY', '所选分页范围没有数据，无法导出');
            }
        } else {
            $offset = 0;
            $limit = min($count, self::EXPORT_MAX_ROWS);
        }

        $rows = $this->buildListQuery($params)
            ->limit($offset, $limit)
            ->select()
            ->toArray();
        $rows = $this->hydrateTags($rows);
        $rows = $this->formatRows($rows);
        $file = $this->xlsxExport->create(
            (string) ($params['file_name'] ?? self::EXPORT_DEFAULT_NAME),
            ['用户编号', '用户昵称', '账号', '手机号码', '注册来源', '注册时间'],
            array_map(static fn(array $row): array => [
                $row['sn'],
                $row['nickname'],
                $row['account'],
                $row['mobile'],
                $row['channel'],
                $row['create_time'],
            ], $rows),
        );

        return [
            'url' => $file['url'],
            'file_name' => $file['original_name'],
        ];
    }

    private function formatRows(array $rows): array
    {
        $sexDesc = [0 => '未知', 1 => '男', 2 => '女'];
        foreach ($rows as &$row) {
            $sex = (int) ($row['sex'] ?? 0);
            $channel = (int) ($row['channel'] ?? 0);
            $row['id'] = (int) $row['id'];
            $row['sex_value'] = $sex;
            $row['sex'] = $sexDesc[$sex] ?? '未知';
            $row['channel_value'] = $channel;
            $row['channel'] = MemberChannelEnum::getDesc($channel);
            $row['status'] = (int) ($row['status'] ?? 1);
            $row['is_disable'] = $row['status'] === 1 ? 0 : 1;
            $row['user_money'] = (float) ($row['user_money'] ?? 0);
            $row['balance'] = $row['user_money'];
            $row['avatar'] = $this->files->getFileUrl((string) ($row['avatar'] ?? ''));
            $row['total_recharge_amount'] = (float) ($row['total_recharge_amount'] ?? 0);
            $row['create_time'] = self::formatTime($row['create_time'] ?? 0);
            $row['update_time'] = self::formatTime($row['update_time'] ?? 0);
            $row['login_time'] = self::formatTime($row['login_time'] ?? 0);
            $row['tag_ids'] = array_map('intval', array_column($row['tags'] ?? [], 'id'));
        }
        unset($row);
        return $rows;
    }

    private function hydrateTags(array $rows): array
    {
        $context = $this->executionContext->tenantAdmin();
        $memberIds = array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
        if ($memberIds === []) {
            return $rows;
        }
        $relations = MemberTagRelation::where([])
            ->whereIn('member_id', $memberIds)->select()->toArray();
        $tagIds = array_values(array_unique(array_map('intval', array_column($relations, 'tag_id'))));
        $tags = $tagIds === [] ? [] : MemberTag::where([])
            ->whereIn('id', $tagIds)->column('*', 'id');
        $byMember = [];
        foreach ($relations as $relation) {
            $tag = $tags[(int) $relation['tag_id']] ?? null;
            if ($tag !== null) {
                $byMember[(int) $relation['member_id']][] = $tag;
            }
        }
        foreach ($rows as &$row) {
            $row['tags'] = $byMember[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    private static function formatTime($value): string
    {
        if (empty($value)) {
            return '';
        }
        if (!is_numeric($value)) {
            return (string) $value;
        }
        return date('Y-m-d H:i:s', (int) $value);
    }

    public function balanceLogs(array $params): PageResult
    {
        if (in_array((int) ($params['export'] ?? 0), [1, 2], true)) {
            throw BusinessException::invalid('MEMBER_BALANCE_LOG_EXPORT_UNSUPPORTED', '该列表不支持导出');
        }

        $pageType = (int) ($params['page_type'] ?? 1);
        if ($pageType === 0) {
            $pageNo = 1;
            $pageSize = self::BALANCE_LOG_MAX_ROWS;
        } else {
            $pagination = PaginationInput::from($params);
            $pageNo = $pagination->page;
            $pageSize = $pagination->pageSize;
        }

        $query = MemberBalanceLog::where([])->alias('al')
            ->join('member u', 'u.tenant_id = al.tenant_id AND u.id = al.member_id')
            ->field(
                'u.nickname,u.account,u.sn,u.avatar,u.mobile,'
                . 'al.action,al.change_amount,al.left_amount,'
                . 'al.change_type,al.source_sn,al.create_time',
            );

        if (($params['type'] ?? '') === 'um') {
            $query->whereIn('al.change_type', AccountLogEnum::getUserMoneyChangeTypes());
        }
        if (isset($params['change_type']) && $params['change_type'] !== '') {
            $query->where('al.change_type', (int) $params['change_type']);
        }
        if (!empty($params['user_info'])) {
            $query->where(
                'u.sn|u.nickname|u.mobile|u.account',
                'like',
                '%' . trim((string) $params['user_info']) . '%',
            );
        }
        if (!empty($params['start_time'])) {
            $query->where('al.create_time', '>=', strtotime((string) $params['start_time']));
        }
        if (!empty($params['end_time'])) {
            $query->where('al.create_time', '<=', strtotime((string) $params['end_time']));
        }

        $pageResult = $pageType === 0
            ? PageResult::fromPaginator($query->order('al.id', 'desc')->paginate([
                'list_rows' => $pageSize,
                'page' => $pageNo,
                'var_page' => 'page_no',
            ]), $pageNo)
            : $pagination->result($query->order('al.id', 'desc'));
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
        $rows = $pageResult->items;

        foreach ($rows as &$row) {
            $row['avatar'] = $this->files->getFileUrl((string) ($row['avatar'] ?? ''));
            $row['change_type_desc'] = AccountLogEnum::getChangeTypeDesc((int) $row['change_type']);
            $symbol = (int) $row['action'] === AccountLogEnum::INC ? '+' : '-';
            $row['change_amount'] = $symbol . number_format((float) $row['change_amount'], 2, '.', '');
            $row['create_time'] = self::formatTime($row['create_time'] ?? '');
        }
        unset($row);

        return new PageResult($rows, $pageResult->total, $pageResult->page, $pageResult->pageSize);
    }

    public function tags(): array
    {
        return $this->queries->tags($this->executionContext->tenantAdmin());
    }

    public function createTag(array $params): void
    {
        $this->tags->create(
            $this->executionContext->tenantAdmin(),
            (string) $params['name'],
            (string) ($params['remark'] ?? ''),
        );
    }

    public function updateTag(array $params): void
    {
        $this->tags->update(
            $this->executionContext->tenantAdmin(),
            (int) $params['id'],
            (string) $params['name'],
            isset($params['remark']) ? (string) $params['remark'] : null,
        );
    }

    public function deleteTag(int $id): void
    {
        $context = $this->executionContext->tenantAdmin();
        Db::transaction(fn() => $this->tags->delete($context, $id));
    }

    public function createMember(array $params): void
    {
        $context = $this->executionContext->tenantAdmin();
        Db::transaction(function () use ($context, $params): void {
            $this->profiles->createAdminMember($context, [
                'nickname' => $params['nickname'],
                'avatar'   => $this->files->setTenantFileUrl($context, (string) ($params['avatar'] ?? '')),
                'mobile'   => $params['mobile']   ?? '',
                'email'    => $params['email']    ?? '',
                'sex'      => (int) ($params['sex'] ?? 0),
                'birthday' => $params['birthday']  ?? null,
                'status'   => (int) ($params['status'] ?? 1),
            ], (array) ($params['tag_ids'] ?? []));
        });
    }

    public function updateMember(array $params): void
    {
        $context = $this->executionContext->tenantAdmin();
        Db::transaction(function () use ($context, $params): void {
            $data = [];
            foreach (['nickname', 'avatar', 'mobile', 'email', 'birthday'] as $f) {
                if (isset($params[$f])) {
                    $data[$f] = $f === 'avatar'
                        ? $this->files->setTenantFileUrl($context, (string) $params[$f])
                        : $params[$f];
                }
            }
            foreach (['sex', 'status'] as $f) {
                if (isset($params[$f])) {
                    $data[$f] = (int) $params[$f];
                }
            }
            $this->profiles->updateAdminMember(
                $context,
                (int) $params['id'],
                $data,
                array_key_exists('tag_ids', $params) ? (array) ($params['tag_ids'] ?? []) : null,
            );
        });
    }

    /** LikeAdmin 后台用户详情的单字段更新语义。 */
    public function updateMemberField(array $params): void
    {
        $context = $this->executionContext->tenantAdmin();
        $field = (string) $params['field'];
        $value = $field === 'avatar'
            ? $this->files->setTenantFileUrl($context, (string) $params['value'])
            : $params['value'];
        $this->profiles->updateAdminField($context, (int) $params['id'], $field, $value);
    }

    public function updateMemberStatus(int $id, int $status): void
    {
        $this->profiles->updateStatus($this->executionContext->tenantAdmin(), $id, $status);
    }

    /** 调整用户余额并写入分类账户流水。 */
    public function adjustMemberBalance(
        array $params,
        int $adminId,
        string $idempotencyKey,
    ): void {
        $context = $this->executionContext->tenantAdmin();
        Db::transaction(function () use ($context, $params, $adminId, $idempotencyKey): void {
            $action = (int) $params['action'];
            $memberId = (int) $params['user_id'];
            $amountCents = Money::toCents(abs((float) $params['num']));
            $remark = (string) ($params['remark'] ?? '');
            $lease = $this->idempotency->begin(IdempotencyCommand::tenant(
                $context,
                'member.balance.adjust',
                $idempotencyKey,
                self::balanceAdjustmentRequestHash($memberId, $action, $amountCents, $remark),
                new DateTimeImmutable('+24 hours'),
            ));
            if (!$lease->isExecutionOwner()) {
                if (!$lease->isReplayable()) {
                    throw BusinessException::conflict('MEMBER_BALANCE_ADJUSTMENT_IN_PROGRESS', '余额调账请求仍在处理中，请稍后查询流水');
                }
                return;
            }

            $changeType = $action === AccountLogEnum::INC
                ? AccountLogEnum::USER_MONEY_INC_ADMIN
                : AccountLogEnum::USER_MONEY_DEC_ADMIN;
            $this->balances->applyInTransaction(
                $context,
                new MemberBalanceMutation(
                    $memberId,
                    $changeType,
                    $action,
                    $amountCents,
                    '',
                    $remark,
                    [],
                    $adminId,
                ),
            );
            $this->idempotency->complete($lease, new IdempotencyReceipt(200, ['success' => true]));
        });
    }

    private static function balanceAdjustmentRequestHash(
        int $memberId,
        int $action,
        int $amountCents,
        string $remark,
    ): string {
        return hash('sha256', json_encode([
            'member_id' => $memberId,
            'action' => $action,
            'amount_cents' => $amountCents,
            'remark' => $remark,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

}
