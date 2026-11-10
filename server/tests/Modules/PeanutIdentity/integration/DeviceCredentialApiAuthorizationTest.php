<?php

declare(strict_types=1);

use app\common\execution\{AdminExecutionContext, CurrentExecutionContext, ExecutionContextStore};
use app\common\infrastructure\authorization\CoreTenantModuleAdminBridge;
use app\common\policy\permission\RegisteredAdminPermissionPolicy;
use app\common\services\authorization\{AdminAuthorizationService, EndpointPermissionGrant};
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use PeanutAdmin\Kernel\Module\{CompiledModuleRegistry, ManifestDocument};
use PeanutAdmin\Modules\Identity\Authorization\{ApiPermissionCatalog, ThinkPhpTenantAuthorizationRepository};
use PeanutAdmin\Modules\Identity\Contract\{AdminDirectoryQuery, TenantAuthorizationQuery};
use PeanutAdmin\Modules\Identity\Device\DeviceCredentialAccess;
use PeanutAdmin\Modules\Identity\Membership\Query\ThinkPhpTenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Menu\{MenuAdministrationService, ThinkPhpMenuCatalogRepository};
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;
use think\facade\Db;

require __DIR__ . '/../support/DeviceCredentialFixture.php';
require_once $server . '/tests/Support/ThinkPhpTestConnection.php';
require_once $server . '/vendor/topthink/framework/src/helper.php';

// Exercise the real post-admission bridge; HTTP login and license qualification are separate.
$app = new think\App(__DIR__);
$pdo = new PDO('sqlite::memory:');
ThinkPhpTestConnection::fromPdo($pdo);
$pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT, name TEXT, security_revision INTEGER, authorization_revision INTEGER);
CREATE TABLE pa_account (id INTEGER PRIMARY KEY, status TEXT, display_name TEXT, security_revision INTEGER, avatar_uri TEXT, last_login_at TEXT);
CREATE TABLE pa_tenant_member (id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, status TEXT, display_name TEXT, security_revision INTEGER, authorization_revision INTEGER, primary_department_id INTEGER);
CREATE TABLE pa_credential (account_id INTEGER, kind TEXT, identifier_type TEXT, identifier_normalized TEXT, status TEXT);
CREATE TABLE pa_member_role (tenant_id INTEGER, tenant_member_id INTEGER, role_id INTEGER);
CREATE TABLE pa_role (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, "key" TEXT, name TEXT, is_builtin INTEGER);
CREATE TABLE pa_permission (id INTEGER PRIMARY KEY, "key" TEXT, module_key TEXT, status TEXT);
CREATE TABLE pa_permission_api (id INTEGER PRIMARY KEY, permission_id INTEGER, api_id TEXT);
INSERT INTO pa_tenant VALUES (1, 'active', 'Synthetic tenant', 1, 1);
INSERT INTO pa_account VALUES (1, 'active', 'Synthetic account', 1, '', NULL);
INSERT INTO pa_tenant_member VALUES (1, 1, 1, 'active', 'Synthetic member', 1, 1, NULL);
INSERT INTO pa_credential VALUES (1, 'email_password', 'email', 'synthetic@example.invalid', 'active');
INSERT INTO pa_permission VALUES (1, 'custom.device-enrollment', 'instance.tenant', 'active');
SQL);
$contexts = new ExecutionContextStore();
$current = new CurrentExecutionContext($contexts);
$registry = new CompiledModuleRegistry([
    ManifestDocument::fromObject($identityModule, json_decode(file_get_contents($identityModule . '/module.json'), flags: JSON_THROW_ON_ERROR)),
], [], [], [], 'device-authorization-fixture');
$entitlements = new TenantEntitlementService($registry);
$identity = new TenantAuthorizationQuery(new ThinkPhpTenantMemberDirectory(), $registry);
$bridge = new CoreTenantModuleAdminBridge(
    new ThinkPhpModuleGovernanceProvider($server, [], ThinkPhpTestConnection::moduleCatalogs($pdo), $registry),
    new ThinkPhpTenantAuthorizationRepository($entitlements, $identity),
    new ThinkPhpMenuCatalogRepository($entitlements), $identity, new MenuAdministrationService(), $entitlements,
);
$request = new think\Request();
$grant = new EndpointPermissionGrant($request);
$authorization = new AdminAuthorizationService(
    $bridge, new RegisteredAdminPermissionPolicy(), new AdminDirectoryQuery($current), $identity, $grant, $current,
);
$access = new DeviceCredentialAccess($current, $authorization, $modules);
$catalog = new ApiPermissionCatalog();
$permission = 'peanut.identity.device-credential.enroll';
$endpoint = [
    'id' => hash('sha256', 'synthetic-enrollment-endpoint'), 'audience' => 'tenant',
    'default_permission_keys' => json_encode([$permission], JSON_THROW_ON_ERROR),
];
Db::name('permission_api')->insert(['permission_id' => 1, 'api_id' => $endpoint['id']]);
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    businessCheck($passed, $message);
    ++$checks;
};
$check($catalog->permissionKeys($endpoint) === ['custom.device-enrollment'], 'The live API catalog resolves the remapped bundle.');
$check(!$grant->allows($actor, $permission), 'No endpoint admission exists initially.');

// AuthMiddleware records this grant only after its own bundle/entitlement decision.
$grant->record($actor, $endpoint);
$contexts->run(new AdminExecutionContext($actor, 'device.api-admission'), static function () use ($access, $actor, $permission): void {
    $access->assert($actor, $permission);
});
$check(true, 'The actual AdminAuthorizationService decision admits the declared device action after API remapping.');
$check(!$grant->allows(businessActor(), $permission), 'An equal-valued context cannot borrow endpoint admission.');
$check(!$grant->allows($actor, 'peanut.identity.device-credential.bind-installation'), 'Enrollment admission does not grant installation binding.');
Db::name('tenant_member')->where('id', 1)->update(['status' => 'disabled']);
try {
    $contexts->run(new AdminExecutionContext($actor, 'device.stale-admission'), static fn() => $access->assert($actor, $permission));
    throw new LogicException('Expected the current principal to be rejected.');
} catch (DomainException $error) {
    $check($error->getMessage() === 'TENANT_ADMIN_PRINCIPAL_UNAVAILABLE', 'Endpoint admission cannot revive a disabled principal.');
}
$check($contexts->isEmpty() && !Db::connect()->getPdo()->inTransaction(), 'The actual decision restores execution and transaction ownership.');
echo json_encode(['suite' => 'DeviceCredentialApiAuthorization', 'checks' => $checks, 'scope' => 'live catalog and post-admission authorization; HTTP login, bundle-role admission and license qualification not run'], JSON_THROW_ON_ERROR) . PHP_EOL;
