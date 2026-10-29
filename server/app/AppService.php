<?php

declare (strict_types=1);

namespace app;

use app\adminapi\services\config\ConfigApplicationService;
use app\adminapi\services\generator\GeneratorService;
use app\adminapi\services\WorkbenchApplicationService;
use app\adminapi\infrastructure\generator\ThinkPhpGeneratorMetadata;
use app\adminapi\infrastructure\AdminApiAccessRegistry;
use app\adminapi\services\AdminLoginAttemptService;
use app\adminapi\services\OperationLogService;
use app\adminapi\infrastructure\generator\GeneratorImportPersistence;
use app\api\services\IndexApplicationService;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use app\common\composition\ModuleComposition;
use app\common\contract\AdminPermissionPolicy;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\contract\idempotency\IdempotentCommandExecutor;
use app\common\contract\module\ModuleQualificationQuery;
use app\common\services\audit\AuditContractHost;
use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use app\common\execution\ExecutionContextStore;
use app\common\model\TenantOwnedModel;
use app\common\infrastructure\http\GuzzleOutboundHttpTransport;
use app\common\contract\http\OutboundHttpTransport;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\enum\instance\DeploymentMode;
use app\common\infrastructure\idempotency\ThinkPhpIdempotentCommandExecutor;
use app\common\services\installation\InstallationExecutionHost;
use app\common\services\installation\InstallationConfigurationHost;
use app\common\infrastructure\module\ModuleExecutionBoundary;
use app\common\composition\CoreServiceOverrides;
use app\common\services\CrontabCommandService;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PeanutAdmin\Modules\File\Service\FileService;
use app\common\services\ProductAssetReferenceService;
use app\common\services\authorization\MenuPermissionUsageQuery;
use app\common\runtime\authorization\RoleAdministrationRuntime;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationQuery;
use PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Contract\TenantAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Contract\PlatformAuditDiagnosticQuery;
use PeanutAdmin\Modules\Identity\Contract\PlatformOperatorIdentityQuery;
use PeanutAdmin\Modules\Task\Contract\TaskDiagnosticQuery;
use PeanutAdmin\Modules\Identity\access\SourceRead\SourceReadCapabilityRegistry;
use app\common\runtime\org\TenantAdminRuntime;
use PeanutAdmin\Modules\Identity\Contract\TenantIdentityQuery;
use PeanutAdmin\Modules\File\Composition\Storage\AliyunStorageClientFactory;
use PeanutAdmin\Modules\File\Infrastructure\Storage\FailClosedStorageCredentialResolver;
use PeanutAdmin\Modules\File\Composition\Storage\QcloudStorageClientFactory;
use PeanutAdmin\Modules\File\Infrastructure\Storage\QiniuStorageHttpTransport;
use app\common\contract\storage\StorageCredentialResolver;
use PeanutAdmin\Modules\File\Service\Storage\StorageConfigurationService;
use PeanutAdmin\Modules\File\Composition\Storage\StorageDriverFactory;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use app\common\tenancy\DataScopePolicy;
use app\common\tenancy\DefaultTenantEntryBindingLookup;
use app\common\tenancy\MultiTenantDataScopePolicy;
use app\common\validate\InputValidator;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationDeliveryPort;
use PeanutAdmin\Modules\Identity\Invitation\OwnerInvitationRuntimePolicy;
use PeanutAdmin\Modules\Identity\Invitation\UnavailableOwnerInvitationDeliveryPort;
use app\platform\identity\CorePlatformOperatorIdentityPort;
use app\platform\identity\PlatformOperatorIdentityPort;
use app\platform\services\ApplicationTenantBootstrapService;
use app\platform\services\CoreTenantOwnerAdminProvisioner;
use app\platform\services\PlatformOperatorSessionService;
use app\platform\services\TenantGovernanceService;
use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use app\platform\infrastructure\module\DeployedTenantModuleRegistry;
use app\platform\validation\module\OpisTenantModuleConfigValidator;
use app\platform\services\module\PlatformTenantModuleService;
use app\platform\infrastructure\module\VerifiedTenantModuleRepository;
use PeanutAdmin\Modules\Ops\Service\PlatformOpsApplicationService;
use PeanutAdmin\Modules\Ops\Infrastructure\ApplicationRuntimeStatusProvider;
use PeanutAdmin\Modules\Ops\Service\DeploymentModuleRequestService;
use PeanutAdmin\Modules\Ops\Infrastructure\PairedBackupProvider;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpMaintenanceWindowStore;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpModuleOperationTaskExecutionService;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpOpsTaskDispatcher;
use PeanutAdmin\Modules\Ops\Infrastructure\ThinkPhpUpgradeTaskExecutionService;
use PeanutAdmin\Modules\Ops\Infrastructure\PlatformAuditRuntimeLogProvider;
use PeanutAdmin\Modules\Ops\Service\PlatformBackupCenterService;
use PeanutAdmin\Modules\Ops\Service\PlatformDiagnosticBundleService;
use PeanutAdmin\Modules\Ops\Service\PlatformModuleOperationExecutionService;
use PeanutAdmin\Modules\Ops\Infrastructure\Authorization\PlatformOpsPermissionChecker;
use PeanutAdmin\Modules\Ops\Service\PlatformUpgradeExecutionService;
use PeanutAdmin\Modules\Ops\Service\PlatformUpgradeReadinessService;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\services\plugin\PlatformModuleRuntimeService;
use app\platform\composition\plugin\ModuleDefinitionRegistryFactory;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use app\platform\services\plugin\PluginCatalogSyncService;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use app\platform\infrastructure\provider\NotificationQualificationContributor;
use app\platform\infrastructure\provider\OauthQualificationContributor;
use app\platform\infrastructure\provider\PaymentQualificationContributor;
use app\platform\services\provider\PlatformProviderQualificationService;
use app\platform\infrastructure\provider\StorageQualificationContributor;
use think\Service;
use think\Model;
use think\facade\Config;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpTenantAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\Persistence\ThinkPhpPlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthRepository;
use PeanutAdmin\Modules\Identity\Auth\PlatformAuthService;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Auth\TokenIssuer;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Kernel\Idempotency\IdempotencyService;
use PeanutAdmin\Modules\Identity\Http\TenantAuthEndpoint;
use PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService;
use PeanutAdmin\Modules\Identity\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Module\TenantModuleConfigurationService;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Tenancy\DefaultTenantContextResolver;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;
use PeanutAdmin\Modules\Ops\Domain\Application\PlatformPermissionChecker;
use PeanutAdmin\Modules\Ops\Domain\Logs\RuntimeLogProviderRegistry;
use PeanutAdmin\Modules\Ops\Domain\Logs\RuntimeLogService;
use PeanutAdmin\Modules\Ops\Domain\Logs\SafeLogMessageCatalog;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceReasonRegistry;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceService;
use PeanutAdmin\Modules\Ops\Domain\Maintenance\MaintenanceWindowStore;
use PeanutAdmin\Modules\Ops\Domain\Status\OpsStatusService;
use PeanutAdmin\Modules\Ops\Domain\Status\RuntimeStatusProvider;
use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProviderRegistry;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskDispatcher;
use PeanutAdmin\Modules\Ops\Domain\Task\OpsTaskService;
use PeanutAdmin\Modules\Settings\Service\SettingCatalogService;
use app\common\persistence\TenantPersistenceConfiguration;

/** 应用组合根，集中注册 Host 基础设施、业务服务与官方 Module Runtime。 */
class AppService extends Service
{
    public function register(): void
    {
        $this->registerHttpSqlTiming();
        $this->registerExecutionContext();
        $this->registerAuthentication();
        $this->registerAuthorization();
        $this->registerStorage();
        $this->registerPlatform();
        $this->registerApplicationServices();
        $this->registerModules();
    }

    /** The sampling selector is FPM process configuration, never a request or .env setting. */
    private function httpSqlTimingRequestId(): ?string
    {
        $selected = getenv('PEANUT_HTTP_SQL_TIMING_REQUEST_ID', true);
        if (!is_string($selected) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $selected) !== 1) {
            return null;
        }
        $header = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        return is_string($header) && hash_equals($selected, trim($header)) ? $selected : null;
    }

    private function registerHttpSqlTiming(): void
    {
        if (PHP_SAPI !== 'fpm-fcgi' || $this->app->runningInConsole()) {
            return;
        }
        $requestId = $this->httpSqlTimingRequestId();
        if ($requestId === null) {
            return;
        }
        $database = $this->app->db;
        // Do not overwrite another listener or retune a connection already created by earlier application code.
        if ($database->getListen() !== [] || $database->getInstance() !== []) {
            return;
        }
        $configuration = $this->app->config->get('database', []);
        $connection = $configuration['default'] ?? null;
        if (!is_string($connection) || !is_array($configuration['connections'][$connection] ?? null)
            || !RequestTrace::beginSqlTiming($requestId)) {
            return;
        }
        $database->listen(static function (mixed $sql, mixed $seconds): void {
            RequestTrace::addSqlTime($sql, $seconds);
        });
        $configuration['connections'][$connection]['trigger_sql'] = true;
        $this->app->config->set($configuration, 'database');
        $this->app->event->listen(\think\event\HttpEnd::class, function (): void {
            $timing = RequestTrace::finishSqlTiming($this->app->request);
            if ($timing !== null && $this->httpSqlTimingRequestId() === $timing['request_id']) {
                $this->app->log->info('http_sql_timing ' . json_encode($timing, JSON_THROW_ON_ERROR));
            }
        });
    }

    private function registerExecutionContext(): void
    {
        $contexts = new ExecutionContextStore();
        $current = new CurrentExecutionContext($contexts);
        $this->app->instance(ExecutionContextStore::class, $contexts);
        $this->app->instance(CurrentExecutionContext::class, $current);
        $configuredOverrides = Config::get('peanut.overrides', []);
        CoreServiceOverrides::configure(is_array($configuredOverrides) ? $configuredOverrides : []);
        foreach (CoreServiceOverrides::registry()->bindings() as $contract => $implementation) {
            if (!$this->app->bound($contract)) {
                $this->app->bind($contract, $implementation);
            }
        }
        $this->app->bind(ModuleCatalogApplier::class, fn(): ModuleCatalogApplier => new ModuleCatalogApplier(
            $this->app->make(SettingCatalogService::class),
            new ModuleAuthorizationCatalogSynchronizer(
                $this->app->make(\PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository::class),
            ),
            $this->app->make(\PeanutAdmin\Kernel\Menu\MenuCatalogRepository::class),
            $this->app->make(\PeanutAdmin\Modules\ReferenceCodes\Service\ReferenceCodeCatalogService::class),
            $this->app->make(\PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService::class),
        ));
        $this->app->bind(IdempotencyService::class, fn(): IdempotencyService => new IdempotencyService(
            $this->app->make(TenantPersistenceConfiguration::class)->mode,
            $this->app->make(TenantPersistenceConfiguration::class)->instanceTenantId,
        ));
        $this->app->bind(IdempotentCommandExecutor::class, ThinkPhpIdempotentCommandExecutor::class);
        $this->app->bind(OutboundHttpTransport::class, fn(): OutboundHttpTransport => new GuzzleOutboundHttpTransport(
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(InputValidator::class, fn(): InputValidator => new InputValidator(
            $this->app,
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(
            InstallationConfigurationHost::class,
            fn(): InstallationConfigurationHost => new InstallationConfigurationHost(dirname(__DIR__), deferPublication: \app\common\infrastructure\installation\ReadonlyHttpMount::active(dirname(__DIR__))),
        );
        $this->app->bind(
            InstallationExecutionHost::class,
            fn(): InstallationExecutionHost => new InstallationExecutionHost(
                dirname(__DIR__),
                $this->app->make(ModuleCatalogApplier::class),
                $this->app->make(\PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery::class),
                fn(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry $registry): \PeanutAdmin\Kernel\Module\ModuleRuntimeRepository => $this->installationModuleRuntime($registry),
                $this->app->make(AuditContractHost::class),
                $this->app->make(\PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::class),
                $this->app->make(ModuleComposition::class),
                $this->app->make(CompiledModuleRegistry::class),
            ),
        );
        $this->app->bind(AuditContractHost::class, fn(): AuditContractHost => new AuditContractHost(
            $this->app->make(CurrentExecutionContext::class),
            $this->app->make(AuditService::class),
        ));
        $this->app->bind(OperationLogService::class, fn(): OperationLogService => new OperationLogService(
            $this->app->make(AuditContractHost::class),
        ));
    }

    /** Composition-only construction; keep the original installation availability locks and late registry. */
    private function installationModuleRuntime(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry $registry): \PeanutAdmin\Kernel\Module\ModuleRuntimeRepository
    {
        return new \PeanutAdmin\Modules\Identity\Module\Persistence\ThinkPhpModuleRuntimeRepository($registry, true);
    }

    private function registerAuthentication(): void
    {
        $this->app->bind(DemoAccountPolicy::class, fn(): DemoAccountPolicy => new DemoAccountPolicy(
            (bool) Config::get('peanut.demo.enabled', false),
            array_values(array_filter([
                (string) Config::get('peanut.demo.admin_email', ''),
                (string) Config::get('peanut.demo.platform_email', ''),
                (string) Config::get('peanut.demo.tenant_a_email', ''),
                (string) Config::get('peanut.demo.tenant_b_email', ''),
            ], static fn(string $email): bool => trim($email) !== '')),
        ));
        $this->app->bind(TenantAuthService::class, function (): TenantAuthService {
            $key = trim((string) Config::get('tenant_auth.identifier_hmac_key', ''));
            if (strlen($key) < 32) {
                throw new \DomainException('TENANT_AUTH_CONFIGURATION_UNAVAILABLE');
            }
            return new TenantAuthService(
                $this->app->make(TenantAuthRepository::class),
                $this->app->make(PasswordHasher::class),
                $this->app->make(Clock::class),
                $this->app->make(TokenIssuer::class),
                $key,
            );
        });
        $this->app->bind(TenantAuthEndpoint::class, fn(): TenantAuthEndpoint => new TenantAuthEndpoint(
            $this->app->make(TenantAuthService::class),
        ));
        $this->app->bind(AdminLoginAttemptService::class, fn(): AdminLoginAttemptService => new AdminLoginAttemptService(
            (int) Config::get('admin_auth.password_error_times', 5),
            (int) Config::get('admin_auth.lock_minutes', 30),
        ));
    }

    /** Wires current native Admin authorization services to their exact constructor contracts. */
    private function registerAuthorization(): void
    {
        // Cross-Tenant read capabilities are server registrations supplied by source Modules.
        // The base product intentionally starts empty, so unknown/wide scopes fail closed.
        $this->app->instance(SourceReadCapabilityRegistry::class, new SourceReadCapabilityRegistry([]));
        $this->app->bind(\PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository::class, ThinkPhpTenantAuthorizationRepository::class);
        $this->app->bind(
            \PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository::class,
            \PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository::class,
        );
        $this->app->bind(\PeanutAdmin\Kernel\Menu\MenuCatalogRepository::class, ThinkPhpMenuCatalogRepository::class);
        $this->app->bind(AdminAuthorizationService::class, fn(): AdminAuthorizationService => new AdminAuthorizationService(
            $this->app->make(CoreTenantModuleAdminBridge::class),
            $this->app->make(AdminPermissionPolicy::class),
            $this->app->make(\PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory::class),
            $this->app->make(AdminDirectoryQuery::class),
            $this->app->make(TenantAuthorizationQuery::class),
        ));
        $this->app->bind(AdminAuthorizationQuery::class, fn(): AdminAuthorizationQuery => $this->app->make(AdminAuthorizationService::class));
        $this->app->bind(CoreTenantModuleAdminBridge::class, fn(): CoreTenantModuleAdminBridge => new CoreTenantModuleAdminBridge(
            $this->app->make(ThinkPhpModuleGovernanceProvider::class),
            $this->app->make(\PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository::class),
            $this->app->make(\PeanutAdmin\Kernel\Menu\MenuCatalogRepository::class),
            $this->app->make(TenantAuthorizationQuery::class),
            $this->app->make(\PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService::class),
        ));
        $this->app->bind(RoleAdministrationRuntime::class, fn(): RoleAdministrationRuntime => new RoleAdministrationRuntime(
            new RoleAdminService($this->app->make(AuditService::class)),
            $this->app->make(AdminAuthorizationService::class),
            $this->app->make(TenantAuthorizationQuery::class),
            $this->app->make(\PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService::class),
        ));
        $this->app->bind(AdminDirectoryQuery::class, fn(): AdminDirectoryQuery => new AdminDirectoryQuery(
            $this->app->make(CurrentExecutionContext::class),
        ));
        $this->app->bind(TenantAdminRuntime::class, fn(): TenantAdminRuntime => new TenantAdminRuntime(
            $this->app->make(MemberAdminService::class),
            $this->app->make(AccountSelfService::class),
            $this->app->make(DemoAccountPolicy::class),
        ));
        $this->app->bind(AdminApiAccessRegistry::class, function (): AdminApiAccessRegistry {
            $routes = Config::get('admin_api_access', []);
            return new AdminApiAccessRegistry(
                (int) Config::get('admin_api_access.version', 0),
                is_array($routes) ? $routes : [],
            );
        });
    }

    /** Wires the Edition-aware storage ledger to the host-configured Core drivers. */
    private function registerStorage(): void
    {
        $this->app->bind(StorageCredentialResolver::class, FailClosedStorageCredentialResolver::class);
        $this->app->bind(StorageDriverFactory::class, fn(): StorageDriverFactory => new StorageDriverFactory(
            $this->app->make(StorageCredentialResolver::class),
            new QiniuStorageHttpTransport($this->app->make(OutboundHttpTransport::class)),
            $this->app->make(AliyunStorageClientFactory::class),
            $this->app->make(QcloudStorageClientFactory::class),
            $this->app->make(CurrentExecutionContext::class),
            $this->app,
        ));
        $this->app->bind(StorageService::class, fn(): StorageService => new StorageService(
            $this->app->make(StorageDriverFactory::class),
            $this->app->make(DataScopePolicy::class),
            $this->app->make(DefaultTenantContextResolver::class),
            (string) Config::get('jwt.secret', ''),
            (string) $this->app->request->domain(),
            $this->app->make(\PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery::class),
            $this->app->make(\PeanutAdmin\Modules\File\Service\FileAuthorizationService::class),
            $this->app->make(CurrentExecutionContext::class),
            $this->app->make(AuditContractHost::class),
            $this->app->make(\PeanutAdmin\Modules\ContentTools\Service\ContentCapabilitiesService::class),
            $this->app->make(\PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry::class),
        ));
        $this->app->bind(FileService::class, fn(): FileService => new FileService(
            $this->app->make(StorageService::class),
            (string) $this->app->request->domain(),
        ));
        $this->app->bind(ProductAssetReferenceService::class, fn(): ProductAssetReferenceService => new ProductAssetReferenceService(
            $this->app->make(FileService::class),
            (string) $this->app->request->domain(),
        ));
        $this->app->bind(StorageConfigurationService::class, fn(): StorageConfigurationService => new StorageConfigurationService(
            $this->app->make(AuditContractHost::class),
        ));
    }

    private function registerPlatform(): void
    {
        $this->app->bind(OwnerInvitationDeliveryPort::class, UnavailableOwnerInvitationDeliveryPort::class);
        $this->app->bind(OwnerInvitationRuntimePolicy::class, fn(): OwnerInvitationRuntimePolicy =>
            OwnerInvitationRuntimePolicy::fromEnvironment(
                (string) Config::get('peanut.environment', ''),
                (string) Config::get('platform_invitation.delivery_mode', 'auto'),
            ));
        $this->app->bind(TenantEntryBindingResolver::class, function (): TenantEntryBindingResolver {
            $mode = DeploymentMode::fromConfiguredValue(Config::get('deployment.mode'));
            if (!$mode instanceof DeploymentMode) {
                throw new \RuntimeException('DEPLOYMENT_MODE_UNCONFIGURED');
            }
            $lookup = $mode === DeploymentMode::Standalone
                ? $this->app->make(DefaultTenantEntryBindingLookup::class)
                : $this->app->make(\PeanutAdmin\Modules\Identity\Tenancy\Infrastructure\ThinkPhpTenantEntryBindingLookup::class);
            return new TenantEntryBindingResolver(
                null,
                true,
                $lookup,
            );
        });
        $this->app->bind(ApplicationHostPolicy::class, fn(): ApplicationHostPolicy => new ApplicationHostPolicy(
            (string) Config::get('deployment.mode', ''),
            ApplicationHostPolicy::hostList((string) Config::get('deployment.platform_hosts', '')),
            $this->app->make(TenantEntryBindingResolver::class),
        ));
        $this->app->bind(DataScopePolicy::class, function (): DataScopePolicy {
            $mode = DeploymentMode::fromConfiguredValue(Config::get('deployment.mode'));
            if (!$mode instanceof DeploymentMode) {
                throw new \RuntimeException('DEPLOYMENT_MODE_UNCONFIGURED');
            }
            return new MultiTenantDataScopePolicy(
                $this->app->make(CurrentExecutionContext::class),
            );
        });
        $this->app->bind(TenantAuthRepository::class, ThinkPhpTenantAuthRepository::class);
        $this->app->bind(PlatformAuthRepository::class, ThinkPhpPlatformAuthRepository::class);
        $this->app->bind(PlatformAuthorizationRepository::class, ThinkPhpPlatformAuthorizationRepository::class);
        $this->app->bind(PlatformAuthService::class, fn(): PlatformAuthService => new PlatformAuthService(
            $this->app->make(PlatformAuthRepository::class),
            $this->app->make(PasswordHasher::class),
            $this->app->make(Clock::class),
            $this->app->make(TokenIssuer::class),
            $this->platformIdentifierHmacKey(),
        ));
        $this->app->bind(PlatformOperatorIdentityPort::class, CorePlatformOperatorIdentityPort::class);
        $this->app->bind(TenantOwnerAdminProvisioner::class, CoreTenantOwnerAdminProvisioner::class);
        $this->app->bind(DeployedTenantModuleRegistry::class, fn(): DeployedTenantModuleRegistry =>
            $this->app->make(ThinkPhpModuleGovernanceProvider::class)->registry());
        $this->app->bind(ModuleRuntimeRepository::class, ThinkPhpModuleRuntimeRepository::class);
        $this->app->bind(TenantModuleConfigurationService::class, fn(): TenantModuleConfigurationService => new TenantModuleConfigurationService(
            $this->app->make(DeployedTenantModuleRegistry::class)->compiled(),
            $this->app->make(OpisTenantModuleConfigValidator::class),
            $this->app->make(ModuleRuntimeRepository::class),
            $this->app->make(AuditService::class),
        ));
        $this->app->bind(TenantModuleManager::class, fn(): TenantModuleManager => new TenantModuleManager(
            $this->app->make(DeployedTenantModuleRegistry::class)->compiled(),
            new VerifiedTenantModuleRepository(
                new ThinkPhpModuleRuntimeRepository($this->app->make(CompiledModuleRegistry::class), true),
                $this->app->make(DeployedTenantModuleRegistry::class),
            ),
            $this->app->make(OpisTenantModuleConfigValidator::class),
            $this->app->make(ModuleComposition::class)->tenantHooks($this->app->make(DeployedTenantModuleRegistry::class)->compiled()),
        ));
        $this->app->bind(PluginCatalogSyncService::class, fn(): PluginCatalogSyncService =>
            new PluginCatalogSyncService(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(PlatformModuleRuntimeService::class, fn(): PlatformModuleRuntimeService =>
            new PlatformModuleRuntimeService(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->trustedModuleKeys(),
                $this->app->make(PluginRuntimeGovernanceService::class),
                $this->app->make(PluginCatalogSyncService::class),
                $this->app->make(ModuleCatalogApplier::class),
                $this->app->make(\PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::class),
                $this->app->make(DeploymentModuleRequestService::class),
                $this->app->make(PlatformModuleOperationExecutionService::class),
            ));
        $this->app->bind(PlatformPermissionChecker::class, PlatformOpsPermissionChecker::class);
        $this->app->bind(OpsTaskDispatcher::class, ThinkPhpOpsTaskDispatcher::class);
        $this->app->bind(MaintenanceWindowStore::class, ThinkPhpMaintenanceWindowStore::class);
        $this->app->bind(BackupRestoreProviderRegistry::class, fn(): BackupRestoreProviderRegistry =>
            new BackupRestoreProviderRegistry([new PairedBackupProvider()]));
        $this->app->bind(MaintenanceReasonRegistry::class, fn(): MaintenanceReasonRegistry =>
            new MaintenanceReasonRegistry([
                'planned-upgrade',
                'database-maintenance',
                'security-maintenance',
                'module-lifecycle',
            ]));
        $this->app->bind(ThinkPhpModuleGovernanceProvider::class, fn(): ThinkPhpModuleGovernanceProvider =>
            new ThinkPhpModuleGovernanceProvider(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->app->make(ModuleCatalogApplier::class),
                $this->app->make(CompiledModuleRegistry::class),
                PluginLockResolver::forRuntime(dirname(__DIR__), (string) $this->moduleConfiguration()['plugin_lock']),
            ));
        $this->app->bind(ModuleQualificationQuery::class, fn(): ModuleQualificationQuery => $this->app
            ->make(ThinkPhpModuleGovernanceProvider::class)
            ->qualification());
        $this->app->bind(PluginRuntimeGovernanceService::class, fn(): PluginRuntimeGovernanceService =>
            new PluginRuntimeGovernanceService(
                dirname(__DIR__),
                $this->moduleConfiguration(),
                $this->app->make(ModuleCatalogApplier::class),
                $this->app->make(\PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::class),
            ));
        $this->app->bind(DeploymentModuleRequestService::class, fn(): DeploymentModuleRequestService =>
            new DeploymentModuleRequestService(
                dirname(__DIR__, 2),
                $this->moduleConfiguration(),
                $this->trustedModuleKeys(),
                $this->app->make(PluginRuntimeGovernanceService::class),
                $this->app->make(ModuleCatalogApplier::class),
            ));
        $this->app->bind(PlatformUpgradeReadinessService::class, fn(): PlatformUpgradeReadinessService =>
            new PlatformUpgradeReadinessService(
                dirname(__DIR__, 2),
                $this->app->make(ThinkPhpModuleGovernanceProvider::class),
                $this->app->make(PlatformBackupCenterService::class),
                $this->app->make(MaintenanceService::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(ApplicationRuntimeStatusProvider::class, fn(): ApplicationRuntimeStatusProvider =>
            new ApplicationRuntimeStatusProvider(
                dirname(__DIR__, 2),
                $this->app->make(PlatformUpgradeReadinessService::class),
                $this->app->make(ThinkPhpModuleGovernanceProvider::class),
            ));
        $this->app->bind(RuntimeStatusProvider::class, ApplicationRuntimeStatusProvider::class);
        $this->app->bind(OpsStatusService::class, fn(): OpsStatusService => new OpsStatusService(
            $this->app->make(PlatformPermissionChecker::class),
            $this->app->make(RuntimeStatusProvider::class),
        ));
        $this->app->bind(PlatformProviderQualificationService::class, function (): PlatformProviderQualificationService {
            $contributors = [new StorageQualificationContributor(
                $this->platformIdentifierHmacKey(),
                $this->app->make(\PeanutAdmin\Modules\File\Contract\StorageQualificationQueries::class),
            )];
            $providerContributors = [
                'peanut.payment' => PaymentQualificationContributor::class,
                'peanut.notification' => NotificationQualificationContributor::class,
                'peanut.oauth' => OauthQualificationContributor::class,
            ];
            foreach ($this->app->make(CompiledModuleRegistry::class)->modules as $manifest) {
                $contributor = $providerContributors[$manifest->data['key']] ?? null;
                if ($contributor !== null) {
                    $contributors[] = new $contributor(
                        $this->platformIdentifierHmacKey(),
                        $this->app->make(AdminDirectoryQuery::class),
                        $this->app->make(\PeanutAdmin\Modules\Integration\Contract\ExternalBindingQualificationQueries::class),
                    );
                }
            }
            return new PlatformProviderQualificationService(
                $this->app->make(PlatformPermissionChecker::class),
                $contributors,
                $this->platformIdentifierHmacKey(),
            );
        });
        $this->app->bind(PlatformDiagnosticBundleService::class, function (): PlatformDiagnosticBundleService {
            $permissions = $this->app->make(PlatformPermissionChecker::class);
            return new PlatformDiagnosticBundleService(
                $permissions,
                fn(\DateTimeImmutable $since): RuntimeLogService => new RuntimeLogService(
                    $permissions,
                    new RuntimeLogProviderRegistry([
                        new PlatformAuditRuntimeLogProvider(
                            $since,
                            $this->app->make(PlatformAuditDiagnosticQuery::class),
                        ),
                    ]),
                    new SafeLogMessageCatalog([]),
                ),
                $this->app->make(OpsStatusService::class),
                $this->app->make(ThinkPhpModuleGovernanceProvider::class),
                $this->app->make(TaskDiagnosticQuery::class),
                $this->app->make(TenantAuditDiagnosticQuery::class),
                (string) Config::get('deployment.mode', ''),
                (bool) Config::get('app.app_debug', false),
            );
        });
        $this->app->bind(PlatformUpgradeExecutionService::class, fn(): PlatformUpgradeExecutionService =>
            new PlatformUpgradeExecutionService(
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                dirname(__DIR__, 2),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(PlatformModuleOperationExecutionService::class, fn(): PlatformModuleOperationExecutionService =>
            new PlatformModuleOperationExecutionService(
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                $this->app->make(DeploymentModuleRequestService::class),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(ThinkPhpUpgradeTaskExecutionService::class, fn(): ThinkPhpUpgradeTaskExecutionService =>
            new ThinkPhpUpgradeTaskExecutionService(
                $this->app->make(AuditContractHost::class),
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                $this->app->make(ThinkPhpMaintenanceWindowStore::class),
                dirname(__DIR__, 2),
                $this->app->make(BackupRestoreProviderRegistry::class),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformOperatorIdentityQuery::class),
            ));
        $this->app->bind(ThinkPhpModuleOperationTaskExecutionService::class, fn(): ThinkPhpModuleOperationTaskExecutionService =>
            new ThinkPhpModuleOperationTaskExecutionService(
                $this->app->make(AuditContractHost::class),
                $this->app->make(ThinkPhpOpsTaskDispatcher::class),
                $this->app->make(ThinkPhpMaintenanceWindowStore::class),
                $this->app->make(DeploymentModuleRequestService::class),
                $this->app->make(BackupRestoreProviderRegistry::class),
                $this->app->make(ApplicationRuntimeStatusProvider::class),
                $this->app->make(PlatformOperatorIdentityQuery::class),
                $this->app->make(PlatformPermissionChecker::class),
            ));
        $this->app->bind(CrontabCommandService::class, fn(): CrontabCommandService => new CrontabCommandService(
            (array) Config::get('console.commands', []),
            (array) Config::get('console.module_commands', []),
        ));
    }

    private function registerApplicationServices(): void
    {
        $this->app->bind(
            \app\platform\services\developer\DeveloperCenterCatalogService::class,
            fn(): \app\platform\services\developer\DeveloperCenterCatalogService =>
                new \app\platform\services\developer\DeveloperCenterCatalogService(
                    dirname(__DIR__),
                    $this->app->make(CompiledModuleRegistry::class),
                ),
        );
        $this->app->bind(WorkbenchApplicationService::class, fn(): WorkbenchApplicationService => new WorkbenchApplicationService(
            $this->app->make(AdminAuthorizationService::class),
            $this->app->make(FileService::class),
            $this->app->make(\PeanutAdmin\Modules\Settings\Service\WebsiteConfigService::class),
            (string) Config::get('project.version', ''),
            (string) Config::get('project.based', ''),
            (array) Config::get('project.default_image', []),
            (bool) Config::get('peanut.demo.enabled', false),
        ));
        $this->app->bind(ConfigApplicationService::class, fn(): ConfigApplicationService => new ConfigApplicationService(
            $this->app->make(\PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings::class),
            $this->app->make(FileService::class),
            $this->app->make(\app\common\services\RichTextResourceService::class),
            $this->app->make(\PeanutAdmin\Modules\Settings\Service\WebsiteConfigService::class),
            (string) Config::get('project.default_image.user_avatar', ''),
        ));
        $this->app->bind(GeneratorService::class, fn(): GeneratorService => new GeneratorService(
            $this->app->make(GeneratorImportPersistence::class),
            $this->app->make(ThinkPhpGeneratorMetadata::class),
            $this->databasePrefix(),
        ));
        $this->app->bind(IndexApplicationService::class, fn(): IndexApplicationService => new IndexApplicationService(
            $this->app->make(TenantIdentityQuery::class),
            $this->app->make(\PeanutAdmin\Modules\Settings\Contract\TenantApplicationSettings::class),
            $this->app->make(\app\common\services\RichTextResourceService::class),
            $this->app->make(\app\common\services\decoration\DecorationReadService::class),
            $this->app->make(\PeanutAdmin\Modules\Settings\Service\WebsiteConfigService::class),
            (string) Config::get('project.version', ''),
            [
                'enabled' => $this->app->make(DemoAccountPolicy::class)->enabled(),
                'tenant_a_host' => (string) Config::get('peanut.demo.tenant_a_host', ''),
                'tenant_b_host' => (string) Config::get('peanut.demo.tenant_b_host', ''),
                'tenant_a_email' => (string) Config::get('peanut.demo.tenant_a_email', ''),
                'tenant_b_email' => (string) Config::get('peanut.demo.tenant_b_email', ''),
                'password' => (string) Config::get('peanut.demo.shared_password', ''),
            ],
            $this->app->bound(PublicArticleQueries::class) ? $this->app->make(PublicArticleQueries::class) : null,
        ));
    }

    public function boot(): void
    {
        $policy = $this->app->make(DataScopePolicy::class);
        if (!$policy instanceof DataScopePolicy) {
            throw new \LogicException('DATA_SCOPE_POLICY_UNAVAILABLE');
        }
        Model::maker(static function (Model $model) use ($policy): void {
            if ($model instanceof TenantOwnedModel) {
                $model->setDataScopePolicy($policy);
            }
        });
    }

    private function databasePrefix(): string
    {
        $connection = (string) Config::get('database.default', 'mysql');
        return (string) Config::get('database.connections.' . $connection . '.prefix', '');
    }

    private function registerModules(): void
    {
        // Recovery must boot while a pending source journal deliberately blocks the Module resolver.
        if ($this->app->runningInConsole()
            && ($_SERVER['argv'][1] ?? null) === 'module:adopt-package'
            && Config::get('peanut.environment', '') === 'development') {
            return;
        }
        // Source authoring repairs stale artifacts; it must not load Providers before it rewrites them.
        if ($this->app->runningInConsole()
            && PHP_SAPI === 'cli'
            && Config::get('peanut.environment', '') === 'development'
            && $this->app->isDebug()
            && in_array((new \think\console\Input())->getFirstArgument(), ['plugin:make', 'plugin:lock'], true)) {
            return;
        }
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new \RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }
        $serverRoot = dirname(__DIR__);
        $lockPath = trim((string) ($config['plugin_lock'] ?? ''));
        if ($lockPath === '') {
            throw new \RuntimeException('PLUGIN_LOCK_INVALID');
        }
        $releasePath = $serverRoot . '/.peanut/release-identity.json';
        if (\app\common\value\installation\ApplicationSourceIdentity::hasRuntime($serverRoot)
            && Config::get('peanut.environment', '') !== 'development') {
            throw new \RuntimeException('APPLICATION_DEVELOPMENT_RUNTIME_ENVIRONMENT_INVALID');
        }
        $developmentReadonly = \app\common\value\installation\ApplicationSourceIdentity::hasRuntime($serverRoot)
            && \app\common\infrastructure\installation\ReadonlyHttpMount::active($serverRoot);
        $factory = new ModuleDefinitionRegistryFactory($serverRoot);
        if (!$this->app->runningInConsole()) {
            $registry = file_exists($releasePath) || is_link($releasePath)
                || \app\common\infrastructure\installation\ReadonlyHttpMount::active($serverRoot)
                ? $this->app->make(\app\common\infrastructure\installation\VerifiedServerDeployment::class)->registry()
                : $factory->fromCompiled($config);
        } else {
            $resolver = PluginLockResolver::forRuntime($serverRoot, $lockPath);
            $registry = $factory->fromPluginLock($resolver, $config, false);
            if (PHP_SAPI === 'cli' && in_array((new \think\console\Input())->getFirstArgument(), ['run', 'module:compile'], true)
                && !file_exists($releasePath) && !is_link($releasePath) && !$developmentReadonly) {
                $factory->publishCompiled($registry, $config, $resolver->all());
            }
        }
        foreach ($registry->modules as $manifest) {
            \app\common\module\PackageCheck::assertEdition($manifest->root, (string) Config::get('deployment.mode', ''));
        }
        $this->app->instance(CompiledModuleRegistry::class, $registry);
        $this->app->make(ModuleComposition::class)->register($registry);
        $moduleCommands = [
            'peanut.workflow' => ['workflow:install-managed' => \app\command\WorkflowInstallManaged::class],
            'peanut.import-export' => ['import-export:expire' => \PeanutAdmin\Modules\ImportExport\Console\ExpireOperationsCommand::class],
            'peanut.payment' => ['refund:reconcile' => \app\command\RefundReconcile::class],
        ];
        $commands = (array) Config::get('console.commands', []);
        $owners = (array) Config::get('console.module_commands', []);
        foreach ($registry->modules as $manifest) {
            foreach ($moduleCommands[$manifest->data['key']] ?? [] as $name => $command) {
                $commands[$name] = $command;
                if ($name === 'refund:reconcile') {
                    $owners[$name] = $manifest->data['key'];
                }
            }
        }
        Config::set(['commands' => $commands, 'module_commands' => $owners], 'console');
    }

    /** @return array<string,mixed> */
    private function moduleConfiguration(): array
    {
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new \RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }
        return $config;
    }

    /** @return array<string,string> */
    private function trustedModuleKeys(): array
    {
        $trustedKeys = [];
        foreach ((array) Config::get('module_packages.trusted_ed25519_keys', []) as $keyId => $encoded) {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (is_string($keyId) && is_string($decoded)
                && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $trustedKeys[$keyId] = $decoded;
            }
        }
        return $trustedKeys;
    }

    private function platformIdentifierHmacKey(): string
    {
        $key = trim((string) Config::get('platform_auth.identifier_hmac_key', ''));
        // 平台认证标识依赖专用 HMAC 密钥；缺失或过短时必须在装配边界拒绝。
        if (strlen($key) < 32) {
            throw new \DomainException('PLATFORM_AUTH_CONFIGURATION_UNAVAILABLE');
        }
        return $key;
    }

}
