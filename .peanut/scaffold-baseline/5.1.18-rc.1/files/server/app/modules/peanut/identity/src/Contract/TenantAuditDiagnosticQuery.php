<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use DateTimeImmutable;

/** Narrow, redacted cross-Tenant audit projection for platform diagnostics. */
interface TenantAuditDiagnosticQuery
{
    /**
     * @return list<array{tenant_id:int,request_id:string,operation_id:?string,operation:string,outcome:string,reason_code:?string,route:string,occurred_at:string}>
     */
    public function recentOperationEvidence(DateTimeImmutable $since, int $limit): array;
}
