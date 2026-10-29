<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use app\common\http\PageResult;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;

/** Public member and storefront reads owned by the Article Module. */
interface PublicArticleQueries
{
    public function lists(array $params, int $memberId = 0): PageResult;

    /** @return list<array<string,mixed>> */
    public function categories(): array;

    /** @return array<string,mixed> */
    public function detail(int $id, int $memberId = 0): array;

    public function collectionLists(int $memberId, array $params): PageResult;

    public function countForMember(AuthenticatedMemberContext $context, int $memberId): int;

    public function add(int $articleId, int $memberId): void;

    public function cancel(int $articleId, int $memberId): void;

    /** @return list<array<string,mixed>> */
    public function infoCenter(): array;

    /** @return list<array<string,mixed>> */
    public function homeArticles(int $limit): array;

    /** @return array<string,mixed> */
    public function pcDetail(int $memberId, int $articleId, string $source = 'default'): array;

    /** @return list<array<string,mixed>> */
    public function limitArticles(string $sortType, int $limit = 0, int $cid = 0, int $excludeId = 0): array;
}
