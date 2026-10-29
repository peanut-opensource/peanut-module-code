<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;

/** Storage boundary for one portable configuration collection. */
interface ConfigurationTransferAdapter
{
    public function key(): string;

    /** Whether this collection can create a missing destination entry. */
    public function supportsCreate(): bool;

    /** @return list<array<string, mixed>> */
    public function export(TenantContext|PlatformContext $context): array;

    /**
     * @return array{exists:bool,value:mixed,revision:?int}
     */
    public function current(TenantContext|PlatformContext $context, string $key): array;

    /** @param array<string, mixed> $entry */
    public function apply(
        TenantContext|PlatformContext $context,
        string $key,
        mixed $value,
        array $entry,
        ?int $revision,
    ): void;
}
