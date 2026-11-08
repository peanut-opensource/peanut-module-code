<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

interface ExternalChannelBindingStore
{
    public function ensureUnconfiguredBinding(int $tenantId, string $tenantCode, string $provider): void;

    public function tenantIsActive(int $tenantId): bool;

    /** @param array<string, mixed> $config */
    public function updateBinding(
        int $tenantId,
        string $provider,
        array $config,
        string $identity,
        bool $enabled,
    ): void;

    /**
     * @param callable(array<string, mixed>): array{config:array<string, mixed>,enabled:bool} $mutation
     */
    public function mutateBinding(
        int $tenantId,
        string $provider,
        string $identity,
        callable $mutation,
        ?string $identityHint = null,
    ): void;
}
