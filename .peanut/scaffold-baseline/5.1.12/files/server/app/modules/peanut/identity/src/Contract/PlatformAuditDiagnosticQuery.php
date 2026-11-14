<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use DateTimeImmutable;

/** Bounded, metadata-only platform audit projection for diagnostics. */
interface PlatformAuditDiagnosticQuery
{
    /** @return list<array{event_type:string,outcome:string,occurrences:int,occurred_at:string}> */
    public function eventGroupsSince(DateTimeImmutable $since, int $limit): array;
}
