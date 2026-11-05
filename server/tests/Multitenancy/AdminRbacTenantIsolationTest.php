<?php

declare(strict_types=1);

use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Module\ManifestLoader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';
require_once __DIR__ . '/../Support/RegisteredMysqlTestResource.php';
require_once __DIR__ . '/../Support/IdentityMysqlFixture.php';

function expectRbacTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<array<string,mixed>> $menus */
function containsImportExportMenu(array $menus): bool
{
    $forbiddenPermissions = [
        'peanut.import-export.operation-log.export',
        'peanut.import-export.operation.status',
        'peanut.import-export.result.download',
    ];
    foreach ($menus as $menu) {
        if (($menu['module_key'] ?? null) === 'peanut.import-export'
            || in_array((string) ($menu['perms'] ?? ''), $forbiddenPermissions, true)
            || containsImportExportMenu(is_array($menu['children'] ?? null) ? $menu['children'] : [])) {
            return true;
        }
    }

    return false;
}

function rbacTenantContext(int $tenantId, int $accountId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        'rbac-session-' . $tenantId . '-' . $memberId,
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
        1,
    ), $requestId);
}

function createNativeRbacSchema(PDO $pdo, string $serverRoot): void
{
    IdentityMysqlFixture::install($pdo);

}

$serverRoot = dirname(__DIR__, 2);
$manifestLoader = new ManifestLoader();
$moduleIdentities = [];
foreach (['File' => 'peanut.file', 'Task' => 'peanut.task', 'ImportExport' => 'peanut.import-export'] as $directory => $moduleKey) {
    $directory = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $directory));
    $manifest = $manifestLoader->load($serverRoot . '/app/modules/peanut/' . $directory);
    $version = (string) ($manifest->data['version'] ?? '');
    if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $manifest->digest) !== 1
    ) {
        throw new RuntimeException("invalid Module fixture identity: {$moduleKey}");
    }
    $moduleIdentities[$moduleKey] = ['version' => $version, 'digest' => $manifest->digest];
}
$fileIdentity = $moduleIdentities['peanut.file'];
$taskIdentity = $moduleIdentities['peanut.task'];
$importExportIdentity = $moduleIdentities['peanut.import-export'];
$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int) IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = IsolatedBackendEnvironment::required('DB_NAME');
if ($host === '' || $port < 1 || $user === '' || $password === '' || $database === '') {
    throw new RuntimeException('registered database environment is required');
}

[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
try {
    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App($serverRoot);
    $app->setRuntimePath(sys_get_temp_dir() . '/peanut-rbac-' . bin2hex(random_bytes(8)) . '/');
    $app->initialize();
    createNativeRbacSchema($pdo, $serverRoot);
    $now = '2030-01-01 00:00:00.000';
    $pdo->exec(<<<SQL
INSERT INTO pa_tenant
  (id, code, name, display_name, status, activated_at, created_at, updated_at)
VALUES
  (101, 'alpha', 'Alpha', 'Alpha', 'active', '{$now}', '{$now}', '{$now}'),
  (202, 'beta', 'Beta', 'Beta', 'active', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_account (id, display_name, status, created_at, updated_at) VALUES
  (1001, 'Alpha member', 'active', '{$now}', '{$now}'),
  (1002, 'Beta member', 'active', '{$now}', '{$now}'),
  (1003, 'Alpha root', 'active', '{$now}', '{$now}'),
  (1004, 'Beta root', 'active', '{$now}', '{$now}');
INSERT INTO pa_credential
  (account_id, kind, identifier_type, identifier_normalized, secret_hash, status, verified_at, secret_changed_at, created_at, updated_at)
VALUES
  (1001, 'email_password', 'email', 'alpha@example.test', REPEAT('a', 64), 'active', '{$now}', '{$now}', '{$now}', '{$now}'),
  (1002, 'email_password', 'email', 'beta@example.test', REPEAT('b', 64), 'active', '{$now}', '{$now}', '{$now}', '{$now}'),
  (1003, 'email_password', 'email', 'alpha-root@example.test', REPEAT('c', 64), 'active', '{$now}', '{$now}', '{$now}', '{$now}'),
  (1004, 'email_password', 'email', 'beta-root@example.test', REPEAT('d', 64), 'active', '{$now}', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_tenant_member
  (id, tenant_id, account_id, display_name, status, authorization_revision, joined_at, created_at, updated_at)
VALUES
  (501, 101, 1001, 'Alpha member', 'active', 1, '{$now}', '{$now}', '{$now}'),
  (502, 202, 1002, 'Beta member', 'active', 1, '{$now}', '{$now}', '{$now}'),
  (503, 101, 1003, 'Alpha root', 'active', 1, '{$now}', '{$now}', '{$now}'),
  (504, 202, 1004, 'Beta root', 'active', 1, '{$now}', '{$now}', '{$now}');
INSERT INTO pa_module_installation
  (module_key, installed_version, manifest_schema_version, manifest_digest, status, installed_at, activated_at, created_at, updated_at)
VALUES
  ('peanut.file', '{$fileIdentity['version']}', 1, '{$fileIdentity['digest']}', 'active', '{$now}', '{$now}', '{$now}', '{$now}'),
  ('peanut.task', '{$taskIdentity['version']}', 1, '{$taskIdentity['digest']}', 'active', '{$now}', '{$now}', '{$now}', '{$now}'),
  ('peanut.import-export', '{$importExportIdentity['version']}', 1, '{$importExportIdentity['digest']}', 'active', '{$now}', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_tenant_module
  (tenant_id, module_key, status, source, enabled_at, created_at, updated_at)
VALUES
  (101, 'peanut.file', 'enabled', 'manual', '{$now}', '{$now}', '{$now}'),
  (101, 'peanut.task', 'enabled', 'manual', '{$now}', '{$now}', '{$now}'),
  (101, 'peanut.import-export', 'enabled', 'manual', '{$now}', '{$now}', '{$now}');
INSERT INTO pa_permission
  (`key`, module_key, type, name, description, risk_level, status, manifest_version, created_at, updated_at, retired_at)
VALUES
  ('peanut.import-export.operation-log.export', 'peanut.import-export', 'api', 'Export operation logs', NULL, 'sensitive', 'active', 'fresh-schema-v1', '{$now}', '{$now}', NULL)
ON DUPLICATE KEY UPDATE status = 'active', updated_at = VALUES(updated_at), retired_at = NULL;
INSERT INTO pa_role
  (id, tenant_id, `key`, name, is_builtin, status, authorization_revision, created_at, updated_at)
VALUES
  (11, 101, 'alpha.export', 'Alpha export', 0, 'active', 1, '{$now}', '{$now}'),
  (22, 202, 'beta.export', 'Beta export', 0, 'active', 1, '{$now}', '{$now}'),
  (33, 101, 'core.tenant-owner', 'Alpha owner', 1, 'active', 1, '{$now}', '{$now}'),
  (44, 202, 'core.tenant-owner', 'Beta owner', 1, 'active', 1, '{$now}', '{$now}');
INSERT INTO pa_member_role (tenant_id, tenant_member_id, role_id, assigned_at) VALUES
  (101, 501, 11, '{$now}'),
  (202, 502, 22, '{$now}'),
  (101, 503, 33, '{$now}'),
  (202, 504, 44, '{$now}');
SQL);
    $registry = $app->make(app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider::class)->registry()->compiled();
    $app->make(PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer::class)->synchronize();
    $app->make(PeanutAdmin\Modules\Identity\Authorization\ModuleAuthorizationCatalogSynchronizer::class)->synchronize($registry);
    (new PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog())->synchronize(app\common\services\authorization\GeneratedApiCatalog::load($serverRoot), [], true);
    $app->make(PeanutAdmin\Modules\Identity\Menu\MenuCatalogSynchronizer::class)->synchronize($registry);
    foreach ($registry->modules as $manifest) {
        $install = $pdo->prepare("INSERT IGNORE INTO pa_module_installation (module_key,installed_version,manifest_schema_version,manifest_digest,status,created_at,updated_at) VALUES (?,?,?,?,'active',?,?)");
        $install->execute([$manifest->data['key'], $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest, $now, $now]);
        $enable = $pdo->prepare("INSERT IGNORE INTO pa_tenant_module (tenant_id,module_key,status,source,created_at,updated_at) VALUES (?,?,'enabled','manual',?,?)");
        $enable->execute([101, $manifest->data['key'], $now, $now]);
        if ($manifest->data['key'] !== 'peanut.import-export') {
            $enable->execute([202, $manifest->data['key'], $now, $now]);
        }
    }
    $permissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key` = 'peanut.import-export.operation-log.export'")->fetchColumn();
    $applicationPermissionId = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key` = 'peanut.reference-codes.read'")->fetchColumn();
    expectRbacTenant($permissionId > 0, 'canonical export permission is missing');
    expectRbacTenant($applicationPermissionId > 0, 'reference dictionary permission is missing');
    foreach ([$permissionId, $applicationPermissionId] as $grant) {
        $pdo->prepare('INSERT INTO pa_role_permission (tenant_id, role_id, permission_id, granted_at) VALUES (?, ?, ?, ?)')->execute([101, 11, $grant, $now]);
    }
    // The menu has its own read permission; exporting does not implicitly grant UI access.
    $menuPermission = (int) $pdo->query("SELECT id FROM pa_permission WHERE `key`='peanut.import-export.operations.read'")->fetchColumn();
    expectRbacTenant($menuPermission > 0, 'ImportExport operations menu permission missing');
    $pdo->prepare('INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (101,11,?,?)')->execute([$menuPermission, $now]);
    $authorization = $app->make(AdminAuthorizationService::class);
    $alphaContext = rbacTenantContext(101, 1001, 501, 'native-rbac-alpha-' . $database);
    $betaContext = rbacTenantContext(202, 1002, 502, 'native-rbac-beta-' . $database);
    $alphaRootContext = rbacTenantContext(101, 1003, 503, 'native-rbac-alpha-root-' . $database);
    $betaRootContext = rbacTenantContext(202, 1004, 504, 'native-rbac-beta-root-' . $database);
    $alpha = $authorization->principal($alphaContext);
    $beta = $authorization->principal($betaContext);
    $alphaRoot = $authorization->principal($alphaRootContext);
    $betaRoot = $authorization->principal($betaRootContext);

    expectRbacTenant(
        $authorization->decide($alphaContext, $alpha, 'peanut.import-export.operation-log.export')->allowed,
        'same-Tenant RBAC permission was denied',
    );
    expectRbacTenant(
        $authorization->decide($alphaContext, $alpha, 'peanut.reference-codes.read')->allowed,
        'reference dictionary RBAC permission was denied',
    );
    expectRbacTenant(
        !$authorization->decide($betaContext, $beta, 'peanut.reference-codes.read')->allowed,
        'reference dictionary RBAC permission leaked across Tenants',
    );
    expectRbacTenant(
        !$authorization->decide($betaContext, $alpha, 'peanut.import-export.operation-log.export')->allowed,
        'mismatched TenantContext accepted an Alpha principal',
    );
    expectRbacTenant(
        !$authorization->decide(null, $alpha, 'peanut.import-export.operation-log.export')->allowed,
        'missing TenantContext did not fail closed',
    );
    // Code docs/architecture/tenant-modules-and-data-scopes.md: permissionGrants is
    // bounded by explicit business role grants and module licenses; owner adds Core grants only.
    expectRbacTenant(
        !$authorization->decide($alphaRootContext, $alphaRoot, 'peanut.import-export.operation-log.export')->allowed,
        'tenant owner bypassed an explicit business permission grant',
    );
    foreach ([[101, 33, $permissionId], [202, 44, $permissionId], [202, 44, $applicationPermissionId]] as [$grantTenant, $grantRole, $grantPermission]) {
        $pdo->prepare('INSERT INTO pa_role_permission (tenant_id,role_id,permission_id,granted_at) VALUES (?,?,?,?)')
            ->execute([$grantTenant, $grantRole, $grantPermission, $now]);
    }
    expectRbacTenant(
        $authorization->decide($alphaRootContext, $alphaRoot, 'peanut.import-export.operation-log.export')->allowed,
        'explicit owner business grant was denied for an enabled Module',
    );
    expectRbacTenant(
        $authorization->decide($betaRootContext, $betaRoot, 'peanut.reference-codes.read')->allowed,
        'Tenant owner explicit reference dictionary grant was denied',
    );
    expectRbacTenant(
        !$authorization->decide($alphaRootContext, $alphaRoot, 'unregistered/read')->allowed,
        'root bypassed route registration',
    );
    expectRbacTenant(
        !$authorization->decide($betaRootContext, $betaRoot, 'peanut.import-export.operation-log.export')->allowed,
        'root bypassed disabled Tenant Module',
    );

    $data = $authorization->accessData($alphaContext, $alpha);
    expectRbacTenant($data->menu !== [], 'enabled Tenant Module menu projection was empty');
    expectRbacTenant(containsImportExportMenu($data->menu), 'enabled and granted ImportExport menu was missing');
    expectRbacTenant(
        !containsImportExportMenu($authorization->accessData($betaContext, $beta)->menu),
        'disabled Tenant Module menu leaked into Beta',
    );

    $pdo->exec('DELETE FROM pa_role_permission WHERE tenant_id = 101 AND role_id = 11');
    expectRbacTenant(
        !$authorization->decide($alphaContext, $alpha, 'peanut.import-export.operation-log.export')->allowed,
        'revoked RBAC permission still authorized',
    );
    expectRbacTenant(
        !$authorization->decide($alphaContext, $alpha, 'peanut.reference-codes.read')->allowed,
        'revoked reference dictionary permission still authorized',
    );
    $pdo->prepare(
        'INSERT INTO pa_role_permission (tenant_id, role_id, permission_id, granted_at) VALUES (?, ?, ?, ?)',
    )->execute([101, 11, $permissionId, $now]);
    $pdo->exec("UPDATE pa_tenant_module SET status = 'disabled', disabled_at = UTC_TIMESTAMP(3) WHERE tenant_id = 101");
    expectRbacTenant(
        !$authorization->decide($alphaRootContext, $alphaRoot, 'peanut.import-export.operation-log.export')->allowed,
        'root bypassed Module revocation',
    );
} finally {
    RegisteredMysqlTestResource::cleanup($pdo, $database, $created);
    IsolatedBackendEnvironment::cleanup();
}

echo "NATIVE-ADMIN-RBAC-TENANT-ISOLATION-001 passed\n";
