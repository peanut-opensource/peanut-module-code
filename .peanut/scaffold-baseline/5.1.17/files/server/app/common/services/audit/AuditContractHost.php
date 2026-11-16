<?php

declare(strict_types=1);

namespace app\common\services\audit;

use app\common\infrastructure\audit\OperationLogProjection;
use app\common\policy\audit\RedactionPolicy;
use app\common\contract\audit\AuditActor;
use app\common\contract\audit\AuditEvent;
use app\common\contract\audit\AuditResource;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

final class AuditContractHost
{
    private OperationLogProjection $operationLogs;
    private AuditService $identityAudit;

    public function __construct(?CurrentExecutionContext $execution, ?AuditService $identityAudit = null)
    {
        $this->operationLogs = new OperationLogProjection($execution);
        $this->identityAudit = $identityAudit ?? new AuditService();
    }

    public function record(AuditEvent $event): void
    {
        if ($event->projection === AuditEvent::OPERATION_LOG) {
            Db::transaction(function () use ($event): void {
                $this->operationLogs->append($event);
                $this->appendTenantEvent($event);
            });
            return;
        }
        if ($event->projection === AuditEvent::PLATFORM) {
            $this->appendPlatformEvent($event);
            return;
        }
        $this->appendTenantEvent($event);
    }

    public function recordOperationLog(
        TenantContext $context,
        int $adminId,
        string $username,
        string $ip,
        string $uri,
        string $method,
        mixed $params,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        int $httpStatus = 200,
    ): void {
        $this->record(AuditEvent::operationLog(
            $context,
            $adminId,
            $username,
            $ip,
            $uri,
            $method,
            $params,
            $outcome,
            $reasonCode,
            $httpStatus,
        ));
    }

    public function appendPlatform(
        string $eventType,
        string $action,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
    ): void {
        $this->recordPlatform($eventType, $action, $requestId, $operatorId, $accountId, $metadata);
    }

    public function appendTenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {
        $this->recordTenantSystem($tenantId, $eventType, $action, $requestId, $metadata);
    }

    public function recordPlatform(
        string $eventType,
        string $operation,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
    ): void {
        $this->record(AuditEvent::platform(
            $eventType,
            $operation,
            $requestId,
            $operatorId,
            $accountId,
            $metadata,
            $outcome,
            $reasonCode,
            $resource,
        ));
    }

    public function recordTenantSystem(
        int $tenantId,
        string $eventType,
        string $operation,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
    ): void {
        $this->record(AuditEvent::tenantSystem(
            $tenantId,
            $eventType,
            $operation,
            $requestId,
            $metadata,
            $outcome,
            $reasonCode,
            $resource,
        ));
    }

    public function appendTenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        int $targetCount = 0,
        ?string $targetSetDigest = null,
        array $metadata = [],
    ): void {
        $this->record(AuditEvent::tenantMember(
            $context,
            $eventType,
            $action,
            $targetResourceType === null || $targetResourceId === null
                ? null
                : new AuditResource($targetResourceType, $targetResourceId),
            $boundaryTargetType === null || $boundaryTargetId === null
                ? null
                : new AuditResource($boundaryTargetType, $boundaryTargetId),
            $targetCount,
            $targetSetDigest,
            $metadata,
        ));
    }

    public function appendTenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void {
        $this->record(AuditEvent::tenantPlatformOperator(
            $tenantId,
            $operatorId,
            $accountId,
            $eventType,
            $action,
            $requestId,
            $metadata,
        ));
    }

    private function appendPlatformEvent(AuditEvent $event): void
    {
        $actor = $event->actor;
        $this->identityAudit->appendPlatformEvent(
            $event->eventType,
            $event->operation,
            $event->outcome->value,
            $event->reasonCode,
            $actor->platformOperatorId,
            $actor->accountId,
            $event->resource?->type,
            $event->resource?->id,
            $event->trace->requestId,
            $event->trace->operationId,
            $event->trace->ipAddress,
            $event->trace->userAgentHash,
            RedactionPolicy::nullableJson($event->before),
            RedactionPolicy::nullableJson($event->after),
            RedactionPolicy::nullableJson($event->metadata),
        );
    }

    private function appendTenantEvent(AuditEvent $event): void
    {
        if ($event->tenantId === null) {
            throw new \InvalidArgumentException('AUDIT_TENANT_REQUIRED');
        }
        $actor = $event->actor;
        $this->identityAudit->appendTenantEvent(
            $event->tenantId,
            $event->eventType,
            $event->operation,
            $event->outcome->value,
            $event->reasonCode,
            $actor->type === AuditActor::TENANT_MEMBER || $actor->type === AuditActor::TENANT_SYSTEM
                ? $actor->tenantId
                : null,
            $actor->tenantMemberId,
            $actor->accountId,
            $actor->platformOperatorId,
            $actor->type,
            $event->resource?->type,
            $event->resource?->id,
            $event->boundaryTarget?->type,
            $event->boundaryTarget?->id,
            $event->targetCount,
            $event->targetSetDigest,
            RedactionPolicy::nullableJson($event->authorizationBasis),
            $event->trace->requestId,
            $event->trace->operationId,
            $event->trace->ipAddress,
            $event->trace->userAgentHash,
            RedactionPolicy::nullableJson($event->before),
            RedactionPolicy::nullableJson($event->after),
            RedactionPolicy::nullableJson($event->metadata),
        );
    }
}
