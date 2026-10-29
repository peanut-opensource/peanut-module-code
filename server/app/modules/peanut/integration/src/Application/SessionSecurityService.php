<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Application;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\Modules\Integration\Package;
use PeanutAdmin\Modules\Identity\Contract\Dto\TenantSessionSummary;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class SessionSecurityService
{
    public function __construct(private TenantSessionAccess $sessions) {}

    /** @return list<SessionDevice> */
    public function list(AuthorizedOperationContext $context): array
    {
        $this->assertOperation($context, 'session-read');
        try {
            return array_map($this->device(...), $this->sessions->ownedSessions($context->tenantContext));
        } catch (TenantSessionAccessException) {
            throw IntegrationSecurityException::denied();
        }
    }

    public function revoke(AuthorizedOperationContext $context, string $sessionKey): SessionDevice
    {
        $this->assertOperation($context, 'session-revoke');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $sessionKey) !== 1) {
            throw IntegrationSecurityException::sessionNotFound();
        }
        try {
            return $this->device($this->sessions->revokeOwnedSession($context->tenantContext, $sessionKey));
        } catch (TenantSessionAccessException $exception) {
            if ($exception->problemCode === 'TENANT_SESSION_NOT_FOUND') {
                throw IntegrationSecurityException::sessionNotFound();
            }
            if ($exception->problemCode === 'TENANT_SESSION_CONFLICT') {
                throw IntegrationSecurityException::conflict();
            }
            throw IntegrationSecurityException::denied();
        }
    }

    private function assertOperation(AuthorizedOperationContext $context, string $operation): void
    {
        if (!hash_equals(Package::RESOURCE_KEY, $context->resourceKey) || !hash_equals($operation, $context->operation)) {
            throw IntegrationSecurityException::denied();
        }
    }

    private function device(TenantSessionSummary $session): SessionDevice
    {
        return new SessionDevice(
            $session->sessionKey,
            $session->clientKey,
            $session->status,
            $session->current,
            $session->maskedIp,
            $session->userAgentFingerprint,
            $session->issuedAt,
            $session->lastSeenAt,
            $session->absoluteExpiresAt,
            $session->revokedAt,
        );
    }
}
