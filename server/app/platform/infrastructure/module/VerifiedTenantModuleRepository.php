<?php

declare(strict_types=1);

namespace app\platform\infrastructure\module;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\TenantModuleMutationRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;

/** Keeps the compiled deployment manifest and installation ledger bound inside Core mutations. */
final readonly class VerifiedTenantModuleRepository implements TenantModuleMutationRepository
{
    public function __construct(
        private TenantModuleMutationRepository $mutations,
        private DeployedTenantModuleRegistry $registry,
    ) {}

    public function tenantIsActive(int $tenantId): bool
    {
        return $this->mutations->tenantIsActive($tenantId);
    }

    public function installation(string $moduleKey): ?ModuleInstallationRecord
    {
        $this->registry->requireInstalled($moduleKey);
        return $this->mutations->installation($moduleKey);
    }

    public function tenantModule(int $tenantId, string $moduleKey): ?TenantModuleRecord
    {
        return $this->mutations->tenantModule($tenantId, $moduleKey);
    }

    public function enabledDependents(int $tenantId, string $moduleKey): array
    {
        return $this->mutations->enabledDependents($tenantId, $moduleKey);
    }

    public function enable(
        int $tenantId,
        string $moduleKey,
        array $config,
        DateTimeImmutable $now,
        string $source = 'manual',
        ?DateTimeImmutable $effectiveAt = null,
        ?DateTimeImmutable $expiresAt = null,
    ): TenantModuleRecord {
        return $this->mutations->enable(
            $tenantId,
            $moduleKey,
            $config,
            $now,
            $source,
            $effectiveAt,
            $expiresAt,
        );
    }

    public function disable(int $tenantId, string $moduleKey, DateTimeImmutable $now): TenantModuleRecord
    {
        return $this->mutations->disable($tenantId, $moduleKey, $now);
    }
}
