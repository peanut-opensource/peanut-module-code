<?php

// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'identity:credential-recover' => \app\command\IdentityCredentialRecovery::class,
        'identity:revocations' => \PeanutAdmin\Modules\Identity\Console\RevocationFactsCommand::class,
        // 平台系统维护仅允许顶层CLI执行，不登记为租户调度命令。
        'integration:purge-expired' => \PeanutAdmin\Modules\Integration\Console\PurgeExpiredDeliveriesCommand::class,
        'integration:webhook:dispatch' => \PeanutAdmin\Modules\Integration\Console\DispatchWebhooksCommand::class,
        'crontab'         => \app\command\Crontab::class,
        'crontab:demo'    => \app\command\CrontabDemo::class,
        'generator:cleanup' => \app\command\GeneratorCleanup::class,
        'tenant-task:work' => \app\command\TenantTaskWorker::class,
        'ops-backup:task' => \app\command\OpsBackupTask::class,
        'ops-restore:task' => \app\command\OpsRestoreTask::class,
        'ops-upgrade:task' => \app\command\OpsUpgradeTask::class,
        'ops-module:request' => \app\command\OpsModuleRequest::class,
        'ops-module:task' => \app\command\OpsModuleTask::class,
        'developer:center' => \app\command\DeveloperCenterCatalog::class,
        'tenant-module:apply-profile' => \app\command\TenantModuleProfile::class,
        'tenant-module:enable-locked-private' => \app\command\TenantModuleEnableLockedPrivate::class,
    ],
    // Every command that may run from the Tenant scheduler must declare its owning Module.
    // Core commands use the built-in `core` capability; application Modules register their key.
    'module_commands' => [
        'crontab:demo' => 'peanut.task',
    ],
];
