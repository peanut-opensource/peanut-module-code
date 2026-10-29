<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer;

/** Adapts the Settings-owned transfer capability; package redaction stays in ImportExport. */
final readonly class TenantSettingsConfigurationAdapter implements ConfigurationTransferAdapter
{
    public function __construct(private TenantSettingsTransfer $settings) {}

    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_TENANT_SETTINGS;
    }

    public function supportsCreate(): bool
    {
        return true;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        $entries = [];
        foreach ($this->settings->snapshot($this->tenant($context)) as $snapshot) {
            $entries[] = ConfigurationTransferValue::entry($this->key(), $snapshot->namespace, $snapshot->document);
        }
        return $entries;
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $snapshot = $this->settings->current($this->tenant($context), $key);
        if ($snapshot === null) {
            return ['exists' => false, 'value' => null, 'revision' => null];
        }
        return [
            'exists' => true,
            'value' => ConfigurationTransferValue::entry($this->key(), $key, $snapshot->document)['value'],
            'revision' => $snapshot->revision,
        ];
    }

    public function apply(TenantContext|PlatformContext $context, string $key, mixed $value, array $entry, ?int $revision): void
    {
        $tenant = $this->tenant($context);
        if (!is_array($value)) {
            throw new \RuntimeException('TRANSFER_TENANT_SETTING_INVALID');
        }
        $this->settings->apply($tenant, $key, $value, $revision);
    }

    private function tenant(TenantContext|PlatformContext $context): TenantContext
    {
        return $context instanceof TenantContext
            ? $context
            : throw new \RuntimeException('TRANSFER_TENANT_CONTEXT_INVALID');
    }
}
