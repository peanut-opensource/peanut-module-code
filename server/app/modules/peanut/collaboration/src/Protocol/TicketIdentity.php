<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Protocol;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;

/** Server-held original session reference. This value does not authorize a Document. */
final readonly class TicketIdentity
{
    private function __construct(
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public string $sessionKey,
        public string $clientKey,
        public int $authorizationRevision,
    ) {}

    public static function fromCurrentSession(TenantContext $context): self
    {
        return new self(
            $context->tenantId,
            $context->accountId,
            $context->memberId,
            $context->sessionKey,
            $context->clientKey,
            $context->authorizationRevision,
        );
    }

    /** Only Collaboration's authenticated ledger lookup may supply these persisted fields. */
    public static function fromLedger(array $identity): self
    {
        if (count($identity) !== 6
            || array_diff(array_keys($identity), ['tenant_id', 'account_id', 'member_id', 'session_key', 'client_key', 'authorization_revision']) !== []
            || !is_int($identity['tenant_id']) || $identity['tenant_id'] < 1
            || !is_int($identity['account_id']) || $identity['account_id'] < 1
            || !is_int($identity['member_id']) || $identity['member_id'] < 1
            || !is_int($identity['authorization_revision']) || $identity['authorization_revision'] < 1
            || !is_string($identity['session_key']) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $identity['session_key']) !== 1
            || !is_string($identity['client_key']) || $identity['client_key'] === '' || strlen($identity['client_key']) > 128) {
            throw new \InvalidArgumentException('COLLABORATION_TICKET_IDENTITY_INVALID');
        }
        return new self(
            $identity['tenant_id'],
            $identity['account_id'],
            $identity['member_id'],
            $identity['session_key'],
            $identity['client_key'],
            $identity['authorization_revision'],
        );
    }

    public function toLedger(): array
    {
        return ['tenant_id' => $this->tenantId, 'account_id' => $this->accountId, 'member_id' => $this->memberId,
            'session_key' => $this->sessionKey, 'client_key' => $this->clientKey,
            'authorization_revision' => $this->authorizationRevision];
    }

    /** This performs real Identity validation; callers still owe current owner/Document/File checks. */
    public function revalidate(TenantSessionAccess $sessions, string $requestId): TenantContext
    {
        return $sessions->revalidateSession(
            $this->tenantId,
            $this->accountId,
            $this->memberId,
            $this->sessionKey,
            $this->clientKey,
            $requestId,
        );
    }
}
