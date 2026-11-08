<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Application;

final readonly class MachinePrincipal
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $tenantId,
        public string $identityKey,
        public array $scopes,
        public string $audience = 'machine',
    ) {}
}
