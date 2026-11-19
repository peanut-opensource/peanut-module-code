<?php

declare(strict_types=1);

namespace app\common\contract\module;

use app\platform\infrastructure\module\DeployedTenantModuleRegistry;

interface ModuleGovernanceProvider
{
    public function registry(): DeployedTenantModuleRegistry;

    public function moduleLifecycle(): ModuleLifecycleCommands;

    public function qualification(): ModuleQualificationQuery;

}
