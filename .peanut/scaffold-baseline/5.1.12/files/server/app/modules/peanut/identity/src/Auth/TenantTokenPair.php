<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Auth;

use DateTimeImmutable;

final readonly class TenantTokenPair
{
    public function __construct(
        public \PeanutAdmin\Kernel\Auth\RawToken $access,
        public \PeanutAdmin\Kernel\Auth\RawToken $refresh,
        public DateTimeImmutable $accessExpiresAt,
        public DateTimeImmutable $refreshExpiresAt,
    ) {}
}
