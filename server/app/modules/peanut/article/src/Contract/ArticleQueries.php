<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface ArticleQueries
{
    public function visible(TenantContext $context, int $articleId): bool;

    /** @return list<array{id:int,title:string,image:string,abstract:string}> */
    public function options(TenantContext $context, int $limit): array;
}
