<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Member\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface MemberTagCommands
{
    public function create(TenantContext $context, string $name, string $remark): void;

    public function update(TenantContext $context, int $tagId, string $name, ?string $remark): void;

    /** Deletes the Tenant-scoped tag and its Tenant-scoped relations atomically when called in a transaction. */
    public function delete(TenantContext $context, int $tagId): void;
}
