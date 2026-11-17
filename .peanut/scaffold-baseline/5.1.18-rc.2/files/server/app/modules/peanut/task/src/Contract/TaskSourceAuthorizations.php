<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Native member jobs only. Neither a serialized binding nor a caller-created execution grants authority. */
interface TaskSourceAuthorizations
{
    /** The real queued job must already exist in the caller's submission transaction. */
    public function assertSourceSubmission(TenantContext $issuer, SourceTaskBinding $binding): void;

    /** Rechecks the original signed authorization and live lease under this runtime's exact System root. */
    public function sourceActor(JobExecution $execution, SourceTaskBinding $binding): TenantContext;
}
