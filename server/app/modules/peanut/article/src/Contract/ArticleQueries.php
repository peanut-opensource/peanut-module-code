<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface ArticleQueries
{
    public function visible(TenantContext $context, int $articleId): bool;

    /**
     * Batch-checks which article IDs are currently visible.
     *
     * @param list<int> $articleIds
     * @return list<int>
     */
    public function visibleIds(TenantContext $context, array $articleIds): array;

    /** @return list<array{id:int,title:string,image:string,abstract:string}> */
    public function options(TenantContext $context, int $limit): array;
}
