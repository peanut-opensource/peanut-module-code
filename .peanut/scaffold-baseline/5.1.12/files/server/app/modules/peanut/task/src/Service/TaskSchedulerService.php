<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskScheduler;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use app\common\execution\ExecutionContextStore;

final readonly class TaskSchedulerService implements TaskScheduler
{
    public function __construct(
        private TaskJobRuntime $tasks,
        private CrontabSchedulerService $crontabs,
        private ExecutionContextStore $execution,
    ) {}

    public function runDue(int $now): void
    {
        $this->requireEmptyEntry();
        $tenantIds = $this->crontabs->runDue(
            $now,
            fn(TenantScope $scope, array $item) => $this->tasks->enqueueCrontab(
                $scope,
                (int) $item['id'],
                $scope->contextIdentity(),
            ),
        );
        foreach ($tenantIds as $tenantId) {
            $this->tasks->runTenant($tenantId, $this->workerId());
        }
    }

    public function start(TenantScope $scope, array $item): void
    {
        $this->requireEmptyEntry();
        $this->tasks->enqueueCrontab($scope, (int) ($item['id'] ?? 0), $scope->contextIdentity());
        $this->tasks->runTenant($scope->tenantId(), $this->workerId());
    }

    private function workerId(): string
    {
        return 'crontab-' . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    private function requireEmptyEntry(): void
    {
        if (!$this->execution->isEmpty()) {
            throw new \DomainException('TASK_EXECUTION_ROOT_REQUIRED');
        }
    }
}
