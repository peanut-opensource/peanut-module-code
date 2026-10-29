<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\Authorization;

use DateTimeImmutable;
use DateTimeZone;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Modules\ImportExport\Engine\Application\ImportExportService;
use PeanutAdmin\Kernel\Async\AsyncAuthorizationRevalidator;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Revalidates queued Admin work through the native Tenant Admin authorization service. */
final readonly class AdminAsyncAuthorization implements AsyncAuthorizationRevalidator
{
    public function __construct(private AdminAuthorizationService $authorization) {}

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        if ($envelope->tenantId < 1
            || $envelope->accountId < 1
            || $envelope->memberId < 1
            || trim($envelope->operationId) === ''
            || trim($envelope->traceId) === ''
            || !hash_equals(ImportExportService::RESOURCE_KEY, $envelope->resourceKey)
            || !hash_equals('create', $envelope->operation)
            || $envelope->requestedTargets !== []
        ) {
            throw $this->denied();
        }

        try {
            $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                $envelope->memberId,
                'async-' . hash('sha256', $envelope->operationId),
                $envelope->tenantId,
                $envelope->accountId,
                $envelope->memberId,
                'admin-async-worker',
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                1,
            ), $envelope->traceId);
            $principal = $this->authorization->principal($context);
            $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
                $envelope->memberId,
                'async-' . hash('sha256', $envelope->operationId),
                $envelope->tenantId,
                $envelope->accountId,
                $envelope->memberId,
                'admin-async-worker',
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                $principal->authorizationRevision,
            ), $envelope->traceId);

            return $this->authorization->authorizedOperation(
                $context,
                $principal,
                $envelope->resourceKey,
                $envelope->operation,
                $envelope->requestedTargets,
                $envelope->operationId,
            );
        } catch (\Throwable) {
            throw $this->denied();
        }
    }

    private function denied(): AuthException
    {
        return new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403);
    }
}
