<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Article\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Draft writes for an authorized tenant worker acting on behalf of a current member. */
interface ArticleDraftImport
{
    /** @param array{title: string, cid: string, body: string} $row @return list<string> */
    public function invalidDraftFields(TenantContext $context, array $row): array;

    public function importDraft(TenantContext $context, string $title, int $categoryId, string $body, string $rowKey): void;
}
