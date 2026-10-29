<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use app\common\services\XlsxExportService;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Article\Contract\ArticleCategoryAdministration;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use think\db\BaseQuery;
use think\facade\Db;

/** 资讯分类后台用例；分类与资讯各自采用统一 CRUD 合同。 */
final class ArticleCategoryAdministrationService implements ArticleCategoryAdministration
{
    private const PAGE_SIZE_DEFAULT = 25;
    private const PAGE_SIZE_MAX = 25000;

    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationQuery $authorization,
        private readonly XlsxExportService $xlsxExport,
    ) {}

    public function lists(TenantContext $context, array $params): PageResult|array
    {
        $this->assertPermission($context, 'peanut.article.category.list');
        return $this->categoryLists($context, $params, false);
    }

    public function all(TenantContext $context): array
    {
        $this->assertPermission($context, 'peanut.article.category.all');
        $rows = ArticleCate::where([])->where('is_show', 1)
            ->field(self::fields())->order(['sort' => 'desc', 'id' => 'desc'])->select()->toArray();
        return array_map([self::class, 'formatRow'], $rows);
    }

    public function detail(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.category.detail');
        return $this->findRow($id, false);
    }

    public function add(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'peanut.article.category.add');
        $category = new ArticleCate();
        if (!$category->save(self::writeData($params))) {
            throw new \LogicException('ARTICLE_CATEGORY_CREATE_FAILED');
        }
        return true;
    }

    public function edit(TenantContext $context, array $params): bool
    {
        $this->assertPermission($context, 'peanut.article.category.edit');
        return Db::transaction(function () use ($params): bool {
            $category = ArticleCate::where([])->where('id', (int) $params['id'])->lock(true)->findOrEmpty();
            if ($category->isEmpty()) {
                throw BusinessException::notFound('ARTICLE_CATEGORY_NOT_FOUND', '资讯分类不存在');
            }
            $category->save(self::writeData($params));
            return true;
        });
    }

    public function delete(TenantContext $context, int $id): bool
    {
        $this->assertPermission($context, 'peanut.article.category.delete');
        return Db::transaction(function () use ($id): bool {
            $category = ArticleCate::withTrashed()->where('id', $id)->lock(true)->findOrEmpty();
            if ($category->isEmpty()) {
                throw BusinessException::notFound('ARTICLE_CATEGORY_NOT_FOUND', '资讯分类不存在');
            }
            if ($category->trashed()) {
                return true;
            }
            if (!Article::where([])->where('cid', $id)->lock(true)->findOrEmpty()->isEmpty()) {
                throw BusinessException::conflict(
                    'ARTICLE_CATEGORY_IN_USE',
                    '资讯分类已使用，请先删除绑定该资讯分类的资讯',
                );
            }
            if (!$category->delete()) {
                throw new \LogicException('ARTICLE_CATEGORY_SOFT_DELETE_FAILED');
            }
            return true;
        });
    }

    public function updateStatus(TenantContext $context, int $id, int $isShow): bool
    {
        $this->assertPermission($context, 'peanut.article.category.update-status');
        return Db::transaction(function () use ($id, $isShow): bool {
            $category = ArticleCate::where([])->where('id', $id)->lock(true)->findOrEmpty();
            if ($category->isEmpty()) {
                throw BusinessException::notFound('ARTICLE_CATEGORY_NOT_FOUND', '资讯分类不存在');
            }
            if ((int) $category->is_show !== $isShow) {
                $category->save(['is_show' => $isShow]);
            }
            return true;
        });
    }

    public function recycleLists(TenantContext $context, array $params): PageResult|array
    {
        $this->assertPermission($context, 'peanut.article.category.recycle.list');
        return $this->categoryLists($context, $params, true);
    }

    public function recycleDetail(TenantContext $context, int $id): array
    {
        $this->assertPermission($context, 'peanut.article.category.recycle.detail');
        return $this->findRow($id, true);
    }

    public function restore(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, 'peanut.article.category.restore');
        return $this->batch($ids, 'restored', static function (int $id): string {
            return Db::transaction(static function () use ($id): string {
                $category = ArticleCate::withTrashed()->where('id', $id)->lock(true)->findOrEmpty();
                if ($category->isEmpty()) {
                    throw BusinessException::notFound('ARTICLE_CATEGORY_NOT_FOUND', '资讯分类不存在');
                }
                if (!$category->trashed()) {
                    return 'already_active';
                }
                if (!$category->restore()) {
                    throw new \LogicException('ARTICLE_CATEGORY_RESTORE_FAILED');
                }
                return 'restored';
            });
        });
    }

    public function forceDelete(TenantContext $context, array $ids): array
    {
        $this->assertPermission($context, 'peanut.article.category.force-delete');
        return $this->batch($ids, 'deleted', static function (int $id): string {
            return Db::transaction(static function () use ($id): string {
                $category = ArticleCate::withTrashed()->where('id', $id)->lock(true)->findOrEmpty();
                if ($category->isEmpty()) {
                    throw BusinessException::notFound('ARTICLE_CATEGORY_NOT_FOUND', '资讯分类不存在');
                }
                if (!$category->trashed()) {
                    throw BusinessException::conflict(
                        'ARTICLE_CATEGORY_FORCE_DELETE_REQUIRES_TRASHED',
                        '仅回收站分类可永久删除',
                    );
                }
                if (!Article::withTrashed()->where('cid', $id)
                    ->lock(true)->findOrEmpty()->isEmpty()
                ) {
                    throw BusinessException::conflict(
                        'ARTICLE_CATEGORY_REFERENCE_EXISTS',
                        '分类仍有关联资讯，不能永久删除',
                    );
                }
                if (!$category->force(true)->delete()) {
                    throw new \LogicException('ARTICLE_CATEGORY_FORCE_DELETE_FAILED');
                }
                return 'deleted';
            });
        });
    }

    private function categoryLists(TenantContext $context, array $params, bool $onlyTrashed): PageResult|array
    {
        $query = $onlyTrashed ? ArticleCate::onlyTrashed() : ArticleCate::where([]);
        $query->field(self::fields());
        if (isset($params['name']) && $params['name'] !== '') {
            $query->whereLike('name', '%' . trim((string) $params['name']) . '%');
        }
        if (isset($params['is_show']) && $params['is_show'] !== '') {
            $query->where('is_show', (int) $params['is_show']);
        }
        foreach (['start_time' => '>=', 'end_time' => '<=', 'start' => '>=', 'end' => '<='] as $field => $operator) {
            if (!isset($params[$field]) || $params[$field] === '') {
                continue;
            }
            $value = in_array($field, ['start_time', 'end_time'], true)
                ? strtotime((string) $params[$field])
                : filter_var($params[$field], FILTER_VALIDATE_INT);
            if ($value === false) {
                throw BusinessException::invalid('ARTICLE_LIST_TIME_INVALID', '查询时间无效');
            }
            $query->where('create_time', $operator, $value);
        }
        $this->applyOrder($query, $params);
        $exportMode = $params['export'] ?? 0;
        if (!in_array($exportMode, [0, 1, 2, '1', '2'], true)) {
            throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', '导出模式无效');
        }
        $exportMode = (int) $exportMode;
        if ($exportMode > 0) {
            try {
                $pageSize = (int) ($params['page_type'] ?? 1) === 0
                    ? self::PAGE_SIZE_MAX
                    : PaginationInput::from($params, 1, self::PAGE_SIZE_DEFAULT)->pageSize;
            } catch (\InvalidArgumentException $exception) {
                throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', $exception->getMessage());
            }
            // Do not shallow-clone a Query with deferred tenant / onlyTrashed scope callbacks.
            $info = ExportPageInfo::from($query->count(), $pageSize, self::PAGE_SIZE_MAX, '资讯分类');
            if ($exportMode === 1) {
                return $info->toArray();
            }
            foreach (['page_type', 'page_start', 'page_end'] as $field) {
                if (isset($params[$field]) && !is_int($params[$field])
                    && !(is_string($params[$field]) && ctype_digit($params[$field]))) {
                    throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', '导出范围必须为整数');
                }
            }
            try {
                [$offset, $limit] = $info->rowRange(
                    (int) ($params['page_type'] ?? 0),
                    (int) ($params['page_start'] ?? 1),
                    isset($params['page_end']) ? (int) $params['page_end'] : null,
                );
            } catch (\InvalidArgumentException $exception) {
                throw BusinessException::invalid('ARTICLE_EXPORT_RANGE_INVALID', $exception->getMessage());
            }
            $page = new PageResult($query->limit($offset, $limit)->select()->toArray(), $info->count, 1, $limit);
        } else {
            $page = $this->paginate($query, $params);
        }
        $rows = array_map(static fn(mixed $item): array => self::formatRow(
            $item instanceof \think\Model ? $item->toArray() : (array) $item,
        ), $page->items);
        $counts = $this->articleCounts(array_column($rows, 'id'), $onlyTrashed);
        foreach ($rows as &$row) {
            $row['article_count'] = $counts[(int) $row['id']] ?? 0;
        }
        unset($row);
        if ($exportMode === 2) {
            $permission = $onlyTrashed ? 'peanut.article.category.recycle.list' : 'peanut.article.category.list';
            $this->assertPermission($context, $permission);
            $fields = [...self::fields(), 'article_count'];
            $file = $this->xlsxExport->create(
                (string) ($params['file_name'] ?? '资讯分类'),
                $fields,
                array_map(static fn(array $row): array => array_map(static fn(string $field): mixed => $row[$field], $fields), $rows),
            );
            $this->assertPermission($context, $permission);
            return ['url' => $file['url'], 'file_name' => $file['original_name']];
        }
        return new PageResult($rows, $page->total, $page->page, $page->pageSize);
    }

    private function findRow(int $id, bool $onlyTrashed): array
    {
        $query = $onlyTrashed ? ArticleCate::onlyTrashed() : ArticleCate::where([]);
        $category = $query->field(self::fields())->where('id', $id)->findOrEmpty();
        return $category->isEmpty() ? [] : self::formatRow($category->toArray());
    }

    /** @return list<string> */
    private static function fields(): array
    {
        return ['id', 'name', 'sort', 'is_show', 'create_time', 'update_time', 'delete_time'];
    }

    /** @return array<string,int|string> */
    private static function writeData(array $params): array
    {
        return [
            'name' => trim((string) $params['name']),
            'sort' => (int) ($params['sort'] ?? 0),
            'is_show' => (int) ($params['is_show'] ?? 1),
        ];
    }

    private function paginate(BaseQuery $query, array $params): PageResult
    {
        if ((int) ($params['page_type'] ?? 1) === 0) {
            return PageResult::fromPaginator($query->paginate([
                'list_rows' => self::PAGE_SIZE_MAX,
                'page' => 1,
                'var_page' => 'page_no',
            ]), 1);
        }
        return PaginationInput::from($params, 1, self::PAGE_SIZE_DEFAULT)->result($query);
    }

    private function applyOrder(BaseQuery $query, array $params): void
    {
        $field = (string) ($params['field'] ?? '');
        $orderBy = strtolower((string) ($params['order_by'] ?? ''));
        if (in_array($field, ['create_time', 'id'], true)
            && in_array($orderBy, ['asc', 'desc'], true)) {
            $query->order($field, $orderBy);
            if ($field !== 'id') {
                $query->order('id', $orderBy);
            }
            return;
        }
        $query->order(['sort' => 'desc', 'id' => 'desc']);
    }

    /** @return array<int,int> */
    private function articleCounts(array $categoryIds, bool $includeTrashed): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds === []) {
            return [];
        }
        $query = $includeTrashed ? Article::withTrashed() : Article::where([]);
        $rows = $query->whereIn('cid', $categoryIds)->field('cid, COUNT(*) AS article_count')
            ->group('cid')->select()->toArray();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['cid']] = (int) $row['article_count'];
        }
        return $counts;
    }

    /** @param array<string,mixed> $row */
    private static function formatRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['sort'] = (int) $row['sort'];
        $row['is_show'] = (int) $row['is_show'];
        foreach (['create_time', 'update_time', 'delete_time'] as $field) {
            $value = $row[$field] ?? 0;
            $row[$field] = empty($value) ? '' : (is_numeric($value)
                ? date('Y-m-d H:i:s', (int) $value)
                : (string) $value);
        }
        return $row;
    }

    /** @param list<int> $ids @return array<string,mixed> */
    private function batch(array $ids, string $successKey, callable $operation): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 100) {
            throw BusinessException::invalid('ARTICLE_CATEGORY_BATCH_IDS_INVALID', '操作对象数量须在 1 到 100 之间');
        }
        $result = ['requested' => $ids, $successKey => [], 'already_active' => [], 'failed' => []];
        foreach ($ids as $id) {
            try {
                $status = $operation($id);
                $result[$status][] = $id;
            } catch (BusinessException $exception) {
                $result['failed'][] = [
                    'id' => $id,
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ];
            }
        }
        return $result;
    }

    private function assertPermission(TenantContext $context, string $permission): void
    {
        try {
            $current = $this->executionContext->tenantAdmin();
            $actor = AdminPrincipal::fromArray($this->executionContext->tenantAdminPrincipal());
        } catch (\Throwable) {
            throw BusinessException::forbidden('ARTICLE_CATEGORY_ADMIN_PERMISSION_DENIED', '无权管理资讯分类');
        }
        if ($current->tenantId !== $context->tenantId
            || $current->accountId !== $context->accountId
            || $current->memberId !== $context->memberId
            || $current->authorizationRevision !== $context->authorizationRevision
            || !$this->authorization->decide($context, $actor, $permission)->allowed
        ) {
            throw BusinessException::forbidden('ARTICLE_CATEGORY_ADMIN_PERMISSION_DENIED', '无权管理资讯分类');
        }
    }
}
