<?php

declare(strict_types=1);

namespace app\common\contract\audit;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AuditActor
{
    public const TENANT_MEMBER = 'member';
    public const TENANT_SYSTEM = 'tenant_system';
    public const PLATFORM_OPERATOR = 'platform_operator';
    public const PLATFORM_SYSTEM = 'platform_system';

    public function __construct(
        public string $type,
        public ?int $tenantId = null,
        public ?int $tenantMemberId = null,
        public ?int $accountId = null,
        public ?int $platformOperatorId = null,
    ) {
        if (!in_array($type, [
            self::TENANT_MEMBER,
            self::TENANT_SYSTEM,
            self::PLATFORM_OPERATOR,
            self::PLATFORM_SYSTEM,
        ], true)) {
            throw new \InvalidArgumentException('AUDIT_ACTOR_TYPE_INVALID');
        }
        if ($tenantId !== null && $tenantId < 1) {
            throw new \InvalidArgumentException('AUDIT_ACTOR_TENANT_INVALID');
        }
        if ($tenantMemberId !== null && $tenantMemberId < 1) {
            throw new \InvalidArgumentException('AUDIT_ACTOR_MEMBER_INVALID');
        }
        if ($accountId !== null && $accountId < 1) {
            throw new \InvalidArgumentException('AUDIT_ACTOR_ACCOUNT_INVALID');
        }
        if ($platformOperatorId !== null && $platformOperatorId < 1) {
            throw new \InvalidArgumentException('AUDIT_ACTOR_OPERATOR_INVALID');
        }
    }

    public static function tenantMember(TenantContext $context): self
    {
        return new self(
            self::TENANT_MEMBER,
            $context->tenantId,
            $context->memberId,
            $context->accountId,
        );
    }

    public static function tenantSystem(int $tenantId): self
    {
        return new self(self::TENANT_SYSTEM, $tenantId);
    }

    public static function tenantPlatformOperator(int $tenantId, int $operatorId, int $accountId): self
    {
        return new self(self::PLATFORM_OPERATOR, $tenantId, null, $accountId, $operatorId);
    }

    public static function platformOperator(int $operatorId, int $accountId): self
    {
        return new self(self::PLATFORM_OPERATOR, null, null, $accountId, $operatorId);
    }

    public static function platformSystem(): self
    {
        return new self(self::PLATFORM_SYSTEM);
    }
}
