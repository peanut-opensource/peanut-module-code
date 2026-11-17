<?php

declare(strict_types=1);

namespace app\platform\services;

use PeanutAdmin\Modules\Notification\Contract\NotificationBootstrapCommands;
use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PeanutAdmin\Modules\Integration\Contract\ExternalIntegrationBootstrapCommands;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use app\common\services\decoration\DecorationPageDefaultsService;
use app\common\model\decoration\DecorateTabbar;
use app\common\model\decoration\DecorationTabbarSetting;
use app\common\model\setting\CustomerServiceSetting;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use think\DbManager;
use think\db\PDOConnection;

/** Seeds the application-owned defaults that every new Tenant must receive. */
final readonly class ApplicationTenantBootstrapService
{
    private const REQUIRED_TABLES = [
        'pa_crontab',
        'pa_customer_service_setting',
        'pa_decorate_page',
        'pa_decorate_tabbar',
        'pa_decorate_tabbar_setting',
        'pa_external_channel_binding',
        'pa_tenant_setting',
    ];

    public function __construct(
        private TaskBootstrapCommands $tasks,
        private ExecutionContextStore $executionContexts,
        private TenantSettingsQuery $tenantSettings,
        private TenantSettingsCommands $settingCommands,
        private ExternalIntegrationBootstrapCommands $externalBindings,
        private DbManager $database,
        private CompiledModuleRegistry $modules,
        private DecorationPageDefaultsService $pageDefaults,
        private ?NotificationBootstrapCommands $notifications = null,
    ) {}

    public function provision(int $tenantId, int $ownerMemberId, int $ownerRoleId, string $tenantCode): void
    {
        if (min($tenantId, $ownerMemberId, $ownerRoleId) < 1 || trim($tenantCode) === '') {
            throw new \DomainException('TENANT_APPLICATION_BOOTSTRAP_INPUT_INVALID');
        }
        if (!$this->applicationSchemaPresent()) {
            return;
        }

        $operationId = $this->executionContexts->current()?->requestId()
            ?? 'tenant-bootstrap:' . $tenantCode;
        $selectedModules = app(\PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries::class)->activeModuleKeys($tenantId);
        $execution = new SystemExecutionContext(new TenantSystemContext(
            $tenantId,
            'platform.tenant-bootstrap',
            'tenant.provision-application-defaults',
            $operationId,
        ));
        $this->executionContexts->run($execution, function () use (
            $execution,
            $tenantId,
            $tenantCode,
            $selectedModules,
        ): void {
            $this->seedCrontab($execution->system, $selectedModules);
            $this->seedNoticeScenes($execution, $selectedModules);
            $this->seedDecoration();
            $this->seedSettings($execution->system);
            $this->seedExternalBindings($tenantId, $tenantCode, $selectedModules);
        });
    }

    private function applicationSchemaPresent(): bool
    {
        $connection = $this->database->connect();
        if (!$connection instanceof PDOConnection) {
            throw new \DomainException('TENANT_APPLICATION_SCHEMA_DRIVER_UNSUPPORTED');
        }
        $available = array_fill_keys($connection->getTables(), true);
        $expected = self::REQUIRED_TABLES;
        $tables = array_values(array_filter(
            $expected,
            static fn(string $table): bool => isset($available[$table]),
        ));
        if ($tables === []) {
            // Core package tests intentionally exercise this adapter without the application schema.
            return false;
        }
        sort($tables, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($tables !== $expected) {
            throw new \DomainException('TENANT_APPLICATION_SCHEMA_INCOMPLETE');
        }
        return true;
    }

    /** @param list<string> $selectedModules */
    private function seedCrontab(TenantSystemContext $context, array $selectedModules): void
    {
        $defaults = [
            [
                'name' => '退款状态收敛',
                'type' => 1,
                'command' => 'refund:reconcile',
                'params' => '',
                'status' => 1,
                'expression' => '* * * * *',
                'error' => '',
                'last_time' => 0,
                'time' => 0,
                'max_time' => 0,
                'sort' => 100,
                'remark' => '查询支付渠道并收敛充值退款最终状态',
                'create_time' => 0,
                'update_time' => 0,
            ],
        ];
        if (!in_array('peanut.payment', $selectedModules, true)) {
            $defaults = array_values(array_filter(
                $defaults,
                static fn(array $row): bool => $row['command'] !== 'refund:reconcile',
            ));
        }
        if ($defaults !== []) {
            $this->tasks->seedDefaults($context, $defaults);
        }
    }

    private function seedNoticeScenes(SystemExecutionContext $execution, array $selectedModules): void
    {
        // A declared optional service may be bound before its Module is installed.
        if ($this->notifications === null || !in_array('peanut.notification', $selectedModules, true)) {
            return;
        }
        $notificationExecution = new SystemExecutionContext(new TenantSystemContext(
            $execution->system->tenantId,
            'platform.tenant-bootstrap',
            'notification.provision-tenant-defaults',
            $execution->requestId(),
        ));
        $this->executionContexts->run(
            $notificationExecution,
            fn() => $this->notifications->provisionTenantDefaults($notificationExecution),
        );
    }

    private function seedDecoration(): void
    {
        $this->pageDefaults->ensureMissing();
        $tabbars = [
            [0, '首页', '{"target_type":"shop","target":"home"}'],
        ];
        $existingPositions = array_fill_keys(array_map(
            'intval',
            DecorateTabbar::whereIn('position', array_column($tabbars, 0))->column('position'),
        ), true);
        $missingTabbars = [];
        foreach ($tabbars as [$position, $name, $link]) {
            if (!isset($existingPositions[$position])) {
                $missingTabbars[] = compact('position', 'name', 'link') + [
                    'selected' => '',
                    'unselected' => '',
                    'is_show' => 1,
                    'create_time' => 0,
                    'update_time' => 0,
                ];
            }
        }
        if ($missingTabbars !== []) {
            (new DecorateTabbar())->saveAll($missingTabbars);
        }
    }

    private function seedSettings(TenantSystemContext $context): void
    {
        $documents = [
            'website' => WebsiteConfigService::defaults(),
            'copyright' => ['config' => []],
            'agreement' => [
                'service_title' => '',
                'service_content' => '',
                'privacy_title' => '',
                'privacy_content' => '',
            ],
            'site-statistics' => ['clarity_code' => ''],
            'member-profile' => ['user_avatar' => 'brand/avatar-member.svg'],
            'login' => [
                'login_way' => [1, 2],
                'coerce_mobile' => 0,
                'login_agreement' => 0,
                'third_auth' => 0,
                'wechat_auth' => 0,
            ],
            'web-page' => ['status' => 1, 'page_status' => 0, 'page_url' => ''],
            'hot-search' => ['status' => 0],
        ];
        foreach ($documents as $namespace => $document) {
            if ($this->tenantSettings->get($context, $namespace)->revision === 0) {
                $this->settingCommands->replace($context, $namespace, $document);
            }
        }
        if (CustomerServiceSetting::where([])->find() === null) {
            CustomerServiceSetting::create([
                'qr_file_id' => null,
                'wechat' => '',
                'phone' => '',
                'service_time' => '',
                'create_time' => 0,
                'update_time' => 0,
            ]);
        }
        if (DecorationTabbarSetting::where([])->find() === null) {
            DecorationTabbarSetting::create([
                'style' => '{"default_color":"#666666","selected_color":"#2F80ED"}',
                'create_time' => 0,
                'update_time' => 0,
            ]);
        }
    }

    /** @param list<string> $selectedModules */
    private function seedExternalBindings(int $tenantId, string $tenantCode, array $selectedModules): void
    {
        $execution = new SystemExecutionContext(new TenantSystemContext(
            $tenantId,
            'platform.tenant-bootstrap',
            'integration.bootstrap-bindings',
            $this->executionContexts->require()->requestId(),
        ));
        $this->executionContexts->run($execution, function () use ($tenantId, $tenantCode, $selectedModules): void {
            foreach (ExternalIntegrationBootstrapCommands::DEFAULT_PROVIDERS as $provider) {
                $moduleKey = str_starts_with($provider, 'payment.') ? 'peanut.payment' : 'peanut.oauth';
                if (!in_array($moduleKey, $selectedModules, true)) {
                    continue;
                }
                $this->externalBindings->ensureUnconfiguredBinding($tenantId, $tenantCode, $provider);
            }
        });
    }
}
