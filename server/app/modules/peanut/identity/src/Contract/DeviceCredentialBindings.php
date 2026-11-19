<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\Dto\DeviceCredentialBinding;

interface DeviceCredentialBindings
{
    /** Current access remains required when a consumer recovers historical registration. */
    public function assertRegistrationAccess(TenantContext $actor, string $credentialKey): void;

    /** Holds the credential lock and writes its association in the caller's actual transaction. */
    public function bindInstallation(
        TenantContext $actor,
        string $credentialKey,
        string $installationKey,
        string $clientKey,
        string $deviceFingerprintHash,
    ): DeviceCredentialBinding;
}
