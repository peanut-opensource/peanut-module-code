<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Service;

use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class ArticleQueryService implements ArticleQueries
{
    public function visible(TenantContext $context, int $articleId): bool
    {
        return !Article::where([])
            ->where(['id' => $articleId, 'is_show' => 1])
            ->findOrEmpty()
            ->isEmpty();
    }

    /** @inheritDoc */
    public function visibleIds(TenantContext $context, array $articleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return array_map(
            'intval',
            Article::where([])->whereIn('id', $ids)->where('is_show', 1)->column('id'),
        );
    }

    public function options(TenantContext $context, int $limit): array
    {
        return Article::where([])
            ->field(['id', 'title', 'image', 'abstract'])
            ->where('is_show', 1)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }
}
