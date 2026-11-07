<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use PeanutAdmin\Modules\Task\Contract\SourceTaskBinding;

/** Optional native Task consumption of a committed fixed source; ordinary Document APIs remain independent. */
interface DocumentSourceGrants
{
    /** Source eligibility only, before a consumer persists preparation or reserves quota; creates no Task authority. */
    public function verifyTaskSourceEligibility(TenantContext $context, VersionSnapshot $snapshot, DocumentOwnerReference $owner): void;

    /** Uses the same prepared version object and Admin transaction as verifyPreparedVersion. */
    public function issueTaskSource(
        TenantContext $context,
        VersionSnapshot $snapshot,
        DocumentOwnerReference $owner,
        SourceTaskBinding $binding,
        int $expiresAt,
        string $requestId,
    ): array;

    /** Returns current authoritative source and attaches live checks to every subsequent native execution fence. */
    public function redeemTaskSource(JobExecution $execution, string $grantKey, SourceTaskBinding $binding): VersionSnapshot;

    /** The original issuer can revoke even after losing source visibility; another member needs owner edit authority. */
    public function revokeTaskSource(TenantContext $context, string $grantKey): void;
}
