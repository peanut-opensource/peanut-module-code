<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

interface ExternalTenantAudit
{
    /** @param array<string, int|string> $attributes */
    public function record(string $outcome, array $attributes): void;
}
