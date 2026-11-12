<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface TenantSettingsQuery
{
    /** @param array<string, mixed> $defaults */
    public function get(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $defaults = [],
    ): TenantSettingSnapshot;
}
