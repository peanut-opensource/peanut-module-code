<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Domain\Maintenance;

use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsAuditEvent;

interface MaintenanceWindowStore
{
    public function current(PlatformContext $context): ?MaintenanceWindow;

    /** Persistence, idempotency, revision comparison, single-active enforcement, and audit are one transaction. */
    public function schedule(
        PlatformContext $context,
        MaintenanceWindow $candidate,
        int $expectedRevision,
        string $idempotencyDigest,
        string $requestDigest,
        OpsAuditEvent $audit,
    ): MaintenanceWindow;

    /** Persistence, idempotency, revision comparison, and audit are one transaction. */
    public function close(
        PlatformContext $context,
        string $maintenanceKey,
        int $expectedRevision,
        string $idempotencyDigest,
        string $requestDigest,
        OpsAuditEvent $audit,
    ): MaintenanceWindow;
}
