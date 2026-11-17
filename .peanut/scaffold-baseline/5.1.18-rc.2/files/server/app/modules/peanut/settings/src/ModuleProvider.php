<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings;

use PeanutAdmin\Modules\Settings\Application\SettingResolver;
use PeanutAdmin\Modules\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Modules\Settings\Contract\DeploymentSettingsTransfer;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer;
use PeanutAdmin\Modules\Settings\Infrastructure\UnavailableSecretProtector;
use PeanutAdmin\Modules\Settings\Definition\DeployedSettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Service\TenantSettingService;
use PeanutAdmin\Modules\Settings\Service\TenantSettingsTransferService;
use PeanutAdmin\Modules\Settings\Service\TenantApplicationSettingService;
use PeanutAdmin\Modules\Settings\Service\DeploymentSettingsTransferService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use think\App;
use Throwable;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.settings';
    }

    public function bindings(): array
    {
        return [
            TenantSettingsCommands::class => TenantSettingService::class,
            TenantSettingsQuery::class => TenantSettingService::class,
            TenantSettingsTransfer::class => TenantSettingsTransferService::class,
            TenantApplicationSettings::class => TenantApplicationSettingService::class,
            DeploymentSettingsTransfer::class => DeploymentSettingsTransferService::class,
            SecretProtector::class => fn(App $app): SecretProtector => $this->secretProtector($app),
            SettingDefinitionRegistry::class => fn(App $app): SettingDefinitionRegistry =>
                $app->make(DeployedSettingDefinitionRegistry::class)->build(),
            SettingResolver::class => function (App $app): SettingResolver {
                return new SettingResolver(
                    $app->make(SecretProtector::class),
                    new ArrayRevisionedSettingCache(),
                    $app->make(\PeanutAdmin\Modules\Identity\Tenancy\Application\TenantWorkspaceQueryService::class),
                    $app->config->get('peanut.settings', []),
                );
            },
        ];
    }

    private function secretProtector(App $app): SecretProtector
    {
        $encoded = trim((string) $app->config->get('peanut.settings_secrets.keys', ''));
        $activeKeyId = trim((string) $app->config->get('peanut.settings_secrets.active_key_id', ''));
        if ($encoded === '' || $activeKeyId === '') {
            return new UnavailableSecretProtector();
        }
        try {
            return SodiumSecretProtector::fromJson($encoded, $activeKeyId);
        } catch (Throwable) {
            return new UnavailableSecretProtector();
        }
    }
}
