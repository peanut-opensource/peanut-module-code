<?php

declare(strict_types=1);

namespace app\common\contract\audit;

use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AuditEvent
{
    public const PLATFORM = 'platform';
    public const TENANT = 'tenant';
    public const OPERATION_LOG = 'operation_log';

    public function __construct(
        public string $projection,
        public ?int $tenantId,
        public AuditActor $actor,
        public string $eventType,
        public string $operation,
        public ?AuditResource $resource,
        public ?AuditResource $boundaryTarget,
        public AuditTrace $trace,
        public AuditOutcome $outcome,
        public ?string $reasonCode,
        public array $metadata = [],
        public ?array $before = null,
        public ?array $after = null,
        public int $targetCount = 0,
        public ?string $targetSetDigest = null,
        public ?array $authorizationBasis = null,
    ) {
        if (!in_array($projection, [self::PLATFORM, self::TENANT, self::OPERATION_LOG], true)) {
            throw new \InvalidArgumentException('AUDIT_PROJECTION_INVALID');
        }
        if (in_array($projection, [self::TENANT, self::OPERATION_LOG], true)
            && ($tenantId === null || $tenantId < 1)) {
            throw new \InvalidArgumentException('AUDIT_TENANT_REQUIRED');
        }
        if (trim($eventType) === '' || trim($operation) === '') {
            throw new \InvalidArgumentException('AUDIT_EVENT_OPERATION_REQUIRED');
        }
        if ($targetCount < 0) {
            throw new \InvalidArgumentException('AUDIT_TARGET_COUNT_INVALID');
        }
    }

    public static function operationLog(
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
    ): self {
        $normalizedUri = strtolower(trim($uri, '/'));
        $normalizedMethod = strtoupper($method);

        return new self(
            self::OPERATION_LOG,
            $context->tenantId,
            AuditActor::tenantMember($context),
            'admin.operation',
            $normalizedMethod . ' ' . $normalizedUri,
            new AuditResource('http.route', $normalizedUri),
            null,
            new AuditTrace($context->requestId, null, trim($ip)),
            $outcome,
            $reasonCode,
            [
                'admin_id' => $adminId,
                'username' => $username,
                'ip' => trim($ip),
                'uri' => $normalizedUri,
                'method' => $normalizedMethod,
                'params' => $params,
                'http_status' => $httpStatus,
            ],
        );
    }

    public static function platform(
        string $eventType,
        string $operation,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
        ?AuditTrace $trace = null,
    ): self {
        return new self(
            self::PLATFORM,
            null,
            $operatorId === null || $accountId === null
                ? AuditActor::platformSystem()
                : AuditActor::platformOperator($operatorId, $accountId),
            $eventType,
            $operation,
            $resource,
            null,
            $trace ?? new AuditTrace($requestId),
            $outcome,
            $reasonCode,
            $metadata,
        );
    }

    public static function tenantSystem(
        int $tenantId,
        string $eventType,
        string $operation,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
        ?AuditTrace $trace = null,
    ): self {
        return new self(
            self::TENANT,
            $tenantId,
            AuditActor::tenantSystem($tenantId),
            $eventType,
            $operation,
            $resource,
            null,
            $trace ?? new AuditTrace($requestId),
            $outcome,
            $reasonCode,
            $metadata,
        );
    }

    public static function tenantMember(
        TenantContext $context,
        string $eventType,
        string $operation,
        ?AuditResource $resource = null,
        ?AuditResource $boundaryTarget = null,
        int $targetCount = 0,
        ?string $targetSetDigest = null,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
    ): self {
        return new self(
            self::TENANT,
            $context->tenantId,
            AuditActor::tenantMember($context),
            $eventType,
            $operation,
            $resource,
            $boundaryTarget,
            new AuditTrace($context->requestId),
            $outcome,
            $reasonCode,
            $metadata,
            null,
            null,
            $targetCount,
            $targetSetDigest,
        );
    }

    public static function tenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $operation,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        ?AuditResource $resource = null,
    ): self {
        return new self(
            self::TENANT,
            $tenantId,
            AuditActor::tenantPlatformOperator($tenantId, $operatorId, $accountId),
            $eventType,
            $operation,
            $resource,
            null,
            new AuditTrace($requestId),
            $outcome,
            $reasonCode,
            $metadata,
        );
    }
}
