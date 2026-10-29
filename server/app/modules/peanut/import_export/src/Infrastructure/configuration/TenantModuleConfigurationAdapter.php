<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Identity\Module\TenantModuleConfigurationService;

/** Converts Identity-owned module configuration data into portable, secret-safe entries. */
final readonly class TenantModuleConfigurationAdapter implements ConfigurationTransferAdapter
{
    public function __construct(private TenantModuleConfigurationService $modules) {}

    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_TENANT_MODULES;
    }

    public function supportsCreate(): bool
    {
        return false;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        return array_map(
            fn(array $state): array => ConfigurationTransferValue::entry($this->key(), $state['module_key'], $state['config']),
            $this->modules->transferSnapshot($this->tenantContext($context)),
        );
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $state = $this->modules->transferCurrent($this->tenantContext($context), $key);
        return $state === null ? ['exists' => false, 'value' => null, 'revision' => null] : [
            'exists' => $state['exists'],
            'value' => ConfigurationTransferValue::entry($this->key(), $key, $state['config'])['value'],
            'revision' => $state['revision'],
        ];
    }

    public function apply(TenantContext|PlatformContext $context, string $key, mixed $value, array $entry, ?int $revision): void
    {
        $tenant = $this->tenantContext($context);
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D', $key) !== 1) {
            throw new \RuntimeException('TRANSFER_TENANT_MODULE_INVALID');
        }
        if (!is_array($value) || $revision === null) {
            throw new \RuntimeException('TRANSFER_TENANT_MODULE_NOT_ENABLED');
        }
        $state = $this->modules->transferCurrent($tenant, $key);
        if ($state === null || !$state['exists']) {
            throw new \RuntimeException('TRANSFER_TENANT_MODULE_NOT_ENABLED');
        }
        // Existing native write path retains JSON Schema, ModuleGuard, revision, transaction and audit.
        $this->modules->update($tenant, $key, $value, $revision);
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
