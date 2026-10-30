<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task;

use PeanutAdmin\Modules\Task\Service\TaskSchedulerService;
use PeanutAdmin\Modules\Task\Service\TaskBootstrapService;
use PeanutAdmin\Modules\Task\Infrastructure\Runtime\ThinkPhpTaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskScheduler;
use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PeanutAdmin\Modules\Task\Contract\TaskDiagnosticQuery;
use PeanutAdmin\Modules\Task\Service\TaskDiagnosticService;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use app\common\services\CrontabCommandService;
use Closure;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;
use PeanutAdmin\DataPermission\Catalog\ResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.task';
    }

    public function jobs(
        string $signingKey,
        ExecutionContextStore $executionContexts,
        CurrentExecutionContext $currentExecution,
        AdminDirectoryQuery $adminDirectory,
        ModuleExecutionBoundary $modules,
        CrontabCommandService $commands,
        Closure $dispatch,
        array $workerDefinitions,
        int $workerLimit,
        ResourceOperationCatalog $operations,
        TenantEntitlementService $entitlements,
    ): TaskJobRuntime {
        return new ThinkPhpTaskJobRuntime(
            new TaskJobStore(),
            $signingKey,
            $executionContexts,
            $currentExecution,
            $adminDirectory,
            $modules,
            $commands,
            $dispatch,
            $workerDefinitions,
            $workerLimit,
            $operations,
            $entitlements,
        );
    }

    public function bindings(): array
    {
        return [
            TaskJobRuntime::class => fn(App $app): TaskJobRuntime => $this->jobs(
                (string) $app->config->get('async.signing_key', ''),
                $app->make(ExecutionContextStore::class),
                $app->make(CurrentExecutionContext::class),
                $app->make(AdminDirectoryQuery::class),
                $app->make(ModuleExecutionBoundary::class),
                $app->make(CrontabCommandService::class),
                function (string $command, array $parameters = [], string $driver = 'buffer') use ($app): \think\console\Output {
                    array_unshift($parameters, $command);
                    $output = new \think\console\Output($driver);
                    $exitCode = $app->make('console')->find($command)->run(new \think\console\Input($parameters), $output);
                    if ($exitCode !== 0) {
                        throw new \RuntimeException('CRONTAB_COMMAND_FAILED');
                    }
                    return $output;
                },
                $app->make(TaskWorkerDefinitionRegistry::class)->resolve($app),
                (int) $app->config->get('async.worker_limit', 25),
                $app->make(ResourceOperationCatalog::class),
                $app->make(TenantEntitlementService::class),
            ),
            TaskBootstrapCommands::class => TaskBootstrapService::class,
            TaskDiagnosticQuery::class => TaskDiagnosticService::class,
            TaskScheduler::class => TaskSchedulerService::class,
        ];
    }
}
