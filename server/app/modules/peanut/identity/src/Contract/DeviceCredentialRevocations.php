<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\RevokeDeviceCredentialInput;

interface DeviceCredentialRevocations
{
    /** Owns the root transaction and recovers the permanent original after known write races. */
    public function revoke(TenantContext $actor, string $credentialKey, RevokeDeviceCredentialInput $input): array;

    /** Requires the caller's active transaction. The caller must roll back failures before fresh recovery. */
    public function revokeInTransaction(TenantContext $actor, string $credentialKey, RevokeDeviceCredentialInput $input): array;
}
