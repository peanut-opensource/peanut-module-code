<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Infrastructure;

use app\common\contract\audit\AuditActor;
use app\common\contract\audit\AuditEvent;
use app\common\contract\audit\AuditTrace;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit;
use PeanutAdmin\Kernel\Audit\AuditOutcome;

final class ThinkPhpExternalTenantAudit implements ExternalTenantAudit
{
    public function __construct(private readonly AuditContractHost $audit) {}

    public function record(string $outcome, array $attributes): void
    {
        $operationId = trim((string) ($attributes['operation_id'] ?? 'external-tenant-resolution'));
        $tenantId = isset($attributes['tenant_id']) ? (int) $attributes['tenant_id'] : null;
        $accepted = $outcome === 'accepted';
        $event = $tenantId !== null && $tenantId > 0
            ? new AuditEvent(
                AuditEvent::TENANT,
                $tenantId,
                AuditActor::tenantSystem($tenantId),
                'external.tenant.resolution',
                'external.tenant.resolve',
                null,
                null,
                new AuditTrace($operationId, $operationId),
                $accepted ? AuditOutcome::Success : AuditOutcome::Denied,
                $accepted ? null : 'EXTERNAL_TENANT_RESOLUTION_REJECTED',
                $attributes,
            )
            : new AuditEvent(
                AuditEvent::PLATFORM,
                null,
                AuditActor::platformSystem(),
                'external.tenant.resolution',
                'external.tenant.resolve',
                null,
                null,
                new AuditTrace($operationId, $operationId),
                $accepted ? AuditOutcome::Success : AuditOutcome::Denied,
                $accepted ? null : 'EXTERNAL_TENANT_RESOLUTION_REJECTED',
                $attributes,
            );
        $this->audit->record($event);
    }
}
