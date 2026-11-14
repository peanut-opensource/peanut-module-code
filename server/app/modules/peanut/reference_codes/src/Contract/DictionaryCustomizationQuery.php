<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ReferenceCodes\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Internal tenant-scoped policy and per-value lookup. */
interface DictionaryCustomizationQuery
{
    /** Return system, extend, tenant or disabled. */
    public function mode(TenantContext $context, string $type): string;

    /** @return array<string, array{action:string,name:?string}> */
    public function overrides(TenantContext $context, string $type): array;
}
