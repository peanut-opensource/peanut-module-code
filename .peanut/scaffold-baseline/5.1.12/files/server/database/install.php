<?php

declare(strict_types=1);

use app\common\value\installation\ApplicationReleaseVersions;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use app\common\value\scaffold\EditionProfile;
use PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService;
use think\App;
use think\Container;

require_once __DIR__ . '/InitialInstallationSource.php';

$installerArguments = $_SERVER['argv'] ?? [];
$installerIsDirect = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;
function emitUnavailableInstallStatus(): void
{
    echo json_encode(['state' => 'blocked', 'code' => 'INSTALL_STATUS_UNAVAILABLE'], JSON_THROW_ON_ERROR), PHP_EOL;
}
if ($installerIsDirect && in_array('--preflight', $installerArguments, true)) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        // The preflight intentionally stops before App::initialize(), which normally loads native helpers.
        require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
        $application = new App(dirname(__DIR__));
        $application->loadEnv((string) $application->env->get('env_name', ''));
    }
    require_once dirname(__DIR__) . '/app/common/services/installation/InstallationPreflightHost.php';
    $preflight = (new \app\common\services\installation\InstallationPreflightHost(dirname(__DIR__)))->inspect();
    echo json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($preflight['status'] === 'ready' ? 0 : 1);
}

/**
 * Peanut Module Code fresh-database installer.
 *
 * Run from the repository root or server directory after configuring the
 * database connection in server/.env or a native PHP_ENV_NAME sibling:
 *
 *     php server/database/install.php
 */

// A source distribution intentionally does not contain vendor/. Report the
// prerequisite before reading configuration or touching any database.
if ($installerIsDirect && !is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    fwrite(STDERR, "尚未安装后端依赖。请在产品根目录运行：\n"
        . "  scripts/project-composer prepare\n"
        . "  scripts/project-composer install --working-dir=server --no-scripts\n"
        . "然后按公开安装文档配置环境，再运行此安装入口。\n");
    if (in_array('--status', $installerArguments, true)) {
        emitUnavailableInstallStatus();
        exit(1);
    }
    exit(2);
}

try {
    require_once __DIR__ . '/environment-guard.php';
} catch (Throwable $exception) {
    if (!$installerIsDirect || !in_array('--status', $installerArguments, true)) {
        throw $exception;
    }
    fwrite(STDERR, '安装失败：INSTALL_EXECUTION_FAILED' . PHP_EOL);
    emitUnavailableInstallStatus();
    exit(1);
}

function initialAdminPassword(string $serverDir): string
{
    $environment = env('ADMIN_INITIAL_PASSWORD', false);
    validateInitialAdminPassword($environment === false ? '' : $environment);
    return $environment === false ? '' : $environment;
}

function initialAdminEmail(string $serverDir): string
{
    $environment = env('ADMIN_INITIAL_EMAIL', false);
    $email = $environment === false ? '' : $environment;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('ADMIN_INITIAL_EMAIL 必须是有效邮箱');
    }
    return strtolower((string) $email);
}

function validateInitialAdminPassword(string $password): void
{
    if (env('PEANUT_DEMO_MODE', false) === 'enabled') {
        if ($password !== 'peanut1234') {
            throw new RuntimeException('演示模式的初始管理员密码必须统一为 peanut1234');
        }
        return;
    }
    Container::getInstance()->make(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class)->assertValid($password);
}

/** @return array{email:string,password:string}|null */
function initialPlatformCredentials(string $serverDir, string $adminEmail): ?array
{
    $environmentMode = env('DEPLOYMENT_MODE', false);
    $mode = $environmentMode === false ? '' : $environmentMode;
    if ($mode !== 'multi-tenant') {
        return null;
    }

    $environmentEmail = env('PLATFORM_INITIAL_EMAIL', false);
    $email = $environmentEmail === false ? '' : $environmentEmail;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须是有效邮箱');
    }
    $email = strtolower((string) $email);
    if (hash_equals($adminEmail, $email)) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须与 ADMIN_INITIAL_EMAIL 不同');
    }

    $environmentPassword = env('PLATFORM_INITIAL_PASSWORD', false);
    $password = $environmentPassword === false ? '' : $environmentPassword;
    if (env('PEANUT_DEMO_MODE', false) === 'enabled') {
        if ((string) $password !== 'peanut1234') {
            throw new RuntimeException('演示模式的 Platform 初始密码必须统一为 peanut1234');
        }
        return ['email' => $email, 'password' => (string) $password];
    }
    Container::getInstance()->make(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class)->assertValid((string) $password);

    return ['email' => $email, 'password' => (string) $password];
}

/**
 * @param array<string,mixed> $input
 * @return array{admin_email:string,admin_password:string,platform_credentials:array{email:string,password:string}|null}
 */
function normalizeInstallationCredentials(array $input): array
{
    $allowed = ['admin_email', 'admin_password', 'platform_email', 'platform_password'];
    $unknown = array_values(array_diff(array_keys($input), $allowed));
    if ($unknown !== []) {
        throw new RuntimeException('安装身份包含不支持的字段');
    }

    $adminEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('ADMIN_INITIAL_EMAIL 必须是有效邮箱');
    }
    $adminPassword = (string) ($input['admin_password'] ?? '');
    validateInitialAdminPassword($adminPassword);

    $mode = env('DEPLOYMENT_MODE', false);
    if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }
    if ($mode === 'standalone') {
        if (trim((string) ($input['platform_email'] ?? '')) !== ''
            || (string) ($input['platform_password'] ?? '') !== '') {
            throw new RuntimeException('standalone 安装不得提供 Platform 初始身份');
        }
        return [
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'platform_credentials' => null,
        ];
    }

    $platformEmail = strtolower(trim((string) ($input['platform_email'] ?? '')));
    if (filter_var($platformEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须是有效邮箱');
    }
    if (hash_equals($adminEmail, $platformEmail)) {
        throw new RuntimeException('PLATFORM_INITIAL_EMAIL 必须与 ADMIN_INITIAL_EMAIL 不同');
    }
    $platformPassword = (string) ($input['platform_password'] ?? '');
    if (env('PEANUT_DEMO_MODE', false) === 'enabled') {
        if ($platformPassword !== 'peanut1234') {
            throw new RuntimeException('演示模式的 Platform 初始密码必须统一为 peanut1234');
        }
    } else {
        Container::getInstance()->make(\PeanutAdmin\Kernel\Identity\PasswordPolicy::class)->assertValid($platformPassword);
    }

    return [
        'admin_email' => $adminEmail,
        'admin_password' => $adminPassword,
        'platform_credentials' => ['email' => $platformEmail, 'password' => $platformPassword],
    ];
}

/** @return array<string,string> */
function installationProcessInput(): array
{
    $path = getenv('PEANUT_INSTALLATION_ENV_FILE');
    if ($path === false || trim($path) === '') {
        return [];
    }
    $path = trim($path);
    if (!str_starts_with($path, '/') || !is_file($path) || is_link($path)) {
        throw new RuntimeException('INSTALLATION_ENVIRONMENT_FILE_INVALID');
    }
    $stat = lstat($path);
    if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1 || (($stat['mode'] ?? 0) & 0777) !== 0600) {
        throw new RuntimeException('INSTALLATION_ENVIRONMENT_PERMISSIONS_INVALID');
    }
    $values = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($values) || $values === []) {
        throw new RuntimeException('INSTALLATION_ENVIRONMENT_PARSE_FAILED');
    }
    $allowed = ['ADMIN_INITIAL_EMAIL', 'ADMIN_INITIAL_PASSWORD', 'PLATFORM_INITIAL_EMAIL',
        'PLATFORM_INITIAL_PASSWORD', 'PEANUT_DEMO_TENANT_ID'];
    foreach ($values as $key => $value) {
        if (!in_array($key, $allowed, true) || !is_string($value)) {
            throw new RuntimeException('INSTALLATION_ENVIRONMENT_INPUT_INVALID');
        }
    }
    return $values;
}

/** @return array{admin_email:string,admin_password:string,platform_email:string,platform_password:string} */
function installationCredentialsFromEnvironment(): array
{
    $input = installationProcessInput();
    return [
        'admin_email' => (string) ($input['ADMIN_INITIAL_EMAIL'] ?? env('ADMIN_INITIAL_EMAIL', '')),
        'admin_password' => (string) ($input['ADMIN_INITIAL_PASSWORD'] ?? env('ADMIN_INITIAL_PASSWORD', '')),
        'platform_email' => (string) ($input['PLATFORM_INITIAL_EMAIL'] ?? env('PLATFORM_INITIAL_EMAIL', '')),
        'platform_password' => (string) ($input['PLATFORM_INITIAL_PASSWORD'] ?? env('PLATFORM_INITIAL_PASSWORD', '')),
    ];
}

/** @return array<string,mixed> */
function automaticInstallationInput(): array
{
    $input = installationCredentialsFromEnvironment();
    $configuredModules = env('PEANUT_INSTALLATION_OFFICIAL_MODULES', false);
    if ($configuredModules !== false && trim($configuredModules) !== '') {
        $input['official_modules'] = array_values(array_filter(array_map(
            'trim',
            explode(',', $configuredModules),
        )));
    }
    return $input;
}

/** @return array<string, string> */
function brandWebsiteDefaults(string $serverDir): array
{
    $path = $serverDir . '/config/brand.json';
    $json = file_get_contents($path);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest)
        || ($manifest['schema_version'] ?? null) !== 1
        || !is_array($manifest['website'] ?? null)) {
        throw new RuntimeException('品牌默认配置格式错误');
    }
    $website = [];
    foreach ($manifest['website'] as $field => $value) {
        if (!is_string($field) || !is_string($value)) {
            throw new RuntimeException('品牌默认字段必须是字符串');
        }
        $website[$field] = $value;
    }
    return $website;
}

function sqlFiles(string $databaseDir): array
{
    return [$databaseDir . '/init.sql'];
}

function loadCoreRuntime(string $serverDir): void
{
    $autoload = $serverDir . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('缺少 Composer autoload，无法创建 Core Schema');
    }
    require_once $autoload;
    if (!class_exists(\think\migration\Migrator::class)) {
        throw new RuntimeException('缺少官方迁移依赖，请先按 server/composer.lock 执行 Composer install --no-scripts');
    }
}

/**
 * Resolve the Edition's required real-Tenant bootstrap contract.
 *
 * Generated applications are bound to the immutable application manifest. The
 * product source tree uses the same Edition profile directly for development.
 * Neither path may infer a default Tenant from a missing or mismatched contract.
 *
 * @return array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string}
 */
function installationTenantBootstrapContract(string $serverDir): array
{
    $mode = env('DEPLOYMENT_MODE', false);
    if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
        throw new RuntimeException('DEPLOYMENT_MODE 必须是 standalone 或 multi-tenant');
    }

    $serverIdentity = $serverDir . '/.peanut/release-identity.json';
    $projectRoot = dirname($serverDir);
    $manifestPath = $projectRoot . '/.peanut/application-manifest.json';
    if (file_exists($serverIdentity) || is_link($serverIdentity)) {
        $identity = ServerReleaseIdentity::resolve($serverDir);
        $contract = $identity->tenantBootstrapContract($mode);
    } elseif (ApplicationSourceIdentity::hasRuntime($serverDir)) {
        $identity = ApplicationSourceIdentity::load($serverDir);
        $contract = $identity->tenantBootstrapContract($mode);
    } elseif (file_exists($manifestPath) || is_link($manifestPath)) {
        $identity = ApplicationSourceIdentity::load($serverDir);
        $contract = $identity->tenantBootstrapContract($mode);
    } elseif (installationSourceDevelopmentMode($serverDir)) {
        $inventoryPath = $projectRoot . '/scaffold/application-template-inventory.json';
        $profilePath = $projectRoot . '/scaffold/edition-profiles.json';
        if (!is_file($inventoryPath) || is_link($inventoryPath)) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_MISSING');
        }
        $contract = EditionProfile::load($profilePath, $mode)->identity()['tenant_bootstrap'];
    } else {
        throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
    }

    $expected = [
        'kind' => 'real-default-tenant',
        'code' => 'default',
        'tenant_identity' => 'required',
        'rbac' => 'required',
        'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
        'module_lifecycle' => 'required',
    ];
    if ($contract !== $expected) {
        throw new RuntimeException('INSTALL_TENANT_BOOTSTRAP_CONTRACT_INVALID');
    }
    return $contract;
}

function installationSourceDevelopmentMode(string $serverDir): bool
{
    return InitialInstallationSource::sourceDevelopmentMode($serverDir);
}

function ensureThinkPhpApplication(string $serverDir): App
{
    $container = Container::getInstance();
    if ($container instanceof App) {
        return $container->initialized() ? $container : $container->initialize();
    }

    return (new App($serverDir))->initialize();
}

/**
 * @param array{email:string,password:string}|null $platformCredentials
 * @param array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string} $tenantBootstrap
 * @return array{tenant_id:int,account_id:int,member_id:int,operator_id:int}
 */
function initializeCoreIdentity(
    PDO $pdo,
    string $email,
    string $password,
    ?array $platformCredentials,
    \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy $demoAccounts,
    array $tenantBootstrap,
): array {
    $service = Container::getInstance()->make(BootstrapService::class);
    $separatePlatformOperator = $platformCredentials !== null;
    $demoBootstrapPassword = $demoAccounts->enabled()
        ? $demoAccounts->bootstrapPassword()
        : null;
    $platformPassword = $demoBootstrapPassword
        ?? ($platformCredentials['password'] ?? $password);
    $ownerPassword = $separatePlatformOperator
        ? ($demoBootstrapPassword ?? $password)
        : null;
    $platform = $service->bootstrapPlatformOwner(
        $platformCredentials['email'] ?? $email,
        $platformPassword,
        $separatePlatformOperator ? 'Platform Operator' : '超级管理员',
        'fresh-install-platform-owner',
    );
    $owner = $service->provisionTenantOwnerCandidate(
        $platform->operatorId,
        $tenantBootstrap['code'],
        'Peanut Module Code',
        $email,
        $ownerPassword,
        '超级管理员',
        'fresh-install-default-owner',
    );
    $service->activateTenantOwner(
        $platform->operatorId,
        $owner->tenantId,
        $owner->memberId,
        'fresh-install-default-owner-activate',
    );
    $service->activateTenant(
        $platform->operatorId,
        $owner->tenantId,
        'fresh-install-default-tenant-activate',
    );

    return [
        'tenant_id' => $owner->tenantId,
        'account_id' => $owner->accountId,
        'member_id' => $owner->memberId,
        'operator_id' => $platform->operatorId,
    ];
}

/** @param list<string> $emails */
function replaceInstalledDemoCredentialHashes(PDO $pdo, array $emails, string $hash): void
{
    $statement = $pdo->prepare(<<<'SQL'
UPDATE pa_credential
SET secret_hash = :secret_hash,
    failed_attempts = 0,
    locked_until = NULL,
    secret_changed_at = UTC_TIMESTAMP(3),
    revision = revision + 1,
    updated_at = UTC_TIMESTAMP(3)
WHERE identifier_type = 'email'
  AND kind = 'email_password'
  AND status = 'active'
  AND identifier_normalized = :email
SQL);
    foreach (array_values(array_unique(array_map('strtolower', $emails))) as $email) {
        if (trim($email) !== '') {
            $statement->execute(['secret_hash' => $hash, 'email' => trim($email)]);
        }
    }
}

/** @return array{tenant_count:int,owner_count:int,operator_count:int} */
function coreIdentityCounts(PDO $pdo, string $tenantCode): array
{
    $tenant = $pdo->prepare("SELECT COUNT(*) FROM pa_tenant WHERE code = ? AND status = 'active'");
    $tenant->execute([$tenantCode]);
    $owner = $pdo->prepare(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant t
JOIN pa_tenant_member tm ON tm.tenant_id = t.id AND tm.status = 'active'
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE t.code = ? AND t.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL);
    $owner->execute([$tenantCode]);
    return [
        'tenant_count' => (int) $tenant->fetchColumn(),
        'owner_count' => (int) $owner->fetchColumn(),
        'operator_count' => (int) $pdo->query(
            "SELECT COUNT(*) FROM pa_platform_operator WHERE status = 'active'",
        )->fetchColumn(),
    ];
}

/** @param array<string, string> $website */
function seedBrandDefaults(PDO $pdo, array $website): void
{
    $statement = $pdo->prepare(
        'INSERT INTO pa_config (type, name, value, create_time, update_time) '
        . "VALUES ('website', ?, ?, ?, ?) "
        . 'ON DUPLICATE KEY UPDATE value = VALUES(value), update_time = VALUES(update_time)',
    );
    $now = time();
    $pdo->beginTransaction();
    try {
        foreach ($website as $field => $value) {
            $statement->execute([$field, $value, $now, $now]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @return list<string> */
function applicationMigrationFiles(string $databaseDir): array
{
    $files = glob($databaseDir . '/migrations/*.php') ?: [];
    foreach ($files as $file) {
        if (!is_file($file) || is_link($file) || !\Phinx\Util\Util::isValidMigrationFileName(basename($file))) {
            throw new RuntimeException('迁移文件名无效：' . basename($file));
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Read source product, instance release-sequence and scaffold migration axes from the root contract.
 *
 * @return array{source_product_version:string,release_sequence_version:string,scaffold_template:string}
 */
function applicationReleaseVersions(string $serverDir): array
{
    loadCoreRuntime($serverDir);
    $serverIdentity = $serverDir . '/.peanut/release-identity.json';
    if (file_exists($serverIdentity) || is_link($serverIdentity)) {
        return ServerReleaseIdentity::resolve($serverDir)->versions();
    }
    if (ApplicationSourceIdentity::hasRuntime($serverDir)) {
        return ApplicationSourceIdentity::load($serverDir)->versions();
    }
    $projectRoot = dirname($serverDir);
    $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
    if (!installationSourceDevelopmentMode($serverDir)) {
        if (!file_exists($applicationManifest) && !is_link($applicationManifest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        if (!is_file($applicationManifest) || is_link($applicationManifest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
    }
    $contract = ApplicationReleaseVersions::load($projectRoot . '/release-versions.json');
    return [
        'source_product_version' => $contract->sourceProductVersion(),
        'release_sequence_version' => $contract->releaseSequenceVersion(),
        'scaffold_template' => $contract->scaffoldTemplate(),
    ];
}

/**
 * @return array{state:string,code:string,health:array<string,int>|null}
 */
function installationDatabaseState(string $serverDir): array
{
    loadCoreRuntime($serverDir);
    ensureThinkPhpApplication($serverDir);
    $connection = \think\facade\Db::connect();
    $connection->query('SELECT 1', [], true);
    $pdo = $connection->getPdo();
    if (!$pdo instanceof PDO || $pdo->inTransaction()) {
        throw new RuntimeException('INSTALL_NATIVE_MASTER_REQUIRED');
    }
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $connection->getConfig('database')) {
        throw new RuntimeException('INSTALL_NATIVE_DATABASE_IDENTITY_CHANGED');
    }
    $tableCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
    )->fetchColumn();
    if ($tableCount === 0) {
        return ['state' => 'uninstalled', 'code' => 'INSTALL_DATABASE_EMPTY', 'health' => null];
    }

    try {
        $health = assertCurrentDatabase($pdo);
        return ['state' => 'installed', 'code' => 'INSTALL_DATABASE_CURRENT', 'health' => $health];
    } catch (Throwable) {
        return ['state' => 'blocked', 'code' => 'INSTALL_DATABASE_PARTIAL', 'health' => null];
    }
}

function installationStateBlocksFreshDatabase(string $serverDir): bool
{
    foreach ([
        $serverDir . '/private/installation/installed.json',
        $serverDir . '/private/installation/migration.json',
        $serverDir . '/runtime/installation/executing.json',
        $serverDir . '/runtime/installation/installed.json',
        $serverDir . '/runtime/installation/baseline.json',
    ] as $path) {
        if (file_exists($path) || is_link($path)) {
            return true;
        }
    }
    return false;
}

/** @return array{cause_code?:string,module_key?:string,exit_code?:int} */
function installationFailureDiagnostic(string $serverDir, string $stage, Throwable $exception, ?string $currentModule = null): array
{
    $diagnostic = [];
    try {
        do {
            // Typed public identifiers are safe; messages, process output and paths are not.
            if (($exception instanceof \app\platform\exception\plugin\PluginLifecycleException
                    || $exception instanceof \app\platform\exception\plugin\PluginPackageException)
                && preg_match('/^(?:MODULE|PLUGIN)_[A-Z0-9_]{1,100}$/D', $exception->errorCode) === 1) {
                $diagnostic['cause_code'] = $exception->errorCode;
                if ($exception instanceof \app\platform\exception\plugin\PluginPackageException
                    && is_int($exception->details['exit_code'] ?? null)) {
                    $diagnostic['exit_code'] = $exception->details['exit_code'];
                }
                break;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);
        if ($diagnostic === []) {
            return $diagnostic;
        }
        // Independently bind the Host's current iteration to its protected selection.
        $progressPath = rtrim($serverDir, DIRECTORY_SEPARATOR) . '/private/installation/executing.json';
        $stat = @lstat($progressPath);
        if (is_string($currentModule) && preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_.-]*$/D', $currentModule) === 1
            && realpath($progressPath) === $progressPath && is_file($progressPath) && !is_link($progressPath)
            && is_array($stat) && $stat['nlink'] === 1 && ($stat['mode'] & 0777) === 0600
            && in_array($stat['uid'], [0, fileowner($serverDir)], true) && $stat['size'] <= 65536) {
            $progress = json_decode((string) file_get_contents($progressPath), true, 32);
            if (is_array($progress) && ($progress['schema_version'] ?? null) === 1
                && ($progress['state'] ?? null) === 'executing'
                && is_array($progress['official_modules'] ?? null)
                && array_is_list($progress['official_modules'])
                && in_array($currentModule, $progress['official_modules'], true)) {
                $diagnostic['module_key'] = $currentModule;
            }
        }
    } catch (Throwable) {
        // Optional context must never replace the original failure or typed cause.
    }
    return $diagnostic;
}

/** Record only diagnostic identifiers; exception messages and debug data can contain credentials. */
function logInstallationFailure(string $serverDir, string $stage, string $errorCode, Throwable $exception, ?string $currentModule = null): void
{
    try {
        $chain = [];
        do {
            $entry = ['class' => $exception::class];
            if ($exception instanceof PDOException) {
                $entry['sqlstate'] = $exception->errorInfo[0] ?? $exception->getCode();
                $entry['driver_code'] = $exception->errorInfo[1] ?? null;
            }
            $chain[] = $entry;
            $exception = $exception->getPrevious();
        } while ($exception !== null);
        \think\facade\Log::write(json_encode([
            'event' => 'installation_failure', 'stage' => $stage, 'error_code' => $errorCode,
            'module_key' => $currentModule, 'exception_chain' => $chain,
        ], JSON_THROW_ON_ERROR), 'error');
    } catch (Throwable) {
        // Diagnostics must not replace the original installation failure.
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function installFreshDatabase(string $serverDir, array $input, ?array $moduleKeys = null): array
{
    if (installationStateBlocksFreshDatabase($serverDir)) {
        throw new RuntimeException('INSTALL_STATE_PRESENT: 安装身份状态已存在，拒绝再次初始化数据库');
    }
    $databaseDir = $serverDir . '/database';
    loadCoreRuntime($serverDir);
    ensureThinkPhpApplication($serverDir);
    $tenantBootstrap = installationTenantBootstrapContract($serverDir);
    $credentials = normalizeInstallationCredentials($input);
    $connection = \think\facade\Db::connect();
    $database = $connection->getConfig('database');
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
        throw new RuntimeException('DB_NAME 只能包含字母、数字和下划线');
    }

    if ($connection->getConfig('prefix') !== 'pa_' || (int) $connection->getConfig('deploy') !== 0
        || $connection->getConfig('break_reconnect') !== false) { throw new RuntimeException('INSTALL_NATIVE_DATABASE_CONFIG_CONFLICT'); }
    $connection->query('SELECT 1', [], true);
    $pdo = $connection->getPdo();
    if (!$pdo instanceof PDO || $pdo->inTransaction()) { throw new RuntimeException('INSTALL_NATIVE_MASTER_REQUIRED'); }
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) { throw new RuntimeException('INSTALL_NATIVE_DATABASE_IDENTITY_CHANGED'); }

    $lockName = 'peanut_install_' . substr(hash('sha256', $database), 0, 48);
    $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lockStatement->execute([$lockName]);
    $lockAcquired = (int) $lockStatement->fetchColumn() === 1;
    $lockStatement->closeCursor();
    if (!$lockAcquired) {
        throw new RuntimeException('无法获取安装锁，请稍后重试');
    }

    $installationFailure = null;
    try {
        if (installationStateBlocksFreshDatabase($serverDir)) {
            throw new RuntimeException('INSTALL_STATE_PRESENT: 安装身份状态已存在，拒绝再次初始化数据库');
        }
        $initial = InitialInstallationSource::project($serverDir, $moduleKeys ?? InitialInstallationSource::modules($serverDir));
        $initialRoot = $serverDir . '/runtime/temp/installation-database';
        foreach (['schema', 'migrations'] as $directory) {
            if (!is_dir($initialRoot . '/' . $directory) && !mkdir($initialRoot . '/' . $directory, 0775, true)) {
                throw new RuntimeException('INSTALL_INITIAL_DIRECTORY_FAILED');
            }
        }
        foreach (glob($initialRoot . '/migrations/*.php') ?: [] as $file) {
            if (!unlink($file)) {
                throw new RuntimeException('INSTALL_INITIAL_MIGRATION_CLEANUP_FAILED');
            }
        }
        foreach ($initial as $path => $content) {
            if (file_put_contents($initialRoot . '/' . $path, $content) !== strlen($content)) {
                throw new RuntimeException('INSTALL_INITIAL_WRITE_FAILED');
            }
        }
        $sources = [];
        foreach (glob($initialRoot . '/migrations/*.php') ?: [] as $file) {
            $sources[] = ['file' => $file];
        }
        foreach (glob($databaseDir . '/migrations/*.php') ?: [] as $file) {
            $target = $initialRoot . '/migrations/' . basename($file);
            if (!copy($file, $target)) {
                throw new RuntimeException('INSTALL_INITIAL_MIGRATION_COPY_FAILED');
            }
            $sources[] = ['file' => $target];
        }
        $selectedKeys = $moduleKeys ?? InitialInstallationSource::modules($serverDir);
        $expected = InitialInstallationSource::tables($serverDir, $selectedKeys);
        $expected[] = (string) $connection->getConfig('prefix') . \think\facade\Config::get('database.migration_table', 'migrations');
        $tableCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        )->fetchColumn();
        if ($tableCount !== 0) {
            throw new RuntimeException('目标数据库不是空库，已拒绝执行首次安装');
        }

        $adminEmail = $credentials['admin_email'];
        $adminPassword = $credentials['admin_password'];
        $platformCredentials = $credentials['platform_credentials'];
        $demoAccounts = new \PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy(
            env('PEANUT_DEMO_MODE', false) === 'enabled',
            array_values(array_filter([
                $adminEmail,
                $platformCredentials['email'] ?? '',
            ])),
        );
        $application = ensureThinkPhpApplication($serverDir);
        $selectedManifests = array_filter(
            $application->make(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->modules,
            static fn(\PeanutAdmin\Kernel\Module\ManifestDocument $manifest): bool => in_array($manifest->data['key'], $selectedKeys, true),
        );
        $sources = array_merge($sources, \app\common\module\ModuleMigrationRun::sources($selectedManifests, $serverDir));
        // Only source discovery changes. The existing native runner owns execution
        // and the original migration versions in the normal migrations ledger.
        $migration = new \app\common\module\ModuleMigrationRun($sources);
        $migration->setApp($application);
        $migration->run(new \think\console\Input([]), new \think\console\Output('buffer'));
        $coreIdentity = initializeCoreIdentity($pdo, $adminEmail, $adminPassword, $platformCredentials, $demoAccounts, $tenantBootstrap);
        if ($demoAccounts->enabled()) {
            replaceInstalledDemoCredentialHashes($pdo, [$adminEmail, $platformCredentials['email'] ?? ''], $demoAccounts->credentialHash());
        }
        $application->console->call('seed:run', ['--seed=InitialSeeder']);
        seedBrandDefaults($pdo, brandWebsiteDefaults($serverDir));
        require_once $databaseDir . '/WorkbenchDemoData.php';
        WorkbenchDemoData::seed($application, (int) $coreIdentity['tenant_id']);
        if (\think\facade\Db::connect() !== $connection || $connection->getPdo() !== $pdo || $pdo->inTransaction()) {
            throw new RuntimeException('INSTALL_NATIVE_MASTER_CHANGED');
        }
        $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) { throw new RuntimeException('INSTALL_DATABASE_LOCK_LOST'); }
        $lock->closeCursor();

        $actual = array_map('strval', $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        )->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($expected, $actual));
        $activeMenus = (int) $pdo->query('SELECT COUNT(*) FROM pa_system_menu')->fetchColumn();
        $configCount = (int) $pdo->query('SELECT COUNT(*) FROM pa_config')->fetchColumn();
        $identityCounts = coreIdentityCounts($pdo, $tenantBootstrap['code']);
        if ($missing !== []
            || $activeMenus === 0
            || $configCount === 0
            || $identityCounts !== ['tenant_count' => 1, 'owner_count' => 1, 'operator_count' => 1]) {
            throw new RuntimeException('安装结果不完整');
        }

        return [
            'database' => $database,
            'migration' => 'native',
            'tables' => count($actual),
            'expected_tables' => count($expected),
            'active_menus' => $activeMenus,
            'configs' => $configCount,
            'tenant_bootstrap' => $tenantBootstrap,
            'default_tenant_id' => $coreIdentity['tenant_id'],
            'owner_account_id' => $coreIdentity['account_id'],
            'owner_member_id' => $coreIdentity['member_id'],
        ];
    } catch (Throwable $exception) {
        $installationFailure = $exception;
        throw $exception;
    } finally {
        try {
            $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStatement->execute([$lockName]);
        } catch (Throwable $exception) {
            logInstallationFailure($serverDir, 'release_database_lock', 'INSTALL_LOCK_RELEASE_FAILED', $exception);
            if ($installationFailure === null) {
                throw $exception;
            }
        }
    }
}

function main(): int
{
    $result = installFreshDatabase(dirname(__DIR__), installationCredentialsFromEnvironment());
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    return 0;
}

if ($installerIsDirect) {
    try {
        $application = ensureThinkPhpApplication(dirname(__DIR__));
        $host = $application->make(\app\common\services\installation\InstallationExecutionHost::class);
        if (in_array('--status', $_SERVER['argv'] ?? [], true)) {
            $status = $host->status();
            echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
            exit($status['state'] === 'installed' ? 0 : 1);
        }
        $status = $host->status();
        if ($status['state'] === 'installed'
            && in_array('--skip-if-installed', $_SERVER['argv'] ?? [], true)) {
            echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
            exit(0);
        }
        $result = $application->make(\app\common\execution\ExecutionContextStore::class)->run(
            new \app\common\execution\InstallationExecutionContext(
                'installation.console.execute',
                'install-' . bin2hex(random_bytes(16)),
            ),
            static fn() => $host->executeAutomatic(automaticInstallationInput()),
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    } catch (\app\common\exception\installation\InstallationExecutionException $exception) {
        fwrite(STDERR, '安装失败：' . $exception->errorCode . PHP_EOL);
        if (in_array('--status', $_SERVER['argv'] ?? [], true)) {
            emitUnavailableInstallStatus();
        }
        exit(1);
    } catch (Throwable $exception) {
        $code = 'INSTALL_EXECUTION_FAILED';
        fwrite(STDERR, '安装失败：' . $code . PHP_EOL);
        if (in_array('--status', $_SERVER['argv'] ?? [], true)) {
            emitUnavailableInstallStatus();
        }
        exit(1);
    }
}
