<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Settings;

use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\persistence\TenantPersistenceConfiguration;
use app\common\tenancy\DataScopePolicy;
use PeanutAdmin\Modules\Settings\Application\SettingAdminService;
use PeanutAdmin\Modules\Settings\Application\SettingResolver;
use PeanutAdmin\Modules\Settings\Cache\ArrayRevisionedSettingCache;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings;
use PeanutAdmin\Modules\Settings\Contract\DeploymentSettingsTransfer;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsProvider;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsTransfer;
use PeanutAdmin\Modules\Settings\Infrastructure\ThinkPhpTenantSettingsProvider;
use PeanutAdmin\Modules\Settings\Infrastructure\UnavailableSecretProtector;
use PeanutAdmin\Modules\Settings\Definition\DeployedSettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Modules\Settings\Service\SettingsHttpApplicationService;
use PeanutAdmin\Modules\Settings\Service\TenantSettingService;
use PeanutAdmin\Modules\Settings\Service\TenantSettingsTransferService;
use PeanutAdmin\Modules\Settings\Service\TenantApplicationSettingService;
use PeanutAdmin\Modules\Settings\Service\DeploymentSettingsTransferService;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
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
            TenantSettingsProvider::class => fn(App $app): TenantSettingsProvider => new ThinkPhpTenantSettingsProvider(
                $app->make(DataScopePolicy::class),
            ),
            TenantSettingService::class => fn(App $app): TenantSettingService => new TenantSettingService(
                $app->make(TenantSettingsProvider::class),
            ),
            TenantSettingsCommands::class => TenantSettingService::class,
            TenantSettingsQuery::class => TenantSettingService::class,
            TenantSettingsTransfer::class => TenantSettingsTransferService::class,
            TenantApplicationSettings::class => TenantApplicationSettingService::class,
            DeploymentSettingsTransfer::class => fn(App $app): DeploymentSettingsTransfer => new DeploymentSettingsTransferService(
                $app->make(SettingDefinitionRegistry::class),
                $app->make(SettingAdminService::class),
            ),
            SecretProtector::class => fn(App $app): SecretProtector => $this->secretProtector($app),
            SettingDefinitionRegistry::class => fn(App $app): SettingDefinitionRegistry =>
                $app->make(DeployedSettingDefinitionRegistry::class)->build(),
            SettingAdminService::class => function (App $app): SettingAdminService {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                return new SettingAdminService(
                    $app->make(SecretProtector::class),
                    $persistence->mode,
                    $persistence->instanceTenantId,
                    $app->make(TenantMemberDirectory::class),
                    $app->make(PlatformOperatorIdentityQuery::class),
                    $app->config->get('peanut.settings', []),
                );
            },
            SettingResolver::class => function (App $app): SettingResolver {
                $persistence = $app->make(TenantPersistenceConfiguration::class);
                return new SettingResolver(
                    $app->make(SecretProtector::class),
                    new ArrayRevisionedSettingCache(),
                    $persistence->mode,
                    $persistence->instanceTenantId,
                    $app->make(\PeanutAdmin\Modules\Identity\Tenancy\Application\TenantWorkspaceQueryService::class),
                    $app->config->get('peanut.settings', []),
                );
            },
            SettingsHttpApplicationService::class => fn(App $app): SettingsHttpApplicationService => new SettingsHttpApplicationService(
                $app->make(SettingDefinitionRegistry::class),
                $app->make(SettingAdminService::class),
                $app->make(SettingResolver::class),
                $app->make(IdempotentCommandExecutor::class),
                $app->make(ModuleRuntimeRepository::class),
                $app->get(\app\common\execution\CurrentExecutionContext::class),
                $app->get(\app\common\contract\authorization\AdminAuthorizationQuery::class),
            ),
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
