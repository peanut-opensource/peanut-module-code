<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops;

use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Modules\File\Contract\StorageQualificationQueries;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualificationQueries;
use think\App;
use think\facade\Config;

/** Registers the platform operations data owner and its module-owned provider projection. */
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
            Service\ProviderQualificationProjection::class => static fn (App $app) => new Service\ProviderQualificationProjection(
                $app->make(Domain\Application\PlatformPermissionChecker::class),
                static function (string $moduleKey) use ($app): bool {
                    try {
                        $app->make(ModuleAvailability::class)->assertDeployment($moduleKey);
                        return true;
                    } catch (ModuleException $error) {
                        if (!in_array($error->errorCode, ['MODULE_NOT_INSTALLED', 'MODULE_INSTALLATION_FAILED'], true)) {
                            throw $error;
                        }
                        return false;
                    }
                },
                static fn (): string => trim((string) Config::get('platform_auth.identifier_hmac_key', '')),
                static fn (): int => $app->make(StorageQualificationQueries::class)->countSubjects(),
                static fn (string $key, int $offset, int $limit): array =>
                    $app->make(StorageQualificationQueries::class)->subjects($key, $offset, $limit),
                static fn (): int => $app->make(AdminDirectoryQuery::class)->countActiveTenantsForQualification(),
                static fn (int $offset, int $limit): array =>
                    $app->make(AdminDirectoryQuery::class)->activeTenantIdsForQualification($offset, $limit),
                static fn (array $tenants, array $providers, string $key): array =>
                    $app->make(ExternalBindingQualificationQueries::class)->forTenants($tenants, $providers, $key),
            ),
            Contract\DeploymentModuleRequests::class => Service\DeploymentModuleRequestService::class,
            Contract\BackupTaskExecution::class => Infrastructure\ThinkPhpBackupTaskExecutionService::class,
            Contract\RestoreTaskExecution::class => Infrastructure\ThinkPhpRestoreTaskExecutionService::class,
            Contract\ModuleTaskExecution::class => Infrastructure\ThinkPhpModuleOperationTaskExecutionService::class,
            Contract\UpgradeTaskExecution::class => Infrastructure\ThinkPhpUpgradeTaskExecutionService::class,
        ];
    }
}
