<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings\Service;

use PeanutAdmin\Modules\Settings\Value\TenantSettingsNamespace;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingSnapshot;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsProvider;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class TenantSettingService implements TenantSettingsQuery, TenantSettingsCommands
{
    public function __construct(private TenantSettingsProvider $provider) {}

    public function get(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $default = [],
    ): TenantSettingSnapshot {
        $tenantId = $context->tenantId;
        TenantSettingsNamespace::assertValid($namespace);
        $snapshot = $this->provider->find($tenantId, $namespace);
        if ($snapshot === null) {
            return new TenantSettingSnapshot($tenantId, $namespace, $default, 0, 0, 0);
        }
        return new TenantSettingSnapshot($snapshot->tenantId, $snapshot->namespace, array_replace_recursive($default, $snapshot->document), $snapshot->revision, $snapshot->createTime, $snapshot->updateTime);
    }

    public function replace(
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $namespace,
        array $document,
    ): TenantSettingSnapshot {
        $tenantId = $context->tenantId;
        TenantSettingsNamespace::assertValid($namespace);
        return $this->provider->replace($tenantId, $namespace, $document);
    }
}
