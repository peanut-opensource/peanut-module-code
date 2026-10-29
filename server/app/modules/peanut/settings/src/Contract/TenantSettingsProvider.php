<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

interface TenantSettingsProvider
{
    public function find(int $tenantId, string $namespace): ?TenantSettingSnapshot;

    /** @param array<string, mixed> $document */
    public function replace(int $tenantId, string $namespace, array $document): TenantSettingSnapshot;
}
