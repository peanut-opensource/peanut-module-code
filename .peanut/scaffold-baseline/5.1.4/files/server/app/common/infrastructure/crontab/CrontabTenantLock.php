<?php

declare(strict_types=1);

namespace app\common\infrastructure\crontab;

use PeanutAdmin\Kernel\Tenancy\ThinkPhpTenantLockStore;
use PeanutAdmin\Kernel\Tenancy\TenantScope;

final readonly class CrontabTenantLock
{
    public function __construct(private ThinkPhpTenantLockStore $locks) {}

    public static function name(TenantScope $scope, int $jobId): string
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Scheduled job ID is invalid');
        }
        return \PeanutAdmin\Kernel\Tenancy\TenantNamespace::lockName($scope, 'crontab:job:' . $jobId);
    }

    public function acquire(TenantScope $scope, int $jobId): bool
    {
        return $this->locks->acquire($scope, 'crontab:job:' . $jobId);
    }

    public function release(TenantScope $scope, int $jobId): void
    {
        try {
            $this->locks->release($scope, 'crontab:job:' . $jobId);
        } catch (\Throwable) {
        }
    }
}
