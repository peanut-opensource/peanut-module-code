<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use DateTimeImmutable;

/** Bounded, redacted task-ledger evidence for platform diagnostics. */
interface TaskDiagnosticQuery
{
    /** @return list<array{task_type:string,status:string,error_code:string,occurrences:int,last_seen_at:string}> */
    public function failedGroupsSince(DateTimeImmutable $since, int $limit): array;
}
