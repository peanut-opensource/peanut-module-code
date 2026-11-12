<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('缺少 Composer autoload，无法校验应用数据库');
}
require_once $autoload;

// Standalone database commands need native Env before any application services.
$application = \think\Container::getInstance();
if (!$application instanceof \think\App) {
    $application = new \think\App(dirname(__DIR__));
}
require_once $application->getThinkPath() . 'helper.php';
if (!$application->initialized()) {
    $application->loadEnv((string) $application->env->get('env_name', ''));
}

function requiredEnvironment(string $name, string|false $default = false): string
{
    $value = env($name, $default);
    if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '') {
        throw new RuntimeException("缺少环境配置：{$name}");
    }
    return trim((string) $value);
}

/** Maintenance selections and lease handles are process inputs, never APP .env fields. */
function requiredMaintenanceEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("缺少维护进程输入：{$name}");
    }
    return trim($value);
}

require_once __DIR__ . '/resource-registry.php';

function guardedDatabaseConfig(?string $leaseProofPath = null, ?int $now = null): array
{
    $registry = projectResourceRegistry($registrySha256);
    $environment = requiredEnvironment('APP_ENV');
    $deploymentTarget = requiredMaintenanceEnvironment('PEANUT_DEPLOYMENT_TARGET');
    $target = deploymentTargetContract($deploymentTarget);
    if (!hash_equals($target['app_environment'], $environment)) {
        throw new RuntimeException("APP_ENV={$environment} 与部署目标 {$deploymentTarget} 不匹配");
    }

    $resourceId = requiredMaintenanceEnvironment('PEANUT_DATABASE_RESOURCE_ID');
    $database = registeredDatabase($registry, $resourceId);
    $registeredName = $database['database'] ?? null;
    $isTemplated = is_string($registeredName)
        && (str_contains($registeredName, '<run_id>') || str_contains($registeredName, '<scenario>'));
    $environments = $database['environments'] ?? [$database['environment'] ?? null];
    $resourceEnvironment = $isTemplated ? 'development' : $target['resource_environment'];
    if (!is_array($environments) || !in_array($resourceEnvironment, $environments, true)) {
        throw new RuntimeException("数据库资源 {$resourceId} 未登记为 {$target['resource_environment']} 环境");
    }
    if (($database['service_type'] ?? null) !== 'mysql' || ($database['fallback'] ?? null) !== 'none') {
        throw new RuntimeException("数据库资源 {$resourceId} 的服务类型或 fallback 不符合门禁");
    }

    $consumer = requiredMaintenanceEnvironment('PEANUT_DATABASE_CONSUMER');
    $endpoint = registeredDatabaseEndpoint($database, $consumer);
    $configuredEndpointId = getenv('PEANUT_DATABASE_ENDPOINT_ID');
    if ($configuredEndpointId !== false && trim($configuredEndpointId) !== ''
        && !hash_equals((string) $endpoint['endpoint_id'], trim($configuredEndpointId))) {
        throw new RuntimeException("数据库资源 {$resourceId} 的 endpoint identity 不匹配登记值");
    }

    $actual = [
        'host' => requiredEnvironment('DB_HOST', '127.0.0.1'),
        'port' => requiredEnvironment('DB_PORT', '3306'),
        'database' => requiredEnvironment('DB_NAME'),
    ];
    if (!hash_equals((string) $endpoint['host'], $actual['host'])
        || !hash_equals((string) $endpoint['port'], $actual['port'])) {
        throw new RuntimeException("数据库资源 {$resourceId} 的地址不匹配登记值");
    }
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if ($isTemplated || $deploymentTarget === 'development-test') {
        throw new RuntimeException('Qualification resources are not application runtime databases');
    } else {
        if (!is_string($registeredName) || !hash_equals($registeredName, $actual['database'])) {
            throw new RuntimeException("数据库资源 {$resourceId} 的 database 不匹配固定登记值");
        }
        $deploymentModes = $database['deployment_modes'] ?? ['standalone'];
        if (!is_array($deploymentModes)
            || $deploymentModes === []
            || array_filter($deploymentModes, static fn(mixed $mode): bool => !is_string($mode) || $mode === '') !== []) {
            throw new RuntimeException("数据库资源 {$resourceId} 的 deployment_modes 登记无效");
        }
        if (!in_array($deploymentMode, $deploymentModes, true)) {
            throw new RuntimeException("数据库资源 {$resourceId} 不允许 DEPLOYMENT_MODE={$deploymentMode}");
        }
        if (isset($database['lease_policy']['shared_access'])) {
            if ($deploymentTarget !== 'local-development' || $consumer !== 'host') {
                throw new RuntimeException('shared schema runtime 只允许登记的 local-development Host');
            }
        }
    }

    return [
        'environment' => $environment,
        'deployment_target' => $deploymentTarget,
        'resource_id' => $resourceId,
        'endpoint_id' => (string) $endpoint['endpoint_id'],
        'consumer' => $consumer,
        ...$actual,
        'user' => requiredEnvironment('DB_USER'),
        'password' => requiredEnvironment('DB_PASS'),
    ];
}

function guardedConnection(array $config, ?int $connectTimeout = null): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if ($connectTimeout !== null) {
        if ($connectTimeout < 1 || $connectTimeout > 5) { throw new RuntimeException('Invalid bounded connection timeout'); }
        $options[PDO::ATTR_TIMEOUT] = $connectTimeout;
    }
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        ),
        $config['user'],
        $config['password'],
        $options,
    );
}

function waitForDatabase(array $config, int $seconds): PDO
{
    $deadline = time() + $seconds;
    do {
        try {
            return guardedConnection($config);
        } catch (PDOException $exception) {
            if (time() >= $deadline) {
                throw new RuntimeException('数据库资源在等待窗口内不可连接', 0, $exception);
            }
            sleep(2);
        }
    } while (true);
}

/** @return list<string> */
function canonicalBaselineTables(): array
{
    require_once __DIR__ . '/InitialInstallationSource.php';
    $serverRoot = dirname(__DIR__);
    $names = InitialInstallationSource::tables($serverRoot, InitialInstallationSource::installedModules($serverRoot));
    sort($names, SORT_STRING);
    return $names;
}

/** @param array<string,list<string>> $requirements */
function assertRequiredColumns(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    );
    foreach ($requirements as $table => $expected) {
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException("数据库关键列缺失：{$table}." . implode(', ', $missing));
        }
    }
}

/** @param array<string,list<string>> $requirements */
function assertRequiredIndexes(PDO $pdo, array $requirements): void
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
    );
    foreach ($requirements as $table => $expected) {
        $statement->execute([$table]);
        $actual = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        if ($missing !== []) {
            throw new RuntimeException("数据库关键索引缺失：{$table}." . implode(', ', $missing));
        }
    }
}

/** @return array{baseline_table_count:int,installed_table_count:int,application_migration_count:int,management_member_count:int,menu_count:int,config_count:int,permission_count:int,tenant_count:int,owner_count:int,operator_count:int} */
function assertCurrentDatabase(PDO $pdo): array
{
    $expected = canonicalBaselineTables();
    // The native command defaults to "migrations"; this application's database
    // configuration sets only DB_PREFIX and does not override migration_table.
    $migrationTable = requiredEnvironment('DB_PREFIX', 'pa_') . 'migrations';
    $expected[] = $migrationTable;
    $actual = array_map('strval', $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
    )->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($expected, $actual));
    if ($missing !== []) {
        throw new RuntimeException('数据库缺少初始应用表：' . implode(', ', $missing));
    }

    assertRequiredColumns($pdo, [
        'pa_account' => ['id', 'status', 'security_revision'],
        'pa_credential' => ['account_id', 'identifier_normalized', 'secret_hash', 'status'],
        'pa_tenant' => ['id', 'code', 'status', 'authorization_revision'],
        'pa_tenant_member' => ['tenant_id', 'account_id', 'status', 'authorization_revision'],
        'pa_permission' => ['key', 'module_key', 'status'],
        'pa_system_menu' => ['id', 'type', 'perms', 'component'],
        'pa_config' => ['type', 'name', 'value'],
        $migrationTable => ['version', 'migration_name', 'start_time', 'end_time', 'breakpoint'],
    ]);
    assertRequiredIndexes($pdo, [
        'pa_tenant' => ['PRIMARY', 'uk_tenant_code'],
        'pa_tenant_member' => ['PRIMARY', 'uk_tenant_member_account'],
        'pa_permission' => ['PRIMARY', 'uk_permission_key'],
        'pa_system_menu' => ['PRIMARY'],
        'pa_config' => ['PRIMARY', 'uk_type_name'],
        $migrationTable => ['PRIMARY'],
    ]);

    $menuCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
    $configCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
    $permissionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_permission WHERE status = 'active'",
    )->fetchColumn();
    $quotedMigrationTable = '`' . str_replace('`', '``', $migrationTable) . '`';
    $appliedVersions = array_map('strval', $pdo->query('SELECT version FROM ' . $quotedMigrationTable)->fetchAll(PDO::FETCH_COLUMN));
    $expectedVersions = [];
    foreach (\app\common\module\ModuleMigrationRun::sources([], dirname(__DIR__), true) as $source) {
        $expectedVersions[] = $source['migration_key'];
    }
    if ($expectedVersions === [] || array_diff($expectedVersions, $appliedVersions) !== []) {
        throw new RuntimeException('数据库原生迁移尚未完成');
    }
    $applicationMigrationCount = count($appliedVersions);
    $tenantCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pa_tenant WHERE code = 'default' AND status = 'active'",
    )->fetchColumn();
    $ownerCount = (int) $pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE t.code = 'default' AND t.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL)->fetchColumn();
    $operatorCount = (int) $pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT po.id)
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
JOIN pa_platform_role pr ON pr.id = por.platform_role_id
WHERE po.status = 'active'
  AND pr.`key` = 'platform.bootstrap-owner'
  AND pr.is_builtin = 1
  AND pr.status = 'active'
SQL)->fetchColumn();
    $deploymentMode = requiredEnvironment('DEPLOYMENT_MODE');
    if (!in_array($deploymentMode, ['standalone', 'multi-tenant'], true)) {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }
    if ($menuCount < 1
        || $configCount < 1
        || $permissionCount < 1
        || $tenantCount !== 1
        || $ownerCount < 1
        || ($deploymentMode === 'multi-tenant' && $operatorCount < 1)) {
        throw new RuntimeException('数据库基线数据不完整');
    }

    return [
        'baseline_table_count' => count($expected),
        'installed_table_count' => count($actual),
        'application_migration_count' => $applicationMigrationCount,
        'management_member_count' => $ownerCount,
        'menu_count' => $menuCount,
        'config_count' => $configCount,
        'permission_count' => $permissionCount,
        'tenant_count' => $tenantCount,
        'owner_count' => $ownerCount,
        'operator_count' => $operatorCount,
    ];
}

/** @param list<string> $arguments */
function environmentGuardMain(array $arguments): int
{
    try {
        $config = guardedDatabaseConfig();
        $validateConfigOnly = in_array('--validate-config', $arguments, true);
        $wait = 0;
        foreach ($arguments as $argument) {
            if (preg_match('/^--wait=(\d+)$/D', (string) $argument, $matches) === 1) {
                $wait = min(300, max(0, (int) $matches[1]));
            }
        }
        $result = [
            'environment' => $config['environment'],
            'deployment_target' => $config['deployment_target'],
            'resource_id' => $config['resource_id'],
            'endpoint_id' => $config['endpoint_id'],
            'consumer' => $config['consumer'],
            'host' => $config['host'],
            'port' => (int) $config['port'],
            'database' => $config['database'],
            'status' => 'validated',
        ];
        if (!$validateConfigOnly) {
            $pdo = $wait > 0 ? waitForDatabase($config, $wait) : guardedConnection($config);
            $result['status'] = 'connected';
            if (in_array('--current', $arguments, true)) {
                $result += assertCurrentDatabase($pdo);
                $result['status'] = 'current';
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, '数据库环境门禁失败：' . $exception->getMessage() . PHP_EOL);
        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(environmentGuardMain($_SERVER['argv'] ?? []));
}
