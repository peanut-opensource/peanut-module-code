<?php

declare(strict_types=1);

namespace app\platform\services;

use PeanutAdmin\Modules\Notification\Contract\NotificationBootstrapCommands;
use PeanutAdmin\Modules\Task\Contract\TaskBootstrapCommands;
use PeanutAdmin\Modules\Integration\Contract\ExternalIntegrationBootstrapCommands;
use app\common\execution\ExecutionContextStore;
use app\common\execution\SystemExecutionContext;
use PeanutAdmin\Modules\Settings\Service\WebsiteConfigService;
use app\common\model\decoration\DecoratePage;
use app\common\model\decoration\DecorateTabbar;
use app\common\model\decoration\DecorationTabbarSetting;
use app\common\model\setting\TransactionSetting;
use app\common\model\setting\CustomerServiceSetting;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Identity\Contract\TenantAuthorizationCommands;
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
        'pa_permission',
        'pa_role_permission',
        'pa_tenant_setting',
        'pa_transaction_setting',
    ];

    public function __construct(
        private TaskBootstrapCommands $tasks,
        private ExecutionContextStore $executionContexts,
        private TenantSettingsQuery $tenantSettings,
        private TenantSettingsCommands $settingCommands,
        private ExternalIntegrationBootstrapCommands $externalBindings,
        private TenantAuthorizationCommands $authorization,
        private DbManager $database,
        private CompiledModuleRegistry $modules,
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
        $execution = new SystemExecutionContext(new TenantSystemContext(
            $tenantId,
            'platform.tenant-bootstrap',
            'tenant.provision-application-defaults',
            $operationId,
        ));
        $this->executionContexts->run($execution, function () use (
            $execution,
            $tenantId,
            $ownerMemberId,
            $ownerRoleId,
            $tenantCode,
        ): void {
            $this->grantOwnerPermissions($tenantId, $ownerMemberId, $ownerRoleId);
            $this->seedCrontab($execution->system);
            $this->seedNoticeScenes($execution);
            $this->seedDecoration();
            $this->seedSettings($execution->system);
            $this->seedExternalBindings($tenantId, $tenantCode);
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
        if ($this->notifications !== null) {
            $expected[] = 'pa_notice_scene';
        }
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

    private function grantOwnerPermissions(int $tenantId, int $ownerMemberId, int $ownerRoleId): void
    {
        $execution = new SystemExecutionContext(new TenantSystemContext(
            $tenantId,
            'platform.tenant-bootstrap',
            'identity.bootstrap-owner-permissions',
            $this->executionContexts->require()->requestId(),
        ));
        $this->executionContexts->run($execution, fn() => $this->authorization->grantActiveModulePermissions(
            $tenantId,
            $ownerMemberId,
            $ownerRoleId,
            'peanut.admin',
        ));
    }

    private function seedCrontab(TenantSystemContext $context): void
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
            [
                'name' => '代码生成归档清理',
                'type' => 1,
                'command' => 'generator:cleanup',
                'params' => '',
                'status' => 1,
                'expression' => '0 3 * * *',
                'error' => '',
                'last_time' => 0,
                'time' => 0,
                'max_time' => 0,
                'sort' => 20,
                'remark' => '清理已使用或过期的代码生成下载令牌和隔离归档',
                'create_time' => 0,
                'update_time' => 0,
            ],
        ];
        if (!in_array('peanut.payment', $this->modules->moduleKeys(), true)) {
            $defaults = array_values(array_filter(
                $defaults,
                static fn(array $row): bool => $row['command'] !== 'refund:reconcile',
            ));
        }
        $this->tasks->seedDefaults($context, $defaults);
    }

    private function seedNoticeScenes(SystemExecutionContext $execution): void
    {
        if ($this->notifications === null) {
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
        $pages = [
            [1, '移动端首页', '[{"title":"搜索","name":"search","disabled":1,"content":{},"styles":{}},{"title":"首页轮播图","name":"banner","content":{"enabled":1,"style":1,"bg_style":1,"data":[{"is_show":1,"image":"","bg":"","name":"","link":{"target_type":"shop","target":"home"}}]},"styles":{}},{"title":"导航菜单","name":"nav","content":{"enabled":1,"style":2,"per_line":5,"show_line":2,"data":[{"is_show":1,"image":"","name":"资讯中心","link":{"target_type":"shop","target":"news"}}]},"styles":{}},{"title":"首页中部轮播图","name":"middle-banner","content":{"enabled":1,"data":[{"is_show":1,"image":"","name":"","link":{"target_type":"shop","target":"home"}}]},"styles":{}},{"title":"资讯","name":"news","disabled":1,"content":{},"styles":{}}]', '[{"title":"页面设置","name":"page-meta","content":{"title":"首页","title_type":1,"title_img":"","bg_type":1,"bg_color":"#2F80ED","bg_image":"","text_color":1},"styles":{}}]'],
            [2, '个人中心', '[{"title":"用户信息","name":"user-info","disabled":1,"content":{},"styles":{}},{"title":"我的服务","name":"my-service","content":{"enabled":1,"style":1,"title":"我的服务","data":[{"is_show":1,"image":"","name":"我的收藏","link":{"target_type":"shop","target":"favorites"}}]},"styles":{}},{"title":"个人中心广告图","name":"user-banner","content":{"enabled":1,"data":[{"is_show":1,"image":"","name":"","link":{"target_type":"shop","target":"profile"}}]},"styles":{}}]', '[{"title":"页面设置","name":"page-meta","content":{"title":"个人中心","title_type":1,"title_img":"","bg_type":1,"bg_color":"#2F80ED","bg_image":"","text_color":1},"styles":{}}]'],
            [3, '客服设置', '[{"title":"客服设置","name":"customer-service","content":{"title":"添加客服二维码","time":"9:30 - 19:00","mobile":"","qrcode":"","remark":"长按添加客服或拨打客服热线"},"styles":{}}]', '[]'],
            [4, 'PC 首页', '[{"title":"首页轮播图","name":"pc-banner","content":{"enabled":1,"data":[{"image":"","name":"","link":{"target_type":"shop","target":"home"}}]},"styles":{"position":"absolute","left":"40px","top":"75px","width":"750px","height":"340px"}}]', '[]'],
            [5, '系统风格', '{"themeColorId":3,"topTextColor":"white","navigationBarColor":"#A74BFD","themeColor1":"#A74BFD","themeColor2":"#CB60FF","buttonColor":"white"}', '[]'],
        ];
        $tabbars = [
            [0, '首页', '{"target_type":"shop","target":"home"}'],
            [1, '资讯', '{"target_type":"shop","target":"news"}'],
            [2, '我的', '{"target_type":"shop","target":"profile"}'],
        ];
        $existingPageTypes = array_fill_keys(array_map(
            'intval',
            DecoratePage::whereIn('type', array_column($pages, 0))->column('type'),
        ), true);
        $missingPages = [];
        foreach ($pages as [$type, $name, $data, $meta]) {
            if (!isset($existingPageTypes[$type])) {
                $missingPages[] = compact('type', 'name', 'data', 'meta') + [
                    'create_time' => 0,
                    'update_time' => 0,
                ];
            }
        }
        if ($missingPages !== []) {
            (new DecoratePage())->saveAll($missingPages);
        }

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
        if (TransactionSetting::where([])->find() === null) {
            TransactionSetting::create([
                'cancel_unpaid_orders' => 1,
                'cancel_unpaid_orders_times' => 30,
                'verification_orders' => 1,
                'verification_orders_times' => 24,
                'create_time' => 0,
                'update_time' => 0,
            ]);
        }
    }

    private function seedExternalBindings(int $tenantId, string $tenantCode): void
    {
        $execution = new SystemExecutionContext(new TenantSystemContext(
            $tenantId,
            'platform.tenant-bootstrap',
            'integration.bootstrap-bindings',
            $this->executionContexts->require()->requestId(),
        ));
        $this->executionContexts->run($execution, function () use ($tenantId, $tenantCode): void {
            $selected = $this->modules->moduleKeys();
            foreach (ExternalIntegrationBootstrapCommands::DEFAULT_PROVIDERS as $provider) {
                $moduleKey = str_starts_with($provider, 'payment.') ? 'peanut.payment' : 'peanut.oauth';
                if (!in_array($moduleKey, $selected, true)) {
                    continue;
                }
                $this->externalBindings->ensureUnconfiguredBinding($tenantId, $tenantCode, $provider);
            }
        });
    }
}
