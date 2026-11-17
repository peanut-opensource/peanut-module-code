<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

final readonly class OwnerInvitationDelivery
{
    public function __construct(
        public int $invitationId,
        public int $generation,
        public string $tenantName,
        public string $email,
        public string $displayName,
        public \DateTimeImmutable $expiresAt,
        private OneTimeInvitationToken $token,
    ) {}

    public function token(): string
    {
        return $this->token->expose();
    }

    public function idempotencyKey(): string
    {
        return 'tenant-owner-invitation:' . $this->invitationId . ':' . $this->generation;
    }

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        return [
            'invitation_id' => $this->invitationId,
            'generation' => $this->generation,
            'tenant_name' => $this->tenantName,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'token_hash' => $this->token->hash(),
        ];
    }
}
