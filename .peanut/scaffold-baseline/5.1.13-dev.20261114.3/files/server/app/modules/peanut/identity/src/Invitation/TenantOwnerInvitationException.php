<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

final class TenantOwnerInvitationException extends \DomainException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $code, string $message = 'Invitation input is invalid.'): self
    {
        return new self($code, 422, $message);
    }

    public static function notFound(): self
    {
        return new self('INVITATION_NOT_FOUND', 404, 'Invitation was not found.');
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, 409, $message);
    }

    public static function gone(string $code, string $message): self
    {
        return new self($code, 410, $message);
    }

    public static function unavailable(string $code, string $message): self
    {
        return new self($code, 503, $message);
    }
}
