<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;

/** Commands for applying a validated, portable configuration package. */
interface ConfigurationTransferCommands
{
    /**
     * @param array<string, mixed>|string $package
     * @param array<string, mixed> $secretBindings
     * @return array<string, mixed>
     */
    public function apply(
        TenantContext|PlatformContext $context,
        string $scope,
        array|string $package,
        array $secretBindings = [],
        string $conflictPolicy = 'abort',
    ): array;
}
