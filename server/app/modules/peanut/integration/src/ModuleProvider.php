<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Integration\Application\MachineScopeCatalog;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindingStore;
use PeanutAdmin\Modules\Integration\Contract\ExternalChannelBindings;
use PeanutAdmin\Modules\Integration\Contract\ExternalBindingTransfer;
use PeanutAdmin\Modules\Integration\Contract\ExternalIntegrationBootstrapCommands;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantAudit;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Contract\ExternalTenantResolutionService;
use PeanutAdmin\Modules\Integration\Contract\IntegrationSecurityRepository;
use PeanutAdmin\Modules\Integration\Contract\MachineScopeGrantResolver;
use PeanutAdmin\Modules\Integration\Infrastructure\ConfiguredMachineScopeGrantResolver;
use PeanutAdmin\Modules\Integration\Infrastructure\CurlWebhookTransport;
use PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantAudit;
use PeanutAdmin\Modules\Integration\Infrastructure\ThinkPhpExternalTenantBindingRepository;
use PeanutAdmin\Modules\Integration\Infrastructure\UnavailableWebhookSecretProtector;
use PeanutAdmin\Modules\Integration\Infrastructure\Persistence\ThinkPhpIntegrationSecurityRepository;
use PeanutAdmin\Modules\Integration\Service\ExternalChannelBindingService;
use PeanutAdmin\Modules\Integration\Service\ExternalBindingTransferService;
use PeanutAdmin\Modules\Integration\Service\ExternalTenantResolver;
use PeanutAdmin\IntegrationSecurity\Crypto\AesGcmWebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Crypto\WebhookSecretProtector;
use PeanutAdmin\IntegrationSecurity\Webhook\HostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\SystemHostAddressResolver;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookDestinationPolicy;
use PeanutAdmin\IntegrationSecurity\Webhook\WebhookTransport;
use PeanutAdmin\Kernel\Module\ModuleProvider as ModuleProviderContract;
use think\App;

final class ModuleProvider implements ModuleProviderContract
{
    public function moduleKey(): string
    {
        return 'peanut.integration';
    }

    public function bindings(): array
    {
        return [
            ExternalTenantAudit::class => ThinkPhpExternalTenantAudit::class,
            ExternalTenantBindingRepository::class => ThinkPhpExternalTenantBindingRepository::class,
            ExternalChannelBindingStore::class => ThinkPhpExternalTenantBindingRepository::class,
            ExternalTenantResolutionService::class => ExternalTenantResolver::class,
            ExternalChannelBindings::class => ExternalChannelBindingService::class,
            ExternalBindingTransfer::class => ExternalBindingTransferService::class,
            ExternalIntegrationBootstrapCommands::class => fn(App $app): ExternalIntegrationBootstrapCommands => new ExternalIntegrationBootstrapCommands(
                $app->make(ExternalChannelBindingStore::class),
                $app->make(CurrentExecutionContext::class),
                $app->make(AdminDirectoryQuery::class),
            ),
            ThinkPhpIntegrationSecurityRepository::class => fn(App $app): ThinkPhpIntegrationSecurityRepository => new ThinkPhpIntegrationSecurityRepository(
                $app->make(CurrentExecutionContext::class),
                $app->make(\app\common\services\audit\AuditContractHost::class),
            ),
            IntegrationSecurityRepository::class => ThinkPhpIntegrationSecurityRepository::class,
            HostAddressResolver::class => SystemHostAddressResolver::class,
            WebhookTransport::class => CurlWebhookTransport::class,
            WebhookDestinationPolicy::class => fn(App $app): WebhookDestinationPolicy => new WebhookDestinationPolicy(
                $app->make(HostAddressResolver::class),
            ),
            WebhookSecretProtector::class => fn(App $app): WebhookSecretProtector => $this->webhookSecrets($app),
            MachineScopeCatalog::class => fn(App $app): MachineScopeCatalog => new MachineScopeCatalog(
                $this->machineScopes($app),
            ),
            MachineScopeGrantResolver::class => fn(App $app): MachineScopeGrantResolver => new ConfiguredMachineScopeGrantResolver(
                $this->machineScopes($app),
            ),
        ];
    }

    /** @return list<string> */
    private function machineScopes(App $app): array
    {
        $scopes = [];
        foreach (explode(',', (string) $app->config->get('integration.machine_scopes', '')) as $scope) {
            $scope = trim($scope);
            if ($scope !== '') {
                $scopes[$scope] = true;
            }
        }
        $result = array_keys($scopes);
        sort($result, SORT_STRING);
        return $result;
    }

    private function webhookSecrets(App $app): WebhookSecretProtector
    {
        $keyId = trim((string) $app->config->get('integration.webhook_secret_key_id', ''));
        $key = trim((string) $app->config->get('integration.webhook_secret_key', ''));
        if ($keyId === '' || $key === '') {
            return new UnavailableWebhookSecretProtector();
        }
        try {
            return new AesGcmWebhookSecretProtector($keyId, $key);
        } catch (\Throwable) {
            return new UnavailableWebhookSecretProtector();
        }
    }
}
