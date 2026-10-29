<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryEntry;
use PeanutAdmin\Modules\ReferenceCodes\DictionaryType;

interface TenantDictionaryCommandProvider
{
    /** @param array<string,mixed> $values */
    public function createType(TenantContext $context, array $values): DictionaryType;

    /** @param array<string,mixed> $values */
    public function replaceType(TenantContext $context, int $id, array $values): DictionaryType;

    public function deleteType(TenantContext $context, int $id): void;

    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void;

    /** @param array<string,mixed> $values */
    public function createEntry(TenantContext $context, array $values): DictionaryEntry;

    /** @param array<string,mixed> $values */
    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntry;

    public function deleteEntry(TenantContext $context, int $id): void;

    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void;
}
