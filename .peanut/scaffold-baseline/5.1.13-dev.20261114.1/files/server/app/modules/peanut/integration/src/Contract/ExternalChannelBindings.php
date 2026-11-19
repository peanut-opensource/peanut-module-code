<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

use PeanutAdmin\Kernel\Auth\TenantContext;

interface ExternalChannelBindings
{
    /** @return array<string,mixed> */
    public function config(TenantContext $context, string $provider): array;

    public function callbackKey(TenantContext $context, string $provider): string;

    /** @param array<string,mixed> $config */
    public function update(TenantContext $context, string $provider, array $config, string $identity): void;

    public function mutate(TenantContext $context, string $provider, string $identity, callable $mutator, ?callable $enabledResolver = null, ?string $identityHint = null): void;
}
