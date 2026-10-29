<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Contract;

interface PaymentChannelGrantCommands
{
    public function providerForPayWay(int $payWay): string;

    public function channelForPayWay(int $payWay): string;

    /** @return array<string,mixed> */
    public function activeGrantForTenant(object $context, string $provider, bool $lock = false): array;

    public function channelConfigured(object $context, int $payWay): bool;

    public function ensureSelfGrant(object $context, string $provider): void;

    public function grantTenantChannel(
        int $tenantId,
        string $provider,
        int $externalBindingId,
        string $merchantAccountRef = '',
        string $merchantGroupRef = '',
    ): int;

    public function revokeTenantChannel(int $tenantId, string $provider, int $externalBindingId): void;
}
