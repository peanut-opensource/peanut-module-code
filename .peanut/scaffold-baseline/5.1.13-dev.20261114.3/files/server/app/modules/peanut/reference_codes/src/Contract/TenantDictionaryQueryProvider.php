<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryPage;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryType;

interface TenantDictionaryQueryProvider
{
    /** @param array<string,mixed> $filters */
    public function types(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPage;

    /** @param array<string,mixed> $filters */
    public function entries(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPage;

    public function type(TenantContext $context, int $id): ?DictionaryType;

    /** @return list<DictionaryType> */
    public function enabledTypes(TenantContext $context): array;

    public function entry(TenantContext $context, int $id): ?DictionaryEntry;

    /** @return list<DictionaryEntry> */
    public function enabledEntriesByType(TenantContext $context, string $type): array;
}
