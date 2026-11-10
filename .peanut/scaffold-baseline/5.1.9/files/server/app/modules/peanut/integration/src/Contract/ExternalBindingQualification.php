<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

/** Configuration presence and identity only; not connectivity or production qualification. */
final readonly class ExternalBindingQualification
{
    public function __construct(
        public int $tenantId,
        public string $providerKey,
        public bool $configured,
        public string $configDigest,
    ) {}
}
