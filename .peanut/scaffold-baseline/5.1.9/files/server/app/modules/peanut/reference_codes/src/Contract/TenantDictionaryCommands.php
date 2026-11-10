<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryEntryDto;
use PeanutAdmin\Modules\ReferenceCodes\Dto\DictionaryTypeDto;
use PeanutAdmin\Kernel\Auth\TenantContext;

interface TenantDictionaryCommands
{
    /** @param array<string,mixed> $values */
    public function createType(TenantContext $context, array $values): DictionaryTypeDto;

    /** @param array<string,mixed> $values */
    public function replaceType(TenantContext $context, int $id, array $values): DictionaryTypeDto;

    public function deleteType(TenantContext $context, int $id): void;

    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void;

    /** @param array<string,mixed> $values */
    public function createEntry(TenantContext $context, array $values): DictionaryEntryDto;

    /** @param array<string,mixed> $values */
    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntryDto;

    public function deleteEntry(TenantContext $context, int $id): void;

    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void;
}
