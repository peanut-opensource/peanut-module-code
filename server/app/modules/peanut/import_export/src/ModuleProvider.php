<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport;

use app\common\persistence\TenantPersistenceConfiguration;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\infrastructure\export\OperationLogExportProvider;
use PeanutAdmin\Modules\ImportExport\Service\ConfigurationTransferApplicationService;
use PeanutAdmin\Modules\ImportExport\Service\ImportExportApplicationService;
use PeanutAdmin\Modules\ImportExport\Service\ImportExportTaskWorkerDefinition;
use PeanutAdmin\Modules\ImportExport\Service\TaskImportExportRuntime;
use PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ConfigurationTransferQueries;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportCommands;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportQueries;
use PeanutAdmin\Modules\ImportExport\Contract\ImportExportWorkerRuntime;
use PeanutAdmin\Modules\ImportExport\Infrastructure\Authorization\AdminAsyncAuthorization;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ConfigurationPackageCodec;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\CoreSettingsConfigurationAdapter;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\ExternalBindingConfigurationAdapter;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\TenantModuleConfigurationAdapter;
use PeanutAdmin\Modules\ImportExport\Infrastructure\configuration\TenantSettingsConfigurationAdapter;
use PeanutAdmin\Modules\ImportExport\Infrastructure\File\AppFileMediaGateway;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Modules\ImportExport\Engine\Contract\DataProviderRegistry;
use PeanutAdmin\Modules\ImportExport\Engine\Execution\CsvOperationRunner;
use PeanutAdmin\Modules\ImportExport\Engine\Execution\ImportExportTaskHandler;
use PeanutAdmin\Modules\ImportExport\Engine\Execution\ImportExportTaskSubmissionProvider;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Modules\Settings\Contract\DeploymentSettingsTransfer;
use think\App;

final class ModuleProvider implements ModuleProviderContract, TaskWorkerContributor
{
    public function moduleKey(): string
    {
        return 'peanut.import-export';
    }

    public function bindings(): array
    {
        return [
            \PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore::class => function (App $app): \PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                return new \PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore(
                    $persistence->mode,
                    $persistence->instanceTenantId,
                    $app->make(\app\common\execution\CurrentExecutionContext::class),
                    $app->make(AuditContractHost::class),
                );
            },
            ImportExportApplicationService::class => function (App $app): ImportExportApplicationService {
                $tasks = $app->make(TaskJobRuntime::class);
                return new ImportExportApplicationService(new ImportExportService(
                    $app->make(\PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore::class),
                    new DataProviderRegistry([new OperationLogExportProvider()]),
                    $tasks->publisher(new ImportExportTaskSubmissionProvider()),
                    $tasks->jobs(),
                    $app->make(AuditService::class),
                ));
            },
            ImportExportCommands::class => ImportExportApplicationService::class,
            ImportExportQueries::class => ImportExportApplicationService::class,
            ConfigurationTransferApplicationService::class => function (App $app): ConfigurationTransferApplicationService {
                return new ConfigurationTransferApplicationService(
                    [
                        $app->make(TenantSettingsConfigurationAdapter::class),
                        $app->make(TenantModuleConfigurationAdapter::class),
                        $app->make(ExternalBindingConfigurationAdapter::class),
                        new CoreSettingsConfigurationAdapter($app->make(DeploymentSettingsTransfer::class)),
                    ],
                    new ConfigurationPackageCodec(),
                    $app->make(AuditContractHost::class),
                );
            },
            ConfigurationTransferCommands::class => ConfigurationTransferApplicationService::class,
            ConfigurationTransferQueries::class => ConfigurationTransferApplicationService::class,
            ImportExportTaskWorkerDefinition::class => function (App $app): ImportExportTaskWorkerDefinition {
                return new ImportExportTaskWorkerDefinition(
                    new ImportExportTaskHandler(new CsvOperationRunner(
                        $app->make(\PeanutAdmin\Modules\ImportExport\Engine\Persistence\ImportExportStore::class),
                        new DataProviderRegistry([new OperationLogExportProvider()]),
                        $app->make(AppFileMediaGateway::class),
                        $app->make(AuditService::class),
                    )),
                    new AdminAsyncAuthorization($app->make(AdminAuthorizationService::class)),
                );
            },
            ImportExportWorkerRuntime::class => TaskImportExportRuntime::class,
        ];
    }

    public function taskWorkerDefinitions(): array
    {
        return [ImportExportTaskWorkerDefinition::class];
    }

}
