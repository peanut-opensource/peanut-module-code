<?php

declare(strict_types=1);

namespace app\platform\value\provider;

use InvalidArgumentException;

final readonly class ProviderQualificationSubject
{
    public function __construct(
        public string $providerKey,
        public string $category,
        public string $scopeType,
        public ?int $tenantId,
        public string $scopeReference,
        public bool $configured,
        public bool $callbackRequired,
        public ?string $credentialRotatedAt,
        public string $configDigest,
        public bool $implemented = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{2,95}$/D', $providerKey) !== 1
            || !in_array($category, ['payment', 'notification', 'oauth', 'storage'], true)
            || !in_array($scopeType, ['tenant', 'instance'], true)
            || ($scopeType === 'tenant') !== ($tenantId !== null && $tenantId > 0)
            || $scopeReference === '' || strlen($scopeReference) > 96
            || preg_match('/^[a-f0-9]{64}$/D', $configDigest) !== 1
        ) {
            throw new InvalidArgumentException('PROVIDER_QUALIFICATION_SUBJECT_INVALID');
        }
    }

    public function internalKey(): string
    {
        return implode("\0", [
            $this->providerKey,
            $this->scopeType,
            (string) ($this->tenantId ?? 0),
            $this->scopeReference,
        ]);
    }
}
