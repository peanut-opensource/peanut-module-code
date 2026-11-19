<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

final readonly class OwnerInvitationDeliveryResult
{
    private function __construct(
        public string $status,
        public ?string $provider,
        public ?string $messageId,
        public ?string $errorCode,
    ) {
        if (!in_array($status, ['pending_delivery', 'sent', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid invitation delivery status.');
        }
        if ($status === 'sent' && ($provider === null || trim($provider) === '')) {
            throw new \InvalidArgumentException('Sent delivery requires a provider.');
        }
    }

    public static function pending(): self
    {
        return new self('pending_delivery', null, null, null);
    }

    public static function sent(string $provider, ?string $messageId = null): self
    {
        return new self('sent', trim($provider), $messageId, null);
    }

    public static function failed(string $provider, string $errorCode): self
    {
        return new self('failed', trim($provider), null, trim($errorCode));
    }
}
