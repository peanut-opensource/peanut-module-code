<?php

declare(strict_types=1);

namespace app\common\context\notice;

use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final class NoticeTenantContext
{
    public const VERIFICATION_ACTOR = 'peanut.notice.verification';

    public static function member(CurrentExecutionContext $executionContext): TenantContext
    {
        $context = $executionContext->tenantAdmin();
        self::tenantId($executionContext, $context);
        return $context;
    }

    public static function tenantId(CurrentExecutionContext $executionContext, mixed $context): int
    {
        if (!$context instanceof TenantContext
            || $context->tenantId < 1
            || $context->accountId < 1
            || $context->memberId < 1
            || $context->authorizationRevision < 1
            || $context->sessionKey === ''
            || $context->clientKey === ''
            || $context->requestId === '') {
            throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
        }
        return self::authoritativeTenantId($executionContext, $context->tenantId);
    }

    public static function verification(
        CurrentExecutionContext $executionContext,
        object $request,
        string $operation,
    ): TenantContext|TenantSystemContext {
        $current = $executionContext->current();
        $context = match (true) {
            $current instanceof AdminExecutionContext => $current->tenant,
            $current instanceof ConsumerExecutionContext => $current->publicTenant,
            $current instanceof SystemExecutionContext => $current->system,
            default => null,
        };
        if ($context instanceof TenantContext) {
            self::tenantId($executionContext, $context);
            return $context;
        }
        if ($context instanceof TenantSystemContext
            && $context->tenantId > 0
            && $context->actorKey === self::VERIFICATION_ACTOR
            && $context->operation === $operation
            && $context->operationId !== '') {
            return $context;
        }
        throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
    }

    public static function verificationTenantId(
        CurrentExecutionContext $executionContext,
        AuthenticatedMemberContext|TenantContext|TenantSystemContext $context,
        string $operation,
    ): int {
        if ($context instanceof AuthenticatedMemberContext) {
            return self::authoritativeTenantId($executionContext, $context->tenantId);
        }
        if ($context instanceof TenantContext) {
            return self::tenantId($executionContext, $context);
        }
        if ($context->tenantId < 1
            || $context->actorKey !== self::VERIFICATION_ACTOR
            || $context->operation !== $operation
            || $context->operationId === '') {
            throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
        }
        return self::authoritativeTenantId($executionContext, $context->tenantId);
    }

    private static function authoritativeTenantId(CurrentExecutionContext $executionContext, int $tenantId): int
    {
        if ($executionContext->current()?->tenantId() !== $tenantId) {
            throw new AuthException('CONTEXT_TENANT_REQUIRED', 403);
        }
        return $tenantId;
    }
}
