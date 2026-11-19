<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryEntryDto;
use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryPageDto;
use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryTypeDto;
use PeanutAdmin\Kernel\Auth\TenantContext;

interface DictionaryQuery
{
    /** @param array<string,mixed> $filters */
    public function types(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPageDto;

    /** @param array<string,mixed> $filters */
    public function entries(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPageDto;

    public function type(TenantContext $context, int $id): ?DictionaryTypeDto;

    /** @return list<DictionaryTypeDto> */
    public function enabledTypes(TenantContext $context): array;

    public function entry(TenantContext $context, int $id): ?DictionaryEntryDto;

    /** @return list<DictionaryEntryDto> */
    public function enabledByType(TenantContext $context, string $type): array;
}
