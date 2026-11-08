<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Contract;

use PeanutAdmin\Kernel\Context\TenantSystemContext;

interface TaskBootstrapCommands
{
    /** @param list<array<string,mixed>> $defaults */
    public function seedDefaults(TenantSystemContext $context, array $defaults): void;
}
