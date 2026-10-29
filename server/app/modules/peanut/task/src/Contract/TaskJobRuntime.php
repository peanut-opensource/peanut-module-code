<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use PeanutAdmin\Kernel\Tenancy\TenantScope;

interface TaskJobRuntime
{
    public function publisher(TaskSubmissionProvider ...$providers): TrustedJobPublisher;

    public function jobs(): TaskJobService;

    public function enqueueCrontab(TenantScope $scope, int $scheduleId, string $contextIdentity): void;

    public function runTenant(int $tenantId, string $workerId): int;
}
