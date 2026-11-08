<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops;

use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;

/** Registers the platform operations data owner; runtime composition stays in the platform host. */
final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.ops';
    }

    /** @return array<class-string, class-string|callable> */
    public function bindings(): array
    {
        return [
            Contract\DeploymentModuleRequests::class => Service\DeploymentModuleRequestService::class,
            Contract\BackupTaskExecution::class => Infrastructure\ThinkPhpBackupTaskExecutionService::class,
            Contract\RestoreTaskExecution::class => Infrastructure\ThinkPhpRestoreTaskExecutionService::class,
            Contract\ModuleTaskExecution::class => Infrastructure\ThinkPhpModuleOperationTaskExecutionService::class,
            Contract\UpgradeTaskExecution::class => Infrastructure\ThinkPhpUpgradeTaskExecutionService::class,
        ];
    }
}
