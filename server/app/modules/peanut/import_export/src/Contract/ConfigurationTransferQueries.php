<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;

/** Queries for exporting and previewing a portable configuration package. */
interface ConfigurationTransferQueries
{
    /** @return array<string, mixed> */
    public function export(TenantContext|PlatformContext $context, string $scope): array;

    /**
     * @param array<string, mixed>|string $package
     * @param array<string, mixed> $secretBindings
     * @return array<string, mixed>
     */
    public function dryRun(
        TenantContext|PlatformContext $context,
        string $scope,
        array|string $package,
        array $secretBindings = [],
        string $conflictPolicy = 'abort',
    ): array;
}
