<?php

declare(strict_types=1);

namespace app\adminapi\services\log;

use app\common\http\PageResult;
use app\adminapi\services\OperationLogService;
use app\common\services\XlsxExportService;
use app\common\model\log\OperationLog;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

class OperationLogApplicationService
{
    private const EXPORT_MAX_ROWS = 25000;
    private const EXPORT_DEFAULT_NAME = '操作日志';

    public function __construct(
        private readonly XlsxExportService $xlsxExport,
        private readonly OperationLogService $operationLogs,
    ) {}

    /** 分页列表，支持按 用户名/URI/方法 过滤 */
    public function lists(TenantContext $context, array $params): PageResult|array
    {
        $query = self::buildQuery($context, $params);
        $count = $query->count();
        $pageSize = min(self::EXPORT_MAX_ROWS, max(1, (int) ($params['page_size'] ?? $params['limit'] ?? 15)));
        if ((int) ($params['export'] ?? 0) === 1) {
            return self::exportInfo($count, $pageSize);
        }
        if ((int) ($params['export'] ?? 0) === 2) {
            return $this->export($context, $params, $count, $pageSize);
        }

        $pageResult = PaginationInput::from($params)->result(self::buildQuery($context, $params)->order('id', 'desc'));
        return $pageResult;
    }

    public function detail(TenantContext $context, int $id): array
    {
        $row = OperationLog::find($id);
        if (!$row instanceof OperationLog) {
            throw new \InvalidArgumentException('操作日志不存在');
        }

        return $row->toArray();
    }

    private static function buildQuery(TenantContext $context, array $params)
    {
        $query = OperationLog::where([]);
        if (!empty($params['username'])) {
            $query->where('username', 'like', '%' . trim((string) $params['username']) . '%');
        }
        if (!empty($params['uri'])) {
            $query->where('uri', 'like', '%' . trim((string) $params['uri']) . '%');
        }
        if (!empty($params['method'])) {
            $query->where('method', strtoupper((string) $params['method']));
        }
        if (!empty($params['ip'])) {
            $query->where('ip', 'like', '%' . trim((string) $params['ip']) . '%');
        }
        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $start = strtotime((string) $params['start_time']);
            $end = strtotime((string) $params['end_time']);
            if ($start !== false && $end !== false) {
                $query->whereBetween('create_time', [$start, $end]);
            }
        }
        return $query;
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

    private function export(TenantContext $context, array $params, int $count, int $pageSize): array
    {
        if ($count === 0) {
            throw new \RuntimeException('没有数据,无法导出');
        }
        $pageType = (int) ($params['page_type'] ?? 0);
        if ($pageType === 1) {
            $pageStart = max(1, (int) ($params['page_start'] ?? 1));
            $pageEnd = max($pageStart, (int) ($params['page_end'] ?? $pageStart));
            $offset = ($pageStart - 1) * $pageSize;
            $limit = ($pageEnd - $pageStart + 1) * $pageSize;
        } else {
            $offset = 0;
            $limit = min($count, self::EXPORT_MAX_ROWS);
        }
        if ($limit > self::EXPORT_MAX_ROWS || $offset >= $count) {
            throw new \RuntimeException('导出范围无数据或超过25000条限制');
        }
        $rows = self::buildQuery($context, $params)->order('id', 'desc')
            ->limit($offset, $limit)->select()->toArray();
        $sheetRows = array_map(static fn(array $row): array => [
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['ip'],
            (string) $row['uri'],
            (string) $row['method'],
            (string) $row['params'],
            empty($row['create_time']) ? '' : date('Y-m-d H:i:s', (int) $row['create_time']),
        ], $rows);
        $file = $this->xlsxExport->create(
            (string) ($params['file_name'] ?? self::EXPORT_DEFAULT_NAME),
            ['ID', '管理员', 'IP', '请求地址', '方法', '参数', '操作时间'],
            $sheetRows,
            'operation-logs',
        );
        return ['url' => $file['url'], 'file_name' => $file['original_name']];
    }

    /** 清空旧日志并原子保留本次清理审计；审计写入失败时删除整体回滚。 */
    public function clear(TenantContext $context, int $adminId, string $username, string $ip): int
    {
        return Db::transaction(function () use ($context, $adminId, $username, $ip): int {
            $count = (int) OperationLog::where([])->count();
            OperationLog::where([])->delete();
            $this->operationLogs->record(
                $context,
                $adminId,
                $username,
                $ip,
                'log/clear',
                'POST',
                ['cleared_count' => $count],
            );
            return $count;
        });
    }
}
