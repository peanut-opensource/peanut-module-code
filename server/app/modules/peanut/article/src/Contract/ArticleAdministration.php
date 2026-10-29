<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\http\PageResult;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** 资讯后台公开用例；调用者必须提供当前受信租户，服务仍会复核人员权限。 */
interface ArticleAdministration
{
    /** Server fixes the supported owner type/slot; no client-provided ownership or implicit legacy conversion. */
    public function bodyDocument(TenantContext $context, int $id, string $requestId): array;
    public function bodyEditor(TenantContext $context, int $id): array;
    public function bodyTransport(TenantContext $context, int $id): array;
    public function bodySave(TenantContext $context, array $input): array;
    public function bodySaveOperation(TenantContext $context, int $id, string $requestId): array;
    /** Normal page; export=1 returns ExportPageInfo, export=2 returns the private ExportFile. */
    public function lists(TenantContext $context, array $params): PageResult|array;

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
