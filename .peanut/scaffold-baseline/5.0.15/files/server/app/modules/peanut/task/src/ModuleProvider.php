<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task;

use PeanutAdmin\Modules\Task\Service\CrontabSchedulerService;
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
use app\common\persistence\TenantPersistenceConfiguration;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;
use PeanutAdmin\Modules\Task\Job\Persistence\TaskJobStore;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.task';
    }

    public function scheduler(
        TaskJobRuntime $tasks,
        CrontabSchedulerService $crontabs,
        ExecutionContextStore $execution,
    ): TaskScheduler {
        return new TaskSchedulerService($tasks, $crontabs, $execution);
    }

    public function jobs(
        TenantPersistenceConfiguration $persistence,
        string $signingKey,
        ExecutionContextStore $executionContexts,
        CurrentExecutionContext $currentExecution,
        AdminDirectoryQuery $adminDirectory,
        ModuleExecutionBoundary $modules,
        CrontabCommandService $commands,
        Closure $dispatch,
        array $workerDefinitions,
        int $workerLimit,
    ): TaskJobRuntime {
        return new ThinkPhpTaskJobRuntime(
            new TaskJobStore($persistence->mode, $persistence->instanceTenantId),
            $signingKey,
            $executionContexts,
            $currentExecution,
            $adminDirectory,
            $modules,
            $commands,
            $dispatch,
            $workerDefinitions,
            $workerLimit,
        );
    }

    public function bindings(): array
    {
        return [
            TaskJobRuntime::class => fn(App $app): TaskJobRuntime => $this->jobs(
                $app->make(TenantPersistenceConfiguration::class),
                (string) $app->config->get('async.signing_key', ''),
                $app->make(ExecutionContextStore::class),
                $app->make(CurrentExecutionContext::class),
                $app->make(AdminDirectoryQuery::class),
                $app->make(ModuleExecutionBoundary::class),
                $app->make(CrontabCommandService::class),
                fn(string $command, array $parameters = [], string $driver = 'buffer') => $app->make('console')->call($command, $parameters, $driver),
                $app->make(TaskWorkerDefinitionRegistry::class)->resolve($app),
                (int) $app->config->get('async.worker_limit', 25),
            ),
            TaskBootstrapCommands::class => TaskBootstrapService::class,
            TaskDiagnosticQuery::class => TaskDiagnosticService::class,
            TaskScheduler::class => fn(App $app): TaskScheduler => $this->scheduler(
                $app->make(TaskJobRuntime::class),
                $app->make(CrontabSchedulerService::class),
                $app->make(ExecutionContextStore::class),
            ),
        ];
    }
}
