<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use app\common\contract\audit\AuditResource;
use app\common\services\audit\AuditContractHost;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\infrastructure\crontab\CrontabTenantLock;
use app\common\tenancy\PlatformTenantDataGateway;
use PeanutAdmin\Modules\Task\Model\Crontab;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use app\common\enum\CrontabEnum;
use PeanutAdmin\Kernel\Scheduling\ScheduleWindow;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use Cron\CronExpression;
use think\facade\Db;

final class CrontabSchedulerService
{
    public function __construct(
        private readonly ExecutionContextStore $contexts,
        private readonly CurrentExecutionContext $current,
        private readonly CrontabTenantLock $locks,
        private readonly AuditContractHost $audit,
        private readonly PlatformTenantDataGateway $tenantData,
        private readonly AdminDirectoryQuery $directory,
    ) {}

    /**
     * @param callable(TenantScope,array<string,mixed>):void $trigger
     * @return list<int> active Tenant IDs whose Task workers must be serviced
     */
    public function runDue(int $now, callable $trigger): array
    {
        $tenantIds = [];
        // Freeze this discovery window and walk owned rows in bounded batches.
        // Inactive or orphan owners must not cause all schedules to be buffered.
        $upperId = (int) $this->tenantData
            ->query(Crontab::class, 'scheduler', 'crontab.discover-due')
            ->where('status', CrontabEnum::START)->max('id');
        $afterId = 0;
        while ($afterId < $upperId) {
            $schedules = $this->tenantData
                ->query(Crontab::class, 'scheduler', 'crontab.discover-due')
                ->where('status', CrontabEnum::START)
                ->where('id', '>', $afterId)->where('id', '<=', $upperId)
                ->order('id', 'asc')->limit(500)->select();
            if ($schedules->isEmpty()) {
                break;
            }
            $candidateTenantIds = array_values(array_filter(array_unique(array_map('intval', $schedules->column('tenant_id'))), static fn(int $id): bool => $id > 0));
            $activeTenants = array_fill_keys($this->directory->activeTenantIds($candidateTenantIds), true);
            foreach ($schedules as $schedule) {
                $item = $schedule->getData();
                $afterId = self::positiveInt($item['id'] ?? null, 'Scheduled job ID is invalid');
                $tenantId = (int) ($item['tenant_id'] ?? 0);
                if (!isset($activeTenants[$tenantId])) {
                    continue;
                }
                $tenantIds[$tenantId] = true;
                $this->consider($item, $now, $trigger);
            }
        }
        return array_map('intval', array_keys($tenantIds));
    }

    /** @param callable(TenantScope,array<string,mixed>):void $trigger */
    public function consider(array $item, int $now, callable $trigger): void
    {
        $tenantId = self::positiveInt($item['tenant_id'] ?? null, 'Scheduled job Tenant owner is invalid');
        $jobId = self::positiveInt($item['id'] ?? null, 'Scheduled job ID is invalid');
        $lastTime = (int) ($item['last_time'] ?? 0);
        $scope = TenantScope::fromTrustedContext(
            $tenantId,
            CrontabTaskDefinition::contextIdentity($tenantId, $jobId, max(0, $lastTime)),
        );

        $system = new TenantSystemContext(
            $tenantId,
            'scheduler',
            'crontab.consider',
            $scope->contextIdentity(),
        );
        $this->contexts->run(
            new \app\common\execution\SystemExecutionContext($system),
            function () use ($scope, $jobId, $lastTime, $now, $item, $trigger): void {
                if (!$this->locks->acquire($scope, $jobId)) {
                    return;
                }
                try {
                    $window = new ScheduleWindow($lastTime, $now);
                    if ($window->isInitial()) {
                        Crontab::where('id', $jobId)
                            ->where('status', CrontabEnum::START)
                            ->where('last_time', 0)
                            ->update(['last_time' => $now]);
                        return;
                    }

                    try {
                        $nextTime = (new CronExpression((string) ($item['expression'] ?? '')))
                            ->getNextRunDate(date('Y-m-d H:i:s', $lastTime))
                            ->getTimestamp();
                    } catch (\InvalidArgumentException $exception) {
                        Crontab::where('id', $jobId)
                            ->where('status', CrontabEnum::START)
                            ->update([
                                'error' => '运行规则错误：' . $exception->getMessage(),
                                'status' => CrontabEnum::ERROR,
                            ]);
                        $this->audit(
                            $scope,
                            $jobId,
                            'task.crontab.rejected',
                            'crontab.schedule',
                            AuditOutcome::Error,
                            'CRONTAB_EXPRESSION_INVALID',
                        );
                        return;
                    }

                    if (!$window->isDue($nextTime)) {
                        return;
                    }

                    // The schedule and its durable job/event use the default connection.
                    // Keep the window unchanged if admission or enqueue fails.
                    Db::transaction(function () use ($jobId, $lastTime, $now, $scope, $item, $trigger): void {
                        if (Crontab::where('id', $jobId)
                                ->where('status', CrontabEnum::START)
                                ->where('last_time', $lastTime)
                                ->update(['last_time' => $now]) !== 1) {
                            return;
                        }

                        $item['last_time'] = $now;
                        $trigger($scope, $item);
                    });
                } finally {
                    $this->locks->release($scope, $jobId);
                }
            },
        );
    }

    private static function positiveInt(mixed $value, string $message): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException($message);
        }
        $value = (int) $value;
        if ($value < 1) {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }

    /** @param array<string,mixed> $metadata */
    private function audit(
        TenantScope $scope,
        int $jobId,
        string $eventType,
        string $operation,
        AuditOutcome $outcome,
        ?string $reasonCode,
        array $metadata = [],
    ): void {
        $current = $this->current->current();
        if ($current === null || $current->tenantId() !== $scope->tenantId()) {
            throw new \DomainException('CRONTAB_AUDIT_CONTEXT_REQUIRED');
        }
        $this->audit->recordTenantSystem(
            $scope->tenantId(),
            $eventType,
            $operation,
            $current->requestId(),
            ['job_id' => $jobId] + $metadata,
            $outcome,
            $reasonCode,
            new AuditResource('crontab', (string) $jobId),
        );
    }
}
