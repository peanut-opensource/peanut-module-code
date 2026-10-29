<?php

declare(strict_types=1);

namespace app\adminapi\services\dept;

use app\common\http\PageResult;
use app\common\exception\BusinessException;
use think\facade\Db;
use app\common\model\dept\Jobs;
use app\common\services\XlsxExportService;
use PeanutAdmin\Kernel\Context\TenantContextRequirement;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberJob;

class JobsApplicationService
{
    private const EXPORT_MAX_ROWS = 25000;
    private const EXPORT_DEFAULT_NAME = '岗位列表';

    public function __construct(
        private readonly XlsxExportService $xlsxExport,
    ) {}

    /** 将 Peanut 旧版 is_disable 请求转换为 LikeAdmin status 契约。 */
    public function normalizeInput(array $params): array
    {
        $params = TenantContextRequirement::withoutTenantId($params);
        if (!array_key_exists('status', $params) && array_key_exists('is_disable', $params)) {
            $params['status'] = (int) $params['is_disable'] === 0 ? 1 : 0;
        }
        return $params;
    }

    public function validationRules(string $scene): array
    {
        $rules = [
            'id' => 'require|integer|gt:0', 'name' => 'require|length:1,50',
            'code' => 'require|max:64', 'sort' => 'integer|egt:0',
            'remark' => 'max:200', 'status' => 'require|in:0,1',
        ];
        if ($scene === 'add') {
            unset($rules['id']);
        }
        return $rules;
    }

    /**
     * 岗位分页列表；export=1 返回导出信息，export=2 生成 XLSX 并返回 URL。
     *
     * @return PageResult|array
     */
    public function lists(TenantContext $context, array $params): PageResult|array
    {
        $params = self::normalizeInput($params);
        $count = self::buildListQuery($context, $params)->count();
        $pageSize = (int) ($params['page_size'] ?? $params['limit'] ?? 15);
        $pageSize = max(1, min(100, $pageSize));

        if ((int) ($params['export'] ?? 0) === 1) {
            return self::exportInfo($count, $pageSize);
        }
        if ((int) ($params['export'] ?? 0) === 2) {
            return $this->export($context, $params, $count, $pageSize);
        }

        $pagination = PaginationInput::from($params);
        $pageResult = $pagination->result(self::buildListQuery($context, $params));
        $pageResult = $pageResult->map(static fn(mixed $item): array => $item instanceof Jobs
            ? $item->toArray()
            : (array) $item);
        $rows = $pageResult->items;

        return new PageResult(
            self::formatRows($rows),
            $pageResult->total,
            $pageResult->page,
            $pageResult->pageSize,
        );
    }

    /** 全部正常岗位（供选择器使用）。 */
    public function all(TenantContext $context): array
    {
        return self::jobs($context)->where('status', 1)
            ->field('id,name,code,status,is_disable')
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
    }

    public function detail(TenantContext $context, int $id): array
    {
        $jobs = self::jobs($context)->where('id', $id)->findOrEmpty();
        if ($jobs->isEmpty()) {
            return [];
        }
        return self::formatRows([$jobs->toArray()])[0];
    }

    public function add(TenantContext $context, array $params): bool
    {
        $params = self::normalizeInput($params);
        return Db::transaction(function () use ($context, $params): bool {
            self::assertUnique($context, (string) $params['name'], (string) $params['code']);
            $status = (int) $params['status'];
            Jobs::create([
                'name'       => trim((string) $params['name']),
                'code'       => trim((string) $params['code']),
                'sort'       => (int) ($params['sort'] ?? 0),
                'status'     => $status,
                'is_disable' => $status === 1 ? 0 : 1,
                'remark'     => (string) ($params['remark'] ?? ''),
            ]);
            return true;
        });
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $params = self::normalizeInput($params);
        return Db::transaction(function () use ($context, $params): bool {
            $id = (int) $params['id'];
            $jobs = self::jobs($context)->where('id', $id)->lock(true)->findOrEmpty();
            if ($jobs->isEmpty()) {
                throw BusinessException::notFound('ADMIN_JOB_NOT_FOUND', '岗位不存在');
            }
            self::assertUnique($context, (string) $params['name'], (string) $params['code'], $id);
            $status = (int) $params['status'];
            $jobs->save([
                'name'       => trim((string) $params['name']),
                'code'       => trim((string) $params['code']),
                'sort'       => (int) ($params['sort'] ?? 0),
                'status'     => $status,
                'is_disable' => $status === 1 ? 0 : 1,
                'remark'     => (string) ($params['remark'] ?? ''),
            ]);
            return true;
        });
    }

    public function delete(TenantContext $context, int $id): bool
    {
        return Db::transaction(function () use ($context, $id): bool {
            $jobs = self::jobs($context)->where('id', $id)->lock(true)->findOrEmpty();
            if ($jobs->isEmpty()) {
                throw BusinessException::notFound('ADMIN_JOB_NOT_FOUND', '岗位不存在');
            }
            if (MemberJob::where('tenant_id', $context->tenantId)->where('job_id', $id)->count() > 0) {
                throw BusinessException::conflict('ADMIN_JOB_IN_USE', '岗位已分配给成员，请先解除关联');
            }
            $jobs->delete();
            return true;
        });
    }

    public function updateStatus(TenantContext $context, int $id, int $status): bool
    {
        return Db::transaction(function () use ($context, $id, $status): bool {
            $jobs = self::jobs($context)->where('id', $id)->lock(true)->findOrEmpty();
            if ($jobs->isEmpty()) {
                throw BusinessException::notFound('ADMIN_JOB_NOT_FOUND', '岗位不存在');
            }
            $jobs->save([
                'status' => $status,
                'is_disable' => $status === 1 ? 0 : 1,
            ]);
            return true;
        });
    }

    private static function buildListQuery(TenantContext $context, array $params)
    {
        $query = self::jobs($context);
        if (!empty($params['code'])) {
            $query->where('code', trim((string) $params['code']));
        }
        if (!empty($params['name'])) {
            $query->whereLike('name', '%' . trim((string) $params['name']) . '%');
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int) $params['status']);
        }
        return $query->order(['sort' => 'desc', 'id' => 'desc']);
    }

    private static function assertUnique(TenantContext $context, string $name, string $code, int $exceptId = 0): void
    {
        $nameQuery = self::jobs($context)->where('name', trim($name));
        $codeQuery = self::jobs($context)->where('code', trim($code));
        if ($exceptId > 0) {
            $nameQuery->where('id', '<>', $exceptId);
            $codeQuery->where('id', '<>', $exceptId);
        }
        if ($nameQuery->count() > 0) {
            throw BusinessException::conflict('ADMIN_JOB_NAME_EXISTS', '岗位名称已存在');
        }
        if ($codeQuery->count() > 0) {
            throw BusinessException::conflict('ADMIN_JOB_CODE_EXISTS', '岗位编码已存在');
        }
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
            throw new \RuntimeException('没有数据，无法导出');
        }

        $pageType = (int) ($params['page_type'] ?? 0);
        if ($pageType === 1) {
            $pageStart = max(1, (int) ($params['page_start'] ?? 1));
            $pageEnd = max($pageStart, (int) ($params['page_end'] ?? $pageStart));
            $offset = ($pageStart - 1) * $pageSize;
            $limit = ($pageEnd - $pageStart + 1) * $pageSize;
            if ($limit > self::EXPORT_MAX_ROWS) {
                throw new \RuntimeException('已超出系统导出限制，当前最多导出25000条记录');
            }
            if ($offset >= $count) {
                throw new \RuntimeException('所选分页范围没有数据，无法导出');
            }
        } else {
            $offset = 0;
            $limit = min($count, self::EXPORT_MAX_ROWS);
        }

        $rows = self::buildListQuery($context, $params)
            ->limit($offset, $limit)
            ->select()
            ->toArray();
        $rows = self::formatRows($rows);
        $file = $this->xlsxExport->create(
            (string) ($params['file_name'] ?? self::EXPORT_DEFAULT_NAME),
            ['岗位编码', '岗位名称', '备注', '状态', '添加时间'],
            array_map(static fn(array $row): array => [
                $row['code'],
                $row['name'],
                $row['remark'],
                $row['status_desc'],
                $row['create_time'],
            ], $rows),
        );

        return [
            'url' => $file['url'],
            'file_name' => $file['original_name'],
        ];
    }

    private static function formatRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['sort'] = (int) $row['sort'];
            $row['status'] = (int) $row['status'];
            $row['is_disable'] = $row['status'] === 1 ? 0 : 1;
            $row['status_desc'] = $row['status'] === 1 ? '正常' : '停用';
            $row['create_time'] = self::formatTime($row['create_time'] ?? 0);
            $row['update_time'] = self::formatTime($row['update_time'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private static function jobs(TenantContext $context)
    {
        return Jobs::where([]);
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
}
