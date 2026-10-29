<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\ImportExport\Infrastructure\configuration;

use PeanutAdmin\Modules\Settings\Contract\DeploymentSettingsTransfer;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;

/** Adapts the public Settings transfer contract to the import/export engine. */
final readonly class CoreSettingsConfigurationAdapter implements ConfigurationTransferAdapter
{
    public function __construct(private DeploymentSettingsTransfer $settings) {}

    public function key(): string
    {
        return ConfigurationPackageCodec::ADAPTER_CORE_SETTINGS;
    }
    public function supportsCreate(): bool
    {
        return true;
    }

    public function export(TenantContext|PlatformContext $context): array
    {
        $platform = $this->platform($context);
        return array_map(
            fn(array $state): array => $this->entry($state),
            array_values(array_filter($this->settings->snapshot($platform), static fn(array $state): bool => $state['exists'])),
        );
    }

    public function current(TenantContext|PlatformContext $context, string $key): array
    {
        $state = $this->settings->current($this->platform($context), $key);
        return [
            'exists' => $state['exists'],
            'value' => $state['exists'] ? $this->entry($state)['value'] : null,
            'revision' => $state['revision'],
        ];
    }

    public function apply(TenantContext|PlatformContext $context, string $key, mixed $value, array $entry, ?int $revision): void
    {
        $platform = $this->platform($context);
        $state = $this->settings->current($platform, $key);
        $unsetSecret = $state['secret'] && SecretReferenceCodec::isMarker($entry['value'] ?? null)
            && (($entry['value']['$secret']['state'] ?? null) === 'unconfigured');
        $this->settings->apply($platform, $key, $value, $unsetSecret || $value === null, $revision);
    }

    /** @param array{key:string,exists:bool,secret:bool,configured:bool,value:mixed,revision:?int} $state */
    private function entry(array $state): array
    {
        if (!$state['secret']) {
            return ConfigurationTransferValue::entry($this->key(), $state['key'], $state['value']);
        }
        $references = [];
        $marker = SecretReferenceCodec::marker(
            $state['configured'] ? 'configured' : '',
            ConfigurationTransferValue::referenceRoot($this->key(), $state['key']),
            $references,
        );
        return [
            'adapter' => $this->key(), 'key' => $state['key'], 'value' => $marker,
            'secrets' => SecretReferenceCodec::references($marker),
        ];
    }

    private function platform(TenantContext|PlatformContext $context): PlatformContext
    {
        return $context instanceof PlatformContext
            ? $context
            : throw new \RuntimeException('TRANSFER_DEPLOYMENT_CONTEXT_INVALID');
    }
}
