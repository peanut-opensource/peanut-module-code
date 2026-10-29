<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Model\RefundLog;
use PeanutAdmin\Modules\Payment\Model\RefundRecord;
use app\common\http\PageResult;
use app\common\exception\BusinessException;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** 退款统计、记录和操作日志查询。 */
class RefundApplicationService
{
    private const PAGE_SIZE_MAX = 25000;

    public function __construct(
        private readonly FileReferences $files,
        private readonly AdminDirectoryQuery $directory,
    ) {}

    /** Peanut 按实际退款金额汇总；当前全额退款时与参考订单金额口径一致。 */
    public function stat(TenantContext $context): array
    {
        $aggregate = RefundRecord::where([])->fieldRaw(
            'COALESCE(SUM(refund_amount), 0) AS total'
            . ',COALESCE(SUM(CASE WHEN refund_status=' . RefundEnum::REFUND_ING . ' THEN refund_amount ELSE 0 END), 0) AS ing'
            . ',COALESCE(SUM(CASE WHEN refund_status=' . RefundEnum::REFUND_SUCCESS . ' THEN refund_amount ELSE 0 END), 0) AS success'
            . ',COALESCE(SUM(CASE WHEN refund_status=' . RefundEnum::REFUND_ERROR . ' THEN refund_amount ELSE 0 END), 0) AS error',
        )->findOrEmpty()->toArray();

        return [
            'total' => round((float) ($aggregate['total'] ?? 0), 2),
            'ing' => round((float) ($aggregate['ing'] ?? 0), 2),
            'success' => round((float) ($aggregate['success'] ?? 0), 2),
            'error' => round((float) ($aggregate['error'] ?? 0), 2),
        ];
    }

    /**
     * @return PageResult
     */
    public function lists(TenantContext $context, array $params): PageResult
    {
        if (in_array((int) ($params['export'] ?? 0), [1, 2], true)) {
            throw BusinessException::invalid('REFUND_EXPORT_UNSUPPORTED', '该列表不支持导出');
        }

        $extendQuery = self::buildBaseQuery($context, $params, false);
        $extendRows = $extendQuery->fieldRaw(
            'count(r.id) as total'
            . ',count(if(r.refund_status=' . RefundEnum::REFUND_ING . ',1,null)) as ing'
            . ',count(if(r.refund_status=' . RefundEnum::REFUND_SUCCESS . ',1,null)) as `success`'
            . ',count(if(r.refund_status=' . RefundEnum::REFUND_ERROR . ',1,null)) as error',
        )->select()->toArray();
        $extend = $extendRows[0] ?? [];

        $query = self::buildBaseQuery($context, $params, true);
        $pageType = (int) ($params['page_type'] ?? 1);
        if ($pageType === 0) {
            $pageNo = 1;
            $pageSize = self::PAGE_SIZE_MAX;
        } else {
            $pagination = PaginationInput::from($params);
            $pageNo = $pagination->page;
            $pageSize = $pagination->pageSize;
        }

        $pageResult = $pageType === 0
            ? PageResult::fromPaginator($query->field('r.*,u.nickname,u.avatar')->order('r.id', 'desc')->paginate([
                'list_rows' => $pageSize,
                'page' => $pageNo,
                'var_page' => 'page_no',
            ]), $pageNo)
            : $pagination->result($query->field('r.*,u.nickname,u.avatar')->order('r.id', 'desc'));
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof \think\Model
            ? $item->toArray()
            : (array) $item);
        $lists = $pageResult->items;

        foreach ($lists as &$item) {
            $item['id'] = (int) $item['id'];
            $item['user_id'] = (int) $item['user_id'];
            $item['order_id'] = (int) $item['order_id'];
            $item['refund_way'] = (int) $item['refund_way'];
            $item['refund_type'] = (int) $item['refund_type'];
            $item['refund_status'] = (int) $item['refund_status'];
            $item['refund_type_text'] = RefundEnum::getTypeDesc($item['refund_type']);
            $item['refund_status_text'] = RefundEnum::getStatusDesc($item['refund_status']);
            $item['refund_way_text'] = RefundEnum::getWayDesc($item['refund_way']);
            $item['avatar'] = $this->files->getFileUrl((string) ($item['avatar'] ?? ''));
            $item['create_time'] = self::formatTime($item['create_time'] ?? 0);
            unset($item['refund_msg']);
        }
        unset($item);

        return new PageResult(
            $lists,
            $pageResult->total,
            $pageResult->page,
            $pageResult->pageSize,
            ['extend' => [
                'total' => (int) ($extend['total'] ?? 0),
                'ing' => (int) ($extend['ing'] ?? 0),
                'success' => (int) ($extend['success'] ?? 0),
                'error' => (int) ($extend['error'] ?? 0),
            ]],
        );
    }

    /** 最新日志在前；支付渠道原始报文不对管理页面暴露。 */
    public function refundLog(TenantContext $context, int $recordId): array
    {
        $lists = RefundLog::where([])
            ->alias('rl')
            ->field('rl.*')
            ->order(['rl.id' => 'desc'])
            ->where('rl.record_id', $recordId)
            ->hidden(['refund_msg'])
            ->select()
            ->toArray();

        $handlerIds = array_values(array_filter(array_unique(array_map('intval', array_column($lists, 'handle_id'))), static fn(int $id): bool => $id > 0));
        $handlerNames = $this->directory->memberDisplayNames($context, $handlerIds);
        foreach ($lists as &$item) {
            $item['handler'] = $handlerNames[(int) ($item['handle_id'] ?? 0)] ?? '';
            $item['id'] = (int) $item['id'];
            $item['record_id'] = (int) $item['record_id'];
            $item['user_id'] = (int) $item['user_id'];
            $item['handle_id'] = (int) $item['handle_id'];
            $item['handler'] = $item['handle_id'] === 0
                ? '系统'
                : (string) ($item['handler'] ?? '');
            $item['refund_status'] = (int) $item['refund_status'];
            $item['refund_status_text'] = RefundEnum::getStatusDesc($item['refund_status']);
            $item['create_time'] = self::formatTime($item['create_time'] ?? 0);
        }
        unset($item);
        return $lists;
    }

    /** 只读会员关联；统计与分页共用tenant和会员筛选，不给调用方Member写入能力。 */
    private static function buildBaseQuery(TenantContext $context, array $params, bool $withStatus): \think\db\Query
    {
        $query = RefundRecord::alias('r')->where([])
            ->join('member u', 'u.tenant_id = r.tenant_id AND u.id = r.user_id');

        if (!empty($params['sn'])) {
            $query->where('r.sn', trim((string) $params['sn']));
        }
        if (!empty($params['order_sn'])) {
            $query->where('r.order_sn', trim((string) $params['order_sn']));
        }
        if (isset($params['refund_type']) && $params['refund_type'] !== '') {
            $query->where('r.refund_type', (int) $params['refund_type']);
        }
        if (!empty($params['user_info'])) {
            $userInfo = trim((string) $params['user_info']);
            $query->where(
                'u.sn|u.nickname|u.mobile|u.account',
                'like',
                '%' . $userInfo . '%',
            );
        }
        if (!empty($params['start_time'])) {
            $query->where('r.create_time', '>=', strtotime((string) $params['start_time']));
        }
        if (!empty($params['end_time'])) {
            $query->where('r.create_time', '<=', strtotime((string) $params['end_time']));
        }
        if ($withStatus && isset($params['refund_status']) && $params['refund_status'] !== '') {
            $query->where('r.refund_status', (int) $params['refund_status']);
        }

        return $query;
    }

    private static function formatTime(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }
        return is_numeric($value) ? date('Y-m-d H:i:s', (int) $value) : (string) $value;
    }
}
