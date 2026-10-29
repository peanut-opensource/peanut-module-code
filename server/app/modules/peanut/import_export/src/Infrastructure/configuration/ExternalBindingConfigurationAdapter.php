<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingTransfer;

/** Adapts Integration's public transfer data; callback secrets never enter portable packages. */
final readonly class ExternalBindingConfigurationAdapter implements ConfigurationTransferAdapter
{
    public function __construct(private ExternalBindingTransfer $bindings) {}

    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_EXTERNAL_BINDINGS;
    }

    public function supportsCreate(): bool
    {
        return true;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        return array_map(
            fn(array $state): array => ConfigurationTransferValue::entry($this->key(), $state['provider'], $state['value']),
            $this->bindings->snapshot($this->tenantContext($context)),
        );
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $state = $this->bindings->current($this->tenantContext($context), $key);
        return $state === null ? ['exists' => false, 'value' => null, 'revision' => null] : [
            'exists' => true,
            'value' => ConfigurationTransferValue::entry($this->key(), $key, $state['value'])['value'],
            'revision' => $state['revision'],
        ];
    }

    public function apply(TenantContext|PlatformContext $context, string $key, mixed $value, array $entry, ?int $revision): void
    {
        $tenant = $this->tenantContext($context);
        if (!is_array($value)) {
            throw new \RuntimeException('TRANSFER_EXTERNAL_BINDING_INVALID');
        }
        $this->bindings->apply($tenant, $key, $value, $revision);
    }

    private function tenantContext(TenantContext|PlatformContext $context): TenantContext
    {
        if (!$context instanceof TenantContext
            || $context->tenantId < 1 || $context->accountId < 1 || $context->memberId < 1
            || $context->authorizationRevision < 1 || $context->sessionKey === ''
            || $context->clientKey === '' || $context->requestId === '') {
            throw new \RuntimeException('TRANSFER_TENANT_CONTEXT_INVALID');
        }
        return $context;
    }
}
