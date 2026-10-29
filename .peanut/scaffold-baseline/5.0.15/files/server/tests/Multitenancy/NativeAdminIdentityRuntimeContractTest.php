<?php

declare(strict_types=1);

$serverDir = dirname(__DIR__, 2);
$runtimeFiles = [
    'app/adminapi',
    'app/common/services/authorization',
    'app/modules/official/identity/src',
    'app/modules/official/identity/module.json',
    'app/platform/services/CoreTenantOwnerAdminProvisioner.php',
    'app/modules/official/import_export/src/Infrastructure/Authorization/AdminAsyncAuthorization.php',
];
$forbidden = [
    'pa_legacy_admin_tenant_map',
    'pa_legacy_role_tenant_map',
    'pa_legacy_dept_tenant_map',
    'pa_default_tenant_bootstrap',
];
$source = '';
if (is_file($serverDir . '/app/common/service/tenant/DefaultTenantBootstrap.php')) {
    throw new RuntimeException('Retired legacy bootstrap service remains in Runtime');
}
foreach ($runtimeFiles as $relative) {
    $path = $serverDir . '/' . $relative;
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }
        continue;
    }
    if (!is_file($path)) {
        throw new RuntimeException('Native identity source owner is missing: ' . $relative);
    }
    $source .= (string) file_get_contents($path);
}

foreach ($forbidden as $table) {
    if (str_contains($source, $table)) {
        throw new RuntimeException("Forbidden identity dependency remains: {$table}");
    }
}
// The ORM applies the configured prefix. Check declared ownership AND each
// actual model's unprefixed name, not obsolete raw SQL in the application layer.
$identityRoot = $serverDir . '/app/modules/official/identity';
$identity = json_decode((string) file_get_contents($identityRoot . '/module.json'), true, 128, JSON_THROW_ON_ERROR);
$models = [
    'Account' => 'account', 'Credential' => 'credential',
    'TenantMember' => 'tenant_member', 'MemberRole' => 'member_role',
    'Role' => 'role', 'RolePermission' => 'role_permission',
    'Permission' => 'permission', 'Department' => 'department',
];
foreach ($models as $class => $name) {
    if (!in_array('pa_' . $name, $identity['database']['owned_tables'] ?? [], true)) {
        throw new RuntimeException('Native identity table ownership is missing: ' . $name);
    }
    $modelFile = $identityRoot . '/src/Persistence/Model/' . $class . '.php';
    if (!is_file($modelFile)) {
        throw new RuntimeException('Native identity model is missing: ' . $class);
    }
    $model = (string) file_get_contents($modelFile);
    if (!preg_match('/protected\\s+\\$name\\s*=\\s*([\'"])' . preg_quote($name, '/') . '\\1\\s*;/', $model)) {
        throw new RuntimeException('Native identity model table differs: ' . $class);
    }
}

$admin = (string) file_get_contents($serverDir . '/app/adminapi/services/auth/AdminApplicationService.php');
foreach (['add' => 'createAdministrator', 'edit' => 'updateAdministrator'] as $method => $command) {
    if (!preg_match('/public function ' . $method . '\\(.*?(?=\\n    (?:\/\*\*|public function))/s', $admin, $match)) {
        throw new RuntimeException("Administrator method is missing: {$method}");
    }
    if (!str_contains($match[0], '$service->' . $command . '(')) {
        throw new RuntimeException("Administrator {$method} must use the Identity module atomic command");
    }
    foreach (['createPending', 'update', 'replaceRoles', 'activate', 'suspend', 'transitionStatus'] as $primitive) {
        if (preg_match('/(?:->|::)' . $primitive . '\\(/', $match[0])) {
            throw new RuntimeException("Administrator {$method} splits the atomic command: {$primitive}");
        }
    }
}

echo "Native Admin identity runtime contract passed.\n";
