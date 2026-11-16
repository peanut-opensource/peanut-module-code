<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

/** Internal persistence contract; consumers use ExternalTenantResolutionService. */
interface ExternalTenantBindingRepository
{
    /** @return list<ExternalTenantBinding> */
    public function byCallbackKey(string $provider, string $callbackKey): array;

    /** @return list<ExternalTenantBinding> */
    public function byClientIdentity(string $provider, string $identityHash): array;

    /** @return list<ExternalTenantBinding> */
    public function byProvider(string $provider): array;

    /** @return list<ExternalTenantBinding> */
    public function byTenant(string $provider, int $tenantId, bool $lock = false): array;

    /** @return list<ExternalTenantBinding> */
    public function byReference(int $tenantId, int $bindingId): array;
}
