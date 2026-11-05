<?php

declare(strict_types=1);

use app\adminapi\services\auth\MenuApplicationService;
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\services\authorization\MenuPermissionUsageQuery;
use app\platform\composition\module\ModuleModuleRegistryFactory;
use PeanutAdmin\Kernel\Menu\MenuRegistry;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Modules\Identity\Menu\MenuAdministrationService;
use PeanutAdmin\Modules\Identity\Menu\ThinkPhpMenuCatalogRepository;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

$server = dirname(__DIR__, 2);
require_once $server . '/vendor/autoload.php';
require_once $server . '/vendor/topthink/framework/src/helper.php';
require_once $server . '/tests/Support/RegisteredMysqlTestResource.php';
require_once $server . '/tests/Support/ThinkPhpTestConnection.php';
require_once $server . '/tests/Support/IdentityMysqlFixture.php';

$assertions = 0;
function optionalMenuExpect(bool $condition, string $message): void
{
    ++$GLOBALS['assertions'];
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function optionalMenuVisible(ThinkPhpMenuCatalogRepository $repository, array $permissions, array $deployment, array $licensed, string $scope = 'tenant'): array
{
    return (new MenuRegistry($repository->activeDefinitions($scope)))->visible(
        $scope === 'tenant' ? 'admin-web' : 'platform-web',
        static fn(string $key): bool => in_array($key, $deployment, true),
        static fn(string $key): bool => in_array($key, $licensed, true),
        static fn(string $key): bool => in_array($key, $permissions, true),
    );
}

$app = new \think\App($server);
$app->config->set(['mode' => 'multi-tenant'], 'deployment');
$registry = (new ModuleModuleRegistryFactory($server))->fromDeploymentConfig([
    'roots' => array_map('dirname', glob($server . '/app/modules/peanut/*/module.json')),
    'kernel_version' => '1.0.0',
    'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp'],
])->compiled();
optionalMenuExpect(count($registry->modules) === 22, 'Optional menu fixture must compile all 22 actual modules');
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    $migrationDirectories = glob($server . '/app/modules/peanut/*/database/migrations', GLOB_ONLYDIR);
    $migrationDirectories = array_values(array_filter($migrationDirectories, static fn($path) => !str_contains($path, '/identity/')));
    IdentityMysqlFixture::install($pdo, [...$migrationDirectories, $server . '/database/installation']);
    ThinkPhpTestConnection::fromPdo($pdo);
    (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();
    $bootstrap = new \PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService(
        new \PeanutAdmin\Modules\Identity\Audit\AuditService(),
        new \PeanutAdmin\Kernel\Identity\PasswordHasher(),
        new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
    );
    $platform = $bootstrap->bootstrapPlatformOwner('optional-menu@example.invalid', 'SyntheticOptionalMenus2026!', 'Menu owner', 'optional-menu-bootstrap');
    $owner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'default', 'Optional menus', 'optional-menu@example.invalid', null, 'Menu owner', 'optional-menu-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $owner->tenantId, $owner->memberId, 'optional-menu-member-active');
    $bootstrap->activateTenant($platform->operatorId, $owner->tenantId, 'optional-menu-tenant-active');
    $applier = ThinkPhpTestConnection::moduleCatalogs($pdo);
    $applier->apply($registry);
    $manifests = [];
    foreach ($registry->modules as $manifest) {
        $manifests[$manifest->data['key']] = $manifest;
    }
    $applier->beginPackageDeployment($manifests, false, gmdate('Y-m-d H:i:s'));
    $applier->activatePackageDeployment($manifests, false, gmdate('Y-m-d H:i:s'));
    $pdo->exec("INSERT INTO pa_plan(code,name,status,revision,created_at,updated_at) VALUES('optional-menu','Optional menu fixture','active',1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $plan = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pa_tenant_license(tenant_id,plan_id,status,effective_at,revision,created_at,updated_at) VALUES(?,?,'active',UTC_TIMESTAMP(3),1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))")->execute([$owner->tenantId, $plan]);
    foreach (array_keys($manifests) as $key) {
        $pdo->prepare('INSERT INTO pa_plan_module(plan_id,module_key) VALUES(?,?)')->execute([$plan, $key]);
    }
    $pdo->exec("INSERT INTO pa_plan_permission(plan_id,permission_key) SELECT {$plan},`key` FROM pa_permission WHERE status='active' AND `key` NOT LIKE 'platform.%'");
    $repository = new ThinkPhpMenuCatalogRepository(new TenantEntitlementService($registry));
    $permissions = $pdo->query("SELECT `key` FROM pa_permission WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
    $deployment = $repository->activeDeploymentModules();
    $licensed = $repository->activeTenantModules($owner->tenantId);
    $pages = array_values(array_filter($repository->activeDefinitions('tenant'), static fn($menu) => str_starts_with($menu->moduleKey, 'peanut.') && $menu->type === 'page'));
    // Current declarations contain 32 pages; RichText now contributes an editor, not a standalone document page.
    optionalMenuExpect(count($pages) === 32, 'A current declared module page was lost');
    optionalMenuExpect(count(array_filter($pages, static fn($page) => $page->moduleKey === 'peanut.rich-text')) === 0, 'Retired RichText document menu reappeared');
    $declared = array_column($registry->menus, null, 'key');
    foreach ($pages as $page) {
        $original = $declared[$page->key];
        optionalMenuExpect($page->parentKey === $original['parent_key'] && $page->routeName === $original['route_name']
            && $page->routePath === $original['route_path'] && $page->requiredPermission === $original['required_permission'], 'Menu contribution identity changed: ' . $page->key);
    }
    $visible = optionalMenuVisible($repository, $permissions, $deployment, $licensed);
    optionalMenuExpect(count(array_filter($visible, static fn($menu) => $menu->parentKey === null && $menu->type === 'group' && $menu->moduleKey === 'core')) === 6, 'One of the six native menu categories was lost');
    optionalMenuExpect(optionalMenuVisible($repository, [], $deployment, $licensed) === [], 'No permissions exposed optional pages or empty categories');
    $articleOnly = optionalMenuVisible($repository, ['peanut.article.list'], $deployment, $licensed);
    optionalMenuExpect(count(array_filter($articleOnly, static fn($menu) => $menu->type === 'page')) === 1, 'Article permission gained unrelated leaf pages');
    optionalMenuExpect(optionalMenuVisible($repository, $permissions, [], $licensed, 'platform') === [], 'Optional menus escaped client/scope/deployment filtering');

    $oldIds = $pdo->query('SELECT `key`,id FROM pa_menu_definition')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pdo->exec("UPDATE pa_menu_definition SET name='我的文章',parent_key='core.organization',sort_order=997,icon='my-icon' WHERE `key`='peanut.article.articles'");
    $pdo->exec("UPDATE pa_menu_definition SET route_path='/my-oauth' WHERE `key`='peanut.oauth.channels'");
    $before = $pdo->query("SELECT id,name,parent_key,sort_order,icon,route_path,required_permission_id FROM pa_menu_definition WHERE `key`='peanut.article.articles'")->fetch(PDO::FETCH_ASSOC);
    $applier->apply($registry);
    optionalMenuExpect($pdo->query("SELECT id,name,parent_key,sort_order,icon,route_path,required_permission_id FROM pa_menu_definition WHERE `key`='peanut.article.articles'")->fetch(PDO::FETCH_ASSOC) === $before, 'Reconcile overwrote customized Article identity or presentation');
    optionalMenuExpect($pdo->query("SELECT route_path FROM pa_menu_definition WHERE `key`='peanut.oauth.channels'")->fetchColumn() === '/my-oauth', 'Reconcile overwrote customized OAuth path');
    optionalMenuExpect($pdo->query("SELECT menu_conflict_json FROM pa_menu_definition WHERE `key`='peanut.article.articles'")->fetchColumn() !== null, 'Customized Article conflict was hidden');
    optionalMenuExpect($pdo->query('SELECT `key`,id FROM pa_menu_definition')->fetchAll(PDO::FETCH_KEY_PAIR) === $oldIds, 'Reconcile changed menu IDs');

    $bridge = (new ReflectionClass(CoreTenantModuleAdminBridge::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($bridge, 'menuAdministration'))->setValue($bridge, new MenuAdministrationService());
    $projection = new ReflectionMethod($bridge, 'serverMenuRecords');
    $project = static fn() => $projection->invoke($bridge, optionalMenuVisible($repository, $permissions, $repository->activeDeploymentModules(), $repository->activeTenantModules($owner->tenantId)), $permissions);
    $management = new MenuApplicationService(
        (new ReflectionClass(AdminAuthorizationService::class))->newInstanceWithoutConstructor(),
        (new ReflectionClass(MenuPermissionUsageQuery::class))->newInstanceWithoutConstructor(),
        new MenuAdministrationService(),
    );
    foreach (['peanut.article.articles', 'peanut.oauth.channels'] as $key) {
        $record = $management->detail($key);
        $management->edit([...$record, 'name' => '自定义展示名', 'sort' => 888, 'is_show' => 0, 'is_cache' => 1,
            'perms' => 'malicious.grant', 'paths' => '/malicious', 'component' => 'malicious/component']);
        $applier->apply($registry);
        $edited = $management->detail($key);
        optionalMenuExpect($edited['name'] === '自定义展示名' && $edited['sort'] === 888 && $edited['is_show'] === 0 && $edited['is_cache'] === 1, 'Optional presentation edit was overwritten');
        optionalMenuExpect($edited['perms'] === $record['perms'] && $edited['paths'] === $record['paths'] && $edited['component'] === $record['component'], 'Optional menu management changed immutable contribution fields');
        $management->updateStatus($key, 1);
        $applier->apply($registry);
        optionalMenuExpect(count(array_filter($project(), static fn($row) => $row['menu_key'] === $key)) === 0, 'Disabled optional menu remained projected');
        $management->updateStatus($key, 0);
        try {
            $management->delete($key);
            throw new RuntimeException('Managed optional menu deletion accepted');
        } catch (\app\common\exception\BusinessException) {
            optionalMenuExpect(true, 'Managed optional delete rejected');
        }
    }

    // A customized Member legacy parent is presentation, and a disabled parent must deny its child.
    $pdo->exec("INSERT INTO pa_system_menu(menu_key,module_key,pid,type,name,paths,sort) VALUES('custom.optional-members','application',0,'M','我的会员目录','/optional-members',999)");
    $groupId = (int) $pdo->lastInsertId();
    $member = $management->detail('peanut.member.account-log');
    $pdo->prepare("INSERT INTO pa_system_menu(menu_key,parent_key,module_key,pid,type,name,perms,paths,component) VALUES('peanut.member.account-log','custom.optional-members','peanut.member',?,'C',?,?,?,?)")
        ->execute([$groupId, $member['name'], $member['perms'], $member['paths'], $member['component']]);
    $applier->apply($registry);
    $memberRows = array_values(array_filter($project(), static fn($row) => $row['menu_key'] === 'peanut.member.account-log'));
    optionalMenuExpect(count($memberRows) === 1 && $memberRows[0]['pid'] === $groupId, 'Member projection overrode a customized legacy parent');
    $pdo->exec("UPDATE pa_system_menu SET is_disable=1 WHERE id={$groupId}");
    optionalMenuExpect(count(array_filter($project(), static fn($row) => $row['menu_key'] === 'peanut.member.account-log')) === 0, 'Disabled customized category leaked Member page');
    $pdo->exec("UPDATE pa_system_menu SET is_disable=0 WHERE id={$groupId}");

    $applier->disableActivePackageModules(['peanut.notification']);
    optionalMenuExpect(!in_array('peanut.notification', $repository->activeDeploymentModules(), true), 'Deployment-disabled optional module remained available');
    optionalMenuExpect(count(array_filter($project(), static fn($row) => $row['module_key'] === 'peanut.notification')) === 0, 'Deployment-disabled Notification menus remained visible');
    $applier->activatePackageDeployment(['peanut.notification' => $manifests['peanut.notification']], false, gmdate('Y-m-d H:i:s'));
    $pdo->exec("DELETE FROM pa_plan_module WHERE plan_id={$plan} AND module_key='peanut.notification'");
    optionalMenuExpect(!in_array('peanut.notification', $repository->activeTenantModules($owner->tenantId), true), 'License removal retained optional module availability');
    optionalMenuExpect(count(array_filter($project(), static fn($row) => $row['module_key'] === 'peanut.notification')) === 0, 'Deployment reactivation bypassed the current license ceiling');
    $pdo->prepare("INSERT INTO pa_plan_module(plan_id,module_key) VALUES(?,'peanut.notification')")->execute([$plan]);
    $pdo->exec("UPDATE pa_tenant_license SET status='suspended' WHERE tenant_id={$owner->tenantId}");
    $optionalKeys = array_values(array_filter(array_keys($manifests), static fn($key) => !$registry->isRequiredTenantFoundation($key)));
    optionalMenuExpect(count(array_filter($project(), static fn($row) => in_array($row['module_key'], $optionalKeys, true))) === 0, 'Suspended license leaked optional module menus');
    $pdo->exec("UPDATE pa_tenant_license SET status='active' WHERE tenant_id={$owner->tenantId}");
    $applier->retire(['peanut.notification']);
    optionalMenuExpect(count(array_filter($repository->activeDefinitions('tenant'), static fn($menu) => $menu->moduleKey === 'peanut.notification')) === 0, 'Retirement left active optional menus');
    $applier->apply($registry, ['peanut.notification']);
    optionalMenuExpect(count(array_filter($repository->activeDefinitions('tenant'), static fn($menu) => $menu->moduleKey === 'peanut.notification')) === 4, 'Reinstall did not restore current Notification contributions');
    $customized = $pdo->query("SELECT id,name,parent_key FROM pa_menu_definition WHERE `key`='peanut.article.articles'")->fetch(PDO::FETCH_ASSOC);
    $applier->retire(['peanut.article']);
    optionalMenuExpect(count(array_filter($project(), static fn($row) => $row['module_key'] === 'peanut.article')) === 0, 'Retired Article menu re-entered through customized presentation');
    $applier->apply($registry, ['peanut.article']);
    optionalMenuExpect($pdo->query("SELECT id,name,parent_key FROM pa_menu_definition WHERE `key`='peanut.article.articles' AND status='active'")->fetch(PDO::FETCH_ASSOC) === $customized, 'Reinstall lost customized Article presentation or ID');
    $applier->apply($registry);
    $snapshot = $management->records();
    $applier->apply($registry);
    optionalMenuExpect($management->records() === $snapshot, 'Repeated reconcile changed optional menu presentation or identities');
    echo 'OPTIONAL_MENU_GROUPING_OK assertions=' . $assertions . ' real_mysql=true modules=22 declared_pages=32' . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
}
