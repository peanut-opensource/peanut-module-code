<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Invitation;

final class OneTimeInvitationToken
{
    private const BYTES = 32;
    private const PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    private function __construct(private readonly string $plaintext) {}

    public static function issue(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }

    public static function fromPlaintext(#[\SensitiveParameter] string $plaintext): self
    {
        if (preg_match(self::PATTERN, $plaintext) !== 1) {
            throw TenantOwnerInvitationException::invalid('INVITATION_TOKEN_INVALID');
        }

        return new self($plaintext);
    }

    public function hash(): string
    {
        return hash('sha256', $this->plaintext);
    }

    public function expose(): string
    {
        return $this->plaintext;
    }

    /** @return array{token_hash:string} */
    public function __debugInfo(): array
    {
        return ['token_hash' => $this->hash()];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Invitation tokens cannot be serialized.');
    }
}
