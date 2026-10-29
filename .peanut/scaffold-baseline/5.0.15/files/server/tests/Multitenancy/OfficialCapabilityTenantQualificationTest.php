<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/route/registry_source.php';

function qualificationExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function qualificationSource(string $root, string $relative): string
{
    $source = file_get_contents($root . '/' . $relative);
    qualificationExpect(is_string($source), 'qualification source is unavailable: ' . $relative);
    return $source;
}

$root = dirname(__DIR__, 2);
$sources = [];
foreach ([
    'app_service' => 'app/AppService.php',
    'default_context_module' => 'app/modules/official/identity/src/Tenancy/DefaultTenantContextResolver.php',
    'entry_binding_core' => 'vendor/peanut-admin/core/kernel/src/Tenancy/TenantEntryBindingResolver.php',
    'entry_binding_lookup' => 'app/modules/official/identity/src/Tenancy/Infrastructure/ThinkPhpTenantEntryBindingLookup.php',
    'standalone_entry_lookup' => 'app/common/tenancy/DefaultTenantEntryBindingLookup.php',
    'entry_schema' => 'database/init.sql',
    'public_tenant_module_middleware' => 'app/api/middleware/PublicTenantModuleMiddleware.php',
    'current_execution_context' => 'app/common/execution/CurrentExecutionContext.php',
    'execution_context_store' => 'app/common/execution/ExecutionContextStore.php',
    'tenant_session' => 'app/adminapi/controller/auth/TenantSessionController.php',
    'tenant_session_application' => 'app/adminapi/services/auth/TenantSessionApplicationService.php',
    'admin_login' => 'app/adminapi/controller/auth/LoginController.php',
    'admin_login_application' => 'app/adminapi/services/auth/LoginApplicationService.php',
    'authenticated_member_context_core' => 'vendor/peanut-admin/core/kernel/src/Context/AuthenticatedMemberContext.php',
    'member_context' => 'app/common/infrastructure/member/MemberApiTenantContextResolver.php',
    'member_subject_lookup' => 'app/modules/official/member/src/Infrastructure/Persistence/ThinkPhpMemberSubjectLookup.php',
    'member_middleware' => 'app/api/middleware/CheckTokenMiddleware.php',
    'file_model' => 'app/modules/official/file/src/Model/File.php',
    'file_namespace_core' => 'vendor/peanut-admin/core/file-media/src/Storage/TenantObjectNamespace.php',
    'article_model' => 'app/modules/official/article/src/Model/Article.php',
    'decoration_model' => 'app/common/model/decoration/DecoratePage.php',
    'notice_model' => 'app/modules/official/notification/src/Model/NoticeLog.php',
    'oauth_repository' => 'app/modules/official/oauth/src/Infrastructure/Persistence/ThinkPhpOAuthPersistence.php',
    'oauth_attempt_model' => 'app/modules/official/oauth/src/Model/OAuthAttempt.php',
    'oauth_completion_model' => 'app/modules/official/oauth/src/Model/OAuthCompletionTicket.php',
    'oauth_identity_model' => 'app/modules/official/oauth/src/Model/OAuthIdentity.php',
    'oauth_principal_model' => 'app/modules/official/oauth/src/Model/OAuthPrincipal.php',
    'oauth_queries' => 'app/modules/official/oauth/src/Service/OAuthQueryService.php',
    'oauth_module_provider' => 'app/modules/official/oauth/src/ModuleProvider.php',
    'external_resolver_module' => 'app/modules/official/integration/src/Service/ExternalTenantResolver.php',
    'integration_module_provider' => 'app/modules/official/integration/src/ModuleProvider.php',
    'external_binding_adapter' => 'app/modules/official/integration/src/Infrastructure/ThinkPhpExternalTenantBindingRepository.php',
    'external_audit_adapter' => 'app/modules/official/integration/src/Infrastructure/ThinkPhpExternalTenantAudit.php',
    'finance_model' => 'app/modules/official/payment/src/Model/RefundRecord.php',
    'recharge_settings' => 'app/modules/official/payment/src/Service/RechargeTenantSettingService.php',
    'tenant_settings' => 'app/modules/official/settings/src/Service/TenantSettingService.php',
    'tenant_settings_provider' => 'app/modules/official/settings/src/Infrastructure/ThinkPhpTenantSettingsProvider.php',
    'settings_module_provider' => 'app/modules/official/settings/src/ModuleProvider.php',
    'application_tenant_bootstrap' => 'app/platform/services/ApplicationTenantBootstrapService.php',
    'tenant_application_settings' => 'app/modules/official/settings/src/Service/TenantApplicationSettingService.php',
    'notice_channel' => 'app/common/services/notice/NoticeChannelService.php',
    'platform_storage' => 'app/modules/official/identity/src/Platform/InstanceControlPlanePolicy.php',
    'platform_storage_controller' => 'app/platform/controller/PlatformStorageController.php',
    'file_module_provider' => 'app/modules/official/file/src/ModuleProvider.php',
    'admin_permissions' => 'app/common/services/authorization/AdminAuthorizationService.php',
    'crontab_scheduler' => 'app/modules/official/task/src/Service/CrontabSchedulerService.php',
    'crontab_model' => 'app/modules/official/task/src/Model/Crontab.php',
    'crontab_task_definition' => 'app/modules/official/task/src/Service/CrontabTaskDefinition.php',
    'scheduled_context_core' => 'vendor/peanut-admin/core/kernel/src/Tenancy/ScheduledTenantContext.php',
    'tenant_scope_core' => 'vendor/peanut-admin/core/kernel/src/Tenancy/TenantScope.php',
    'async_authorization' => 'app/modules/official/import_export/src/Infrastructure/Authorization/AdminAsyncAuthorization.php',
    'async_runtime' => 'app/modules/official/import_export/src/Service/TaskImportExportRuntime.php',
    'async_module_provider' => 'app/modules/official/import_export/src/ModuleProvider.php',
    'async_worker_definition' => 'app/modules/official/import_export/src/Service/ImportExportTaskWorkerDefinition.php',
    'async_files' => 'app/modules/official/import_export/src/Infrastructure/File/AppFileMediaGateway.php',
    'storage_path' => 'app/modules/official/file/src/Value/Storage/StoragePath.php',
    'routes' => 'route/registry_source.php',
    'official_file_routes' => 'app/modules/official/file/route/app.php',
    'official_notification_routes' => 'app/modules/official/notification/route/app.php',
    'official_oauth_routes' => 'app/modules/official/oauth/route/app.php',
    'official_payment_routes' => 'app/modules/official/payment/route/app.php',
    'official_member_routes' => 'app/modules/official/member/route/app.php',
    'official_task_routes' => 'app/modules/official/task/route/app.php',
    'official_import_export_routes' => 'app/modules/official/import_export/route/app.php',
    'official_module_middleware' => 'app/common/infrastructure/module/OfficialModuleMiddleware.php',
    'module_execution_boundary' => 'app/common/infrastructure/module/ModuleExecutionBoundary.php',
    'module_execution_context_core' => 'vendor/peanut-admin/core/kernel/src/Module/ModuleExecutionContext.php',
    'module_guard_core' => 'vendor/peanut-admin/core/kernel/src/Module/ModuleGuard.php',
    'oauth_controller' => 'app/api/controller/OAuthController.php',
    'payment_notify_controller' => 'app/api/controller/PaymentNotifyController.php',
    'official_account_controller' => 'app/api/controller/OfficialAccountController.php',
    'oauth_application' => 'app/api/services/OAuthApplicationService.php',
    'payment_callback_application' => 'app/api/services/PaymentCallbackApplicationService.php',
    'official_account_application' => 'app/api/services/OfficialAccountApplicationService.php',
    'module_worker' => 'app/common/infrastructure/async/ModuleAwareTaskHandler.php',
    'console' => 'config/console.php',
    'module_manifest' => 'vendor/peanut-admin/core/kernel/src/Module/ManifestLoader.php',
    'module_compiled_registry' => 'vendor/peanut-admin/core/kernel/src/Module/CompiledModuleRegistry.php',
    'module_availability' => 'vendor/peanut-admin/core/kernel/src/Host/ModuleAvailabilityAdapter.php',
    'deployed_module_registry' => 'app/platform/infrastructure/module/DeployedTenantModuleRegistry.php',
    'fixture_module_access' => 'app/modules/fixture/delivery_record/src/Infrastructure/Authorization/ThinkPhpDeliveryRecordAccess.php',
    'official_article_manifest' => 'app/modules/official/article/module.json',
    'official_article_public' => 'app/api/middleware/PublicTenantModuleMiddleware.php',
    'member_token' => 'app/api/services/UserTokenService.php',
    'jwt_config' => 'config/jwt.php',
    'menu_controller' => 'app/adminapi/controller/auth/MenuController.php',
    'system_controller' => 'app/adminapi/controller/system/SystemController.php',
] as $key => $relative) {
    $sources[$key] = $key === 'routes'
        ? peanut_route_registry_source($root)
        : qualificationSource($root, $relative);
}

foreach (['file_model', 'article_model', 'decoration_model', 'notice_model', 'finance_model', 'crontab_model'] as $key) {
    qualificationExpect(
        str_contains($sources[$key], 'extends TenantOwnedModel'),
        $key . ' lost its Tenant-owned Model scope',
    );
}
foreach (['oauth_attempt_model', 'oauth_completion_model', 'oauth_identity_model', 'oauth_principal_model'] as $key) {
    qualificationExpect(
        str_contains($sources[$key], 'extends TenantOwnedModel'),
        $key . ' lost its Tenant-owned ORM scope',
    );
}
qualificationExpect(
    !str_contains($sources['oauth_repository'], "where('tenant_id'")
        && !str_contains($sources['oauth_repository'], 'where("tenant_id"')
        && !str_contains($sources['oauth_repository'], 'withoutGlobalScope')
        && str_contains($sources['oauth_repository'], 'OAuthAttempt::')
        && str_contains($sources['oauth_repository'], 'OAuthCompletionTicket::')
        && str_contains($sources['oauth_repository'], 'OAuthIdentity::')
        && str_contains($sources['oauth_repository'], 'OAuthPrincipal::'),
    'oauth persistence bypassed Tenant-owned ORM models',
);
qualificationExpect(
    str_contains($sources['storage_path'], 'TenantObjectNamespace::directory')
        && str_contains($sources['file_namespace_core'], "sprintf('tenants/v1/%d/%s'")
        && str_contains($sources['file_namespace_core'], "sprintf('tenants/v1/%d/'")
        && str_contains($sources['file_namespace_core'], "str_contains(\$relativeDirectory, '..')")
        && str_contains($sources['file_namespace_core'], 'assertTenantId($tenantId)'),
    'file objects lost the Core-owned Tenant namespace',
);
qualificationExpect(
    str_contains($sources['async_files'], "'export.csv'")
        && str_contains($sources['async_files'], '->storePath(')
        && str_contains($sources['storage_path'], 'TenantObjectNamespace::directory'),
    'async exports lost private Tenant namespace',
);
qualificationExpect(
    str_contains($sources['app_service'], 'use PeanutAdmin\\Modules\\Identity\\Tenancy\\DefaultTenantContextResolver;')
        && str_contains($sources['public_tenant_module_middleware'], 'RequestTrace::id($this->executionContext, $request, \'public\')')
        && !is_file($root . '/app/common/service/tenant/DefaultTenantContextResolver.php')
        && str_contains($sources['default_context_module'], "Tenant::where('code', 'default')->where('status', 'active')")
        && str_contains($sources['default_context_module'], "->limit(2)->column('id')")
        && str_contains($sources['default_context_module'], 'count($ids) !== 1')
        && str_contains($sources['default_context_module'], 'DEFAULT_TENANT_CONTEXT_UNAVAILABLE')
        && !str_contains($sources['default_context_module'], 'default_tenant_bootstrap'),
    'anonymous default-Tenant resolution does not fail closed',
);
qualificationExpect(
    str_contains($sources['member_context'], 'MemberSubjectLookup')
        && str_contains($sources['member_context'], "where('status', 'active')")
        && str_contains($sources['member_subject_lookup'], "where('status', 1)")
        && str_contains($sources['member_subject_lookup'], "whereNull('delete_time')")
        && str_contains($sources['member_subject_lookup'], "getData('tenant_id')")
        && str_contains($sources['member_middleware'], 'MemberApiTenantContextResolver'),
    'member JWT ownership does not establish an active trusted Tenant context',
);
qualificationExpect(
    str_contains($sources['member_context'], 'use PeanutAdmin\\Kernel\\Context\\AuthenticatedMemberContext;')
        && str_contains($sources['member_context'], 'return new AuthenticatedMemberContext(')
        && !is_file($root . '/app/common/service/member/AuthenticatedMemberContext.php')
        && str_contains($sources['authenticated_member_context_core'], 'public readonly int $memberId')
        && str_contains($sources['authenticated_member_context_core'], 'MEMBER_TENANT_CONTEXT_UNAVAILABLE')
        && !str_contains($sources['authenticated_member_context_core'], 'accountId')
        && !str_contains($sources['authenticated_member_context_core'], 'tenantMember')
        && !str_contains($sources['authenticated_member_context_core'], 'PeanutAdmin\\Kernel\\Auth'),
    'application-member identity is mixed with Core Account or TenantMember identity',
);
qualificationExpect(
    str_contains($sources['member_context'], 'new AuthenticatedMemberContext(')
        && !str_contains($sources['member_context'], 'ValidatedTenantSession')
        && !str_contains($sources['member_context'], 'TenantContext::fromValidatedSession')
        && str_contains($sources['member_middleware'], '\app\common\execution\ConsumerExecutionContext::member(')
        && str_contains($sources['member_middleware'], 'ExecutionContextStore')
        && !str_contains($sources['member_middleware'], '$request->authenticatedMemberContext =')
        && !str_contains($sources['member_middleware'], '$request->tenantContext = $this->tenantContexts()'),
    'member JWT context is still written into the Core TenantContext request boundary',
);
qualificationExpect(
    str_contains($sources['entry_schema'], 'pa_tenant_entry_binding')
        && str_contains($sources['entry_schema'], 'fk_tenant_entry_binding_tenant')
        && str_contains($sources['app_service'], 'use PeanutAdmin\\Kernel\\Tenancy\\TenantEntryBindingResolver;')
        && !is_file($root . '/app/common/service/tenant/TenantEntryBindingResolver.php')
        && str_contains($sources['app_service'], 'DefaultTenantContextResolver::class')
        && str_contains($sources['app_service'], 'DeploymentMode::Standalone')
        && !str_contains($sources['app_service'], 'deployment.public_default_tenant_fallback')
        && preg_match('/new TenantEntryBindingResolver\(\s*null,\s*true,\s*\$lookup,?\s*\)/', $sources['app_service']) === 1
        && str_contains($sources['app_service'], 'DefaultTenantEntryBindingLookup::class')
        && str_contains($sources['app_service'], 'ThinkPhpTenantEntryBindingLookup::class')
        && str_contains($sources['standalone_entry_lookup'], '$this->defaultTenant->system(')
        && str_contains($sources['standalone_entry_lookup'], "'tenant_code' => 'default'")
        && str_contains($sources['entry_binding_lookup'], "where('binding.host', \$host)")
        && str_contains($sources['entry_binding_lookup'], "where('binding.client_key', \$clientKey)")
        && str_contains($sources['entry_binding_lookup'], 'count($rows) !== 1')
        && str_contains($sources['entry_binding_lookup'], "binding_status'] ?? null) !== 'active'")
        && str_contains($sources['entry_binding_lookup'], "tenant_status'] ?? null) !== 'active'")
        && str_contains($sources['entry_binding_core'], '$this->lookup->binding($host, $clientKey)')
        && str_contains($sources['entry_binding_core'], 'TENANT_ENTRY_BINDING_CONFLICT'),
    'Tenant entry bindings are not instance-owned and active-Tenant scoped',
);
qualificationExpect(
    str_contains($sources['tenant_session'], 'TenantSessionApplicationService')
        && str_contains($sources['tenant_session_application'], 'loginTenantCode(')
        && str_contains($sources['admin_login'], '$this->loginApplication->login(')
        && str_contains($sources['admin_login_application'], 'loginTenantCode(')
        && str_contains($sources['public_tenant_module_middleware'], '$this->entryBindings->system(')
        && !str_contains($sources['public_tenant_module_middleware'], 'DefaultTenantContextResolver::system('),
    'Admin or anonymous member authentication bypasses Tenant entry resolution',
);
qualificationExpect(
    str_contains($sources['routes'], "Route::get('search/hotLists'")
        && str_contains($sources['routes'], "PublicTenantModuleMiddleware::class, 'peanut.hot-search.public-read', '', 'hot-search.lists'")
        && str_contains($sources['routes'], "Route::get('index/policy'")
        && substr_count($sources['routes'], "PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read'") >= 6,
    'public hot-search or policy route is missing a Host-bound Tenant guard',
);
qualificationExpect(
    str_contains($sources['integration_module_provider'], 'use PeanutAdmin\\Modules\\Integration\\Contract\\ExternalTenantAudit;')
        && str_contains($sources['integration_module_provider'], 'ExternalTenantAudit::class => ThinkPhpExternalTenantAudit::class')
        && str_contains($sources['integration_module_provider'], 'ExternalTenantBindingRepository::class => ThinkPhpExternalTenantBindingRepository::class')
        && str_contains($sources['integration_module_provider'], 'ExternalTenantResolutionService::class => ExternalTenantResolver::class')
        && str_contains($sources['oauth_module_provider'], 'use PeanutAdmin\\Modules\\Integration\\Contract\\ExternalTenantResolutionService;')
        && str_contains($sources['oauth_module_provider'], '$app->make(ExternalTenantResolutionService::class)')
        && str_contains($sources['external_binding_adapter'], 'implements ExternalTenantBindingRepository')
        && str_contains($sources['external_audit_adapter'], 'implements ExternalTenantAudit')
        && str_contains($sources['external_resolver_module'], '!$binding->tenantActive')
        && str_contains($sources['external_resolver_module'], 'count($bindings) !== 1')
        && str_contains($sources['external_resolver_module'], '!$binding->active')
        && str_contains($sources['external_resolver_module'], '!hash_equals($provider, $binding->provider)')
        && str_contains($sources['external_resolver_module'], "\$this->audit->record('rejected'"),
    'external callbacks do not reject ambiguous or suspended Tenant ownership',
);
foreach (['ExternalTenantAudit.php', 'ExternalTenantBinding.php', 'ExternalTenantBindingRepository.php', 'ExternalTenantResolution.php', 'ExternalTenantResolver.php'] as $bridge) {
    qualificationExpect(
        !is_file($root . '/app/common/service/external/' . $bridge),
        'external callback consumption restored an application mirror bridge: ' . $bridge,
    );
}
qualificationExpect(
    str_contains($sources['oauth_queries'], 'wechatSubjectForMember($context, $memberId, $terminal)')
        && str_contains($sources['oauth_repository'], "'member_id' => \$memberId")
        && str_contains($sources['oauth_repository'], 'OAuthIdentity::where('),
    'OAuth subject lookup is not explicitly bound to the member Tenant context',
);
qualificationExpect(
    str_contains($sources['crontab_scheduler'], "where('t.status', 'active')")
        && str_contains($sources['crontab_scheduler'], 'use PeanutAdmin\\Kernel\\Tenancy\\TenantScope;')
        && !str_contains($sources['crontab_scheduler'], 'Console::call')
        && str_contains($sources['crontab_task_definition'], 'ScheduledTenantContext::run')
        && str_contains($sources['crontab_task_definition'], 'RetryableTaskException')
        && str_contains($sources['scheduled_context_core'], 'finally')
        && str_contains($sources['scheduled_context_core'], 'self::$scope = null')
        && str_contains($sources['scheduled_context_core'], "throw new \\RuntimeException('Scheduled TenantContext is required')")
        && str_contains($sources['tenant_scope_core'], 'fromTrustedContext(')
        && str_contains($sources['tenant_scope_core'], 'private function __construct('),
    'scheduled work does not re-establish active Tenant ownership',
);
qualificationExpect(
    str_contains($sources['async_authorization'], 'AdminAuthorizationService')
        && str_contains($sources['async_authorization'], '->principal($context)')
        && str_contains($sources['async_authorization'], '->authorizedOperation(')
        && str_contains($sources['async_authorization'], 'envelope->resourceKey')
        && str_contains($sources['async_authorization'], 'envelope->operation')
        && str_contains($sources['async_authorization'], 'envelope->requestedTargets'),
    'async work does not recheck Tenant availability and authorization',
);
qualificationExpect(
    str_contains($sources['notice_channel'], "private const BINDING_PROVIDER = 'notice.sms'")
        && str_contains($sources['notice_channel'], '$this->bindings->mutate(')
        && str_contains($sources['notice_channel'], "'tenant:' . \$tenantId")
        && str_contains($sources['notice_channel'], '$this->resolver')
        && !str_contains($sources['notice_channel'], 'ConfigService'),
    'notification Provider configuration is not Tenant-owned',
);
qualificationExpect(
    str_contains($sources['tenant_settings'], 'implements TenantSettingsQuery, TenantSettingsCommands')
        && str_contains($sources['tenant_settings_provider'], "where('tenant_id', \$tenantId)")
        && str_contains($sources['tenant_settings_provider'], "where('namespace', \$namespace)")
        && str_contains($sources['recharge_settings'], 'private readonly TenantSettingsQuery $settingsQuery')
        && str_contains($sources['recharge_settings'], 'private readonly TenantSettingsCommands $settingsCommands')
        && str_contains($sources['recharge_settings'], '$this->settingsQuery->get($context, self::NAMESPACE')
        && str_contains($sources['recharge_settings'], '$this->settingsCommands->replace($context, self::NAMESPACE')
        && str_contains($sources['recharge_settings'], 'private readonly PaymentChannelGrantCommands $channelGrants')
        && str_contains($sources['settings_module_provider'], 'TenantSettingsQuery::class => TenantSettingService::class')
        && str_contains($sources['settings_module_provider'], 'TenantSettingsCommands::class => TenantSettingService::class')
        && str_contains($sources['application_tenant_bootstrap'], 'private TenantSettingService $tenantSettings')
        && str_contains($sources['application_tenant_bootstrap'], '$this->tenantSettings->get($context, $namespace)')
        && str_contains($sources['application_tenant_bootstrap'], '$this->tenantSettings->replace($context, $namespace, $document)')
        && !str_contains($sources['application_tenant_bootstrap'], 'TenantSettingsBootstrapRuntimeFactory')
        && !str_contains($sources['application_tenant_bootstrap'], 'PdoTenantSettingsBootstrapProvider')
        && str_contains($sources['recharge_settings'], '$this->channelGrants->channelConfigured('),
    'recharge policy or payment channel configuration is not Tenant-owned',
);
foreach (['agreement', 'site-statistics', 'member-profile', 'login', 'web-page', 'hot-search'] as $namespace) {
    qualificationExpect(
        str_contains($sources['tenant_application_settings'], "'{$namespace}'"),
        'Tenant application setting namespace is missing: ' . $namespace,
    );
}
qualificationExpect(
    str_contains($sources['member_token'], "'aud'")
        && str_contains($sources['member_token'], "'sub'")
        && str_contains($sources['member_token'], 'strlen($this->secret) < 32')
        && !str_contains($sources['jwt_config'], 'peanut-admin-change-this-in-production')
        && !str_contains($sources['member_token'], 'peanut-admin-secret-key'),
    'member JWT is missing key strength, lifetime, issuer/audience, or subject validation',
);
qualificationExpect(
    str_contains($sources['menu_controller'], 'instanceMenuDenial()')
        && str_contains($sources['system_controller'], 'instanceToolAccessDenial()'),
    'Tenant Admin can still reach an instance-global control plane',
);
qualificationExpect(
    !is_file($root . '/app/adminapi/controller/setting/StorageController.php'),
    'retired Tenant Admin storage controller remains available for accidental route registration',
);
qualificationExpect(
    str_contains($sources['platform_storage_controller'], 'protected string $storageClass = StorageConfiguration::class')
        && str_contains($sources['file_module_provider'], 'StorageConfiguration::class => StorageConfigurationService::class')
        && str_contains($sources['platform_storage_controller'], '$this->platformContext === null')
        && str_contains($sources['admin_permissions'], 'use PeanutAdmin\\Modules\\Identity\\Platform\\InstanceControlPlanePolicy;')
        && str_contains($sources['admin_permissions'], 'InstanceControlPlanePolicy::isTenantAdminRoute')
        && str_contains($sources['admin_permissions'], 'InstanceControlPlanePolicy::tenantAdminPermissions()')
        && !is_file($root . '/app/common/service/platform/InstanceControlPlanePolicy.php')
        && str_contains($sources['routes'], 'infrastructure/storage')
        && !str_contains($sources['routes'], "Route::post('storage/setup'"),
    'instance storage control remains reachable from a Tenant Admin audience',
);
foreach ([
    'app/common/service/member/MemberTenantContext.php',
    'app/common/service/article/ArticleTenantContext.php',
    'app/common/service/file/FileTenantContext.php',
    'app/common/service/oauth/OAuthTenantContext.php',
    'app/common/service/hot_search/HotSearchTenantContext.php',
    'app/common/service/decoration/DecorationTenantContext.php',
    'app/common/service/finance/FinanceTenantContext.php',
    'app/common/service/org/OrgTenantContext.php',
    'app/common/service/dict/DictTenantContext.php',
] as $retiredContext) {
    qualificationExpect(
        !is_file($root . '/' . $retiredContext),
        'retired domain-specific Tenant context was reintroduced: ' . $retiredContext,
    );
}
qualificationExpect(
    str_contains($sources['current_execution_context'], 'function tenantAdmin(): TenantContext')
        && str_contains($sources['current_execution_context'], 'function member(): AuthenticatedMemberContext')
        && str_contains($sources['execution_context_store'], 'function run(ExecutionContext $context, callable $operation)'),
    'typed execution context boundary is missing',
);
foreach ([
    'official_member_routes' => [
        "PublicTenantModuleMiddleware::class, 'peanut.member.public-auth', 'official.member', 'member.register'",
        "PublicTenantModuleMiddleware::class, 'peanut.member.public-auth', 'official.member', 'member.login'",
    ],
    'official_notification_routes' => [
        "PublicTenantModuleMiddleware::class, 'peanut.notice.verification', 'official.notification', 'notice.verification.send'",
    ],
] as $sourceKey => $routeGuards) {
    foreach ($routeGuards as $routeGuard) {
        qualificationExpect(
            str_contains($sources[$sourceKey], $routeGuard),
            'public Tenant guard is missing: ' . $routeGuard,
        );
    }
}
qualificationExpect(
    str_contains($sources['official_member_routes'], "'peanut.notice.verification', 'official.notification'")
        && str_contains($sources['official_member_routes'], 'notice.verification.verify'),
    'member password/mobile flows are missing the Tenant-owned notification guard',
);

$matrix = [
    'file_media' => ['trusted_context' => true, 'sql_scope' => true, 'non_sql_namespace' => true],
    'article_content_decoration' => ['trusted_context' => true, 'sql_scope' => true, 'suspended_tenant_denied' => true],
    'member_crm' => ['trusted_context' => true, 'sql_scope' => true, 'suspended_tenant_denied' => true],
    'notice_oauth' => ['trusted_context' => true, 'sql_scope' => true, 'external_owner_active' => true],
    'payment_recharge_refund_callbacks' => ['trusted_context' => true, 'sql_scope' => true, 'external_owner_active' => true],
    'crontab_task_import_export' => ['trusted_context' => true, 'sql_scope' => true, 'non_sql_namespace' => true, 'execution_recheck' => true],
    'tenant_module' => [
        'official_capabilities_are_enableable_modules' => true,
        'reason' => 'Shared engines stay in Core, while each official business entry is packaged and Tenant enableable.',
        'optional_module_manifest_required' => true,
        'optional_module_enable_guard_required' => true,
        'optional_module_guard_owner' => 'peanut-admin/core ModuleGuard plus permission catalog',
    ],
    'official_article_module' => [
        'manifest' => true,
        'plugin_installation_guard' => true,
        'tenant_module_guard' => true,
        'tenant_sql_scope' => true,
        'host_bound_public_tenant' => true,
    ],
];

foreach (array_diff(array_keys($matrix), ['tenant_module', 'official_article_module']) as $capability) {
    qualificationExpect(
        ($matrix[$capability]['trusted_context'] ?? false) === true
            && ($matrix[$capability]['sql_scope'] ?? false) === true,
        'official capability is not mandatorily Tenant-scoped: ' . $capability,
    );
}
qualificationExpect(
    str_contains($sources['official_article_manifest'], '"key": "official.article"')
        && str_contains($sources['official_module_middleware'], 'ModuleExecutionBoundary')
        && str_contains($sources['official_article_public'], '$this->entryBindings->system(')
        && str_contains($sources['official_article_public'], 'ModuleExecutionBoundary')
        && str_contains($sources['article_model'], 'extends TenantOwnedModel'),
    'official Article Module is not guarded by the shared execution and public Host boundaries',
);
qualificationExpect(
    str_contains($sources['module_manifest'], "'/module.json'")
        && str_contains($sources['module_availability'], 'assertDeployment(')
        && str_contains($sources['module_availability'], 'assertTenant(')
        && str_contains($sources['fixture_module_access'], 'use PeanutAdmin\\Kernel\\Authorization\\TenantAuthorizationRepository;')
        && str_contains($sources['fixture_module_access'], 'private TenantAuthorizationRepository $authorization')
        && str_contains($sources['fixture_module_access'], '$this->authorization->permissions(')
        && str_contains($sources['fixture_module_access'], "AUTHORIZATION_PERMISSION_DENIED")
        && str_contains($sources['deployed_module_registry'], "(\$tenant['enableable'] ?? null) !== true")
        && str_contains($sources['deployed_module_registry'], '!$compiled->isRequiredTenantFoundation($key)')
        && str_contains($sources['module_compiled_registry'], "(\$manifest->data['lifecycle']['protected'] ?? false) === true")
        && str_contains($sources['module_compiled_registry'], "(\$manifest->data['tenant']['enableable'] ?? null) === false"),
    'optional Modules are not guarded by both module.json and Tenant enablement',
);

$officialModules = [
    'official.file' => 'official_file_routes',
    'official.notification' => 'official_notification_routes',
    'official.oauth' => 'official_oauth_routes',
    'official.payment' => 'official_payment_routes',
    'official.member' => 'official_member_routes',
    'official.task' => 'official_task_routes',
    'official.import-export' => 'official_import_export_routes',
];
foreach ($officialModules as $moduleKey => $routeSourceKey) {
    $routeFile = '../app/modules/' . str_replace(['official.', '-'], ['official/', '_'], $moduleKey) . '/route/app.php';
    qualificationExpect(
        str_contains($sources[$routeSourceKey], "OfficialModuleMiddleware::class, '{$moduleKey}'")
            && str_contains($sources['routes'], "/* {$routeFile} */"),
        'official Module HTTP entry is not loaded and Tenant guarded: ' . $moduleKey,
    );
}
qualificationExpect(
    !is_file($root . '/app/common/service/module/ModuleExecutionContext.php')
        && str_contains($sources['module_execution_boundary'], 'use PeanutAdmin\\Kernel\\Module\\ModuleExecutionContext;')
        && str_contains($sources['module_execution_context_core'], 'public static function admin(')
        && str_contains($sources['module_execution_context_core'], 'public static function businessMember(')
        && str_contains($sources['module_execution_context_core'], 'public static function system(')
        && str_contains($sources['module_execution_context_core'], 'public static function scheduled(')
        && str_contains($sources['module_execution_context_core'], 'authorizationRevision < 1')
        && str_contains($sources['module_execution_context_core'], 'MODULE_CONTEXT_INVALID')
        && str_contains($sources['module_execution_boundary'], 'CurrentExecutionContext')
        && str_contains($sources['module_execution_boundary'], 'ModuleExecutionContext::admin(')
        && str_contains($sources['module_execution_boundary'], 'ModuleExecutionContext::system(')
        && str_contains($sources['module_execution_boundary'], 'ModuleExecutionContext::businessMember(')
        && str_contains($sources['official_module_middleware'], '->assertHttp($moduleKey, $operation)')
        && str_contains($sources['module_execution_boundary'], '$this->guard->assertDeployment(')
        && str_contains($sources['module_execution_boundary'], '$this->guard->assertTenant(')
        && str_contains($sources['module_guard_core'], "MODULE_TENANT_DISABLED")
        && str_contains($sources['module_guard_core'], "AUTHORIZATION_PERMISSION_DENIED"),
    'shared official Module middleware does not require a trusted Tenant context and TenantModule state',
);
qualificationExpect(
    str_contains($sources['oauth_controller'], '$this->application->callback(')
        && str_contains($sources['official_account_controller'], '$this->application->callback(')
        && str_contains($sources['payment_notify_controller'], '$this->application->wechat(')
        && str_contains($sources['oauth_application'], "assertExternalCallback('official.oauth')")
        && str_contains($sources['official_account_application'], "assertExternalCallback('official.oauth')")
        && str_contains($sources['payment_callback_application'], "assertExternalCallback('official.payment')")
        && !str_contains($sources['external_resolver_module'], 'assertExternalCallback(')
        && str_contains($sources['async_runtime'], 'ImportExportCommands')
        && str_contains($sources['async_module_provider'], 'TaskImportExportRuntime::class')
        && str_contains($sources['async_module_provider'], 'TaskJobRuntime::class')
        && str_contains($sources['async_worker_definition'], "return 'official.import-export'")
        && str_contains($sources['module_worker'], "assertWorker('official.task')")
        && str_contains($sources['crontab_task_definition'], "assertScheduled('official.task')")
        && str_contains($sources['console'], "'refund:reconcile' => 'official.payment'"),
    'external callback, worker or scheduler entry bypasses its official Module lifecycle',
);

$shippedManifests = glob($root . '/app/modules/*/*/module.json') ?: [];
qualificationExpect($shippedManifests !== [], 'shipped Module qualification must not be empty');
sort($shippedManifests, SORT_STRING);
$requiredFoundationCount = 0;
$optionalModuleCount = 0;
foreach ($shippedManifests as $manifestPath) {
    $manifest = json_decode(
        (string) file_get_contents($manifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $tenant = is_array($manifest['tenant'] ?? null) ? $manifest['tenant'] : [];
    $backend = is_array($manifest['backend'] ?? null) ? $manifest['backend'] : [];
    $database = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
    // Match the installed Core's required-foundation contract; ordinary Modules remain enableable.
    $requiredFoundation = ($manifest['lifecycle']['protected'] ?? false) === true
        && ($tenant['enableable'] ?? null) === false;
    qualificationExpect(
        ($requiredFoundation || (($tenant['enableable'] ?? null) === true
            && ($tenant['disable_behavior'] ?? null) === 'reject_new_operations'))
            && is_array($tenant['requires'] ?? null)
            && is_string($backend['provider'] ?? null)
            && trim((string) $backend['provider']) !== ''
            && is_array($database['owned_tables'] ?? null),
        'shipped Module is neither a protected required foundation nor Tenant-enableable: '
            . basename(dirname($manifestPath)),
    );
    $requiredFoundation ? $requiredFoundationCount++ : $optionalModuleCount++;
}

echo json_encode([
    'status' => 'passed',
    'evidence_scope' => 'source-contracts-only',
    'source_count' => count($sources),
    'shipped_module_count' => count($shippedManifests),
    'required_foundation_count' => $requiredFoundationCount,
    'optional_module_count' => $optionalModuleCount,
    'matrix' => $matrix,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
