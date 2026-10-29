<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\http\PageResult;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** 资讯模块内部的分类后台用例；与资讯 CRUD 使用相同参数和返回合同。 */
interface ArticleCategoryAdministration
{
    /** Normal page; export=1 returns ExportPageInfo, export=2 returns the private ExportFile. */
    public function lists(TenantContext $context, array $params): PageResult|array;

    /** @return list<array<string,mixed>> */
    public function all(TenantContext $context): array;

    /** @return array<string,mixed> */
    public function detail(TenantContext $context, int $id): array;

    public function add(TenantContext $context, array $params): bool;

    public function edit(TenantContext $context, array $params): bool;

    public function delete(TenantContext $context, int $id): bool;

    public function updateStatus(TenantContext $context, int $id, int $isShow): bool;

    /** Same export protocol, independently authorized and restricted to deleted records. */
    public function recycleLists(TenantContext $context, array $params): PageResult|array;

    /** @return array<string,mixed> */
    public function recycleDetail(TenantContext $context, int $id): array;

    /** @param list<int> $ids @return array<string,mixed> */
    public function restore(TenantContext $context, array $ids): array;

    /** @param list<int> $ids @return array<string,mixed> */
    public function forceDelete(TenantContext $context, array $ids): array;
}
