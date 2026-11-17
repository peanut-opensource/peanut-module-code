<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Job\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class TaskJobAttemptRecord extends TenantModel
{
    /** @var string */ protected $name = 'task_job_attempt';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'job_id' => 'integer', 'attempt_number' => 'integer',
    ];
}
