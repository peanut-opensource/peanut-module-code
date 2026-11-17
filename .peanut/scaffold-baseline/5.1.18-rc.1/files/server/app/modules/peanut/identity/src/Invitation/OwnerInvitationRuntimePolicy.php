<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

final readonly class OwnerInvitationRuntimePolicy
{
    private const PLAINTEXT_TOKEN_ENVIRONMENTS = ['local', 'development'];
    private const DELIVERY_MODES = ['auto', 'manual'];

    private function __construct(private string $environment, private string $deliveryMode)
    {
        if (!in_array($this->deliveryMode, self::DELIVERY_MODES, true)) {
            throw new \InvalidArgumentException('OWNER_INVITATION_DELIVERY_MODE_INVALID');
        }
    }

    public static function fromEnvironment(string $environment, string $deliveryMode = 'auto'): self
    {
        return new self(strtolower(trim($environment)), strtolower(trim($deliveryMode)));
    }

    public function allowsPlaintextTokenResponse(): bool
    {
        return $this->deliveryMode === 'manual'
            || in_array($this->environment, self::PLAINTEXT_TOKEN_ENVIRONMENTS, true);
    }

    public function assertIssuanceAllowed(OwnerInvitationDeliveryPort $delivery): void
    {
        if ($this->deliveryMode !== 'manual'
            && !$this->allowsPlaintextTokenResponse()
            && !$delivery->isConfigured()) {
            throw TenantOwnerInvitationException::unavailable(
                'OWNER_INVITATION_DELIVERY_UNAVAILABLE',
                'Owner invitation delivery is not configured.',
            );
        }
    }
}
