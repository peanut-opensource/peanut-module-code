<?php

declare(strict_types=1);

namespace app\common\contract\module;

interface ModuleQualificationQuery
{
    public function installedModule(string $moduleKey): ModuleQualification;

    /** @return list<ModuleQualification> */
    public function installedModules(): array;

    /** @return list<TenantModuleState> */
    public function tenantModuleStates(int $tenantId): array;

    /** @return list<string> */
    public function activeTenantModuleKeys(int $tenantId): array;
}
