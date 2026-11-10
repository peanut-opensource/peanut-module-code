<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\DeviceCredentialBinding;

interface DeviceCredentialBindingQueries
{
    /** Read the active association under a current credential lock in the caller transaction. */
    public function lockActiveInstallationBinding(
        TenantContext $actor,
        string $credentialKey,
        string $installationKey,
        string $clientKey,
        string $deviceFingerprintHash,
    ): DeviceCredentialBinding;
}
