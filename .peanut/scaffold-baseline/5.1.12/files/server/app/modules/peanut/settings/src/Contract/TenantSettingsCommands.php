<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Contract;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface TenantSettingsCommands
{
    /** @param array<string, mixed> $document */
    public function replace(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $document,
    ): TenantSettingSnapshot;
}
