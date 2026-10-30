<?php

declare(strict_types=1);

namespace app\common\services\installation;

use app\common\module\ModuleMigrationRun;
use app\common\exception\installation\InstallationExecutionException;
use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use app\platform\infrastructure\module\ThinkPhpModuleGovernanceProvider;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
use PeanutAdmin\Modules\Identity\Platform\Application\PlanLicenseAdminService;
use PDO;
use RuntimeException;
use think\db\PDOConnection;
use think\facade\Config;
use think\facade\Db;
use Throwable;

/** The only application-owned fresh installation execution runtime. */
final class InstallationExecutionHost
{
    private const MODES = ['guided', 'automatic'];
    private ?string $currentInstallationModule = null;
    private readonly string $serverRoot;

    public function __construct(
        string $serverRoot,
        private readonly ModuleCatalogApplier $catalogs,
        private readonly TenantModuleStateQueries $moduleStates,
        private readonly PlanLicenseAdminService $licenses,
        private readonly CompiledModuleRegistry $definitions,
    ) {
        $this->serverRoot = rtrim($serverRoot, DIRECTORY_SEPARATOR);
        require_once $this->serverRoot . '/database/install.php';
    }

    /** The normal request gate checks only the physical completion lock. */
    public function isInstalled(): bool
    {
        return $this->completionLockPresent();
    }

    /** @return array{installed:bool,deployment_mode:string} */
    public function entryStatus(): array
    {
        return [
            'installed' => $this->completionLockPresent(),
            'deployment_mode' => $this->deploymentMode(),
        ];
    }

    /** Environment inspection precedes instance configuration and database use. */
    public function environment(): array
    {
        $report = (new InstallationPreflightHost($this->serverRoot))->inspect(false);
        return $report + ['stage' => 'environment', 'writes' => ['outcome' => 'none'],
            'safe_retry' => true, 'completion_lock_present' => $this->completionLockPresent()];
    }

    /** Read-only database check and permission diagnostics on the actual master PDO. */
    public function databaseCheck(): array
    {
        return $this->databaseCheckForStatus($this->status());
    }

    private function databaseCheckForStatus(array $status): array
    {
        if ($status['state'] !== 'uninstalled' || !$status['safe_retry']) {
            return $status + ['permissions_verified' => false];
        }
        try {
            $connection = Db::connect();
            $connection->query('SELECT 1', [], true);
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO || $pdo->inTransaction()) {
                throw new RuntimeException('INSTALL_DATABASE_CONNECTION_UNAVAILABLE');
            }
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($database) || $database === '') {
                throw new RuntimeException('INSTALL_DATABASE_IDENTITY_UNKNOWN');
            }
            if ($database !== $connection->getConfig('database')) {
                throw new RuntimeException('INSTALL_DATABASE_CONNECTION_MISMATCH');
            }
        } catch (Throwable $exception) {
            $reason = $exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'INSTALL_DATABASE_')
                ? $exception->getMessage() : 'INSTALL_DATABASE_CONNECTION_UNAVAILABLE';
            return [...$status, 'category' => 'unknown', 'stage' => 'database_check',
                'reason' => $reason, 'safe_retry' => false,
                'writes' => [...$status['writes'], 'database' => 'none'],
                'permissions_verified' => false, 'database_check' => 'unknown',
                'remedy' => '核对实际连接及库身份后重新检查；没有执行安装或授予权限。'];
        }

        $required = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES', 'CREATE TEMPORARY TABLES'];
        $observed = [];
        try {
            $scope = '`' . str_replace('`', '``', $database) . '`.*';
            $escapedScope = '`' . strtr($database, ['\\' => '\\\\', '`' => '``', '_' => '\\_', '%' => '\\%']) . '`.*';
            foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM) as $row) {
                // SHOW GRANTS can escape literal schema characters; accept only
                // the exact current schema spelling, never expand a pattern.
                // Roles, other wildcard schema patterns and unrecognised grants are
                // never guessed into effective permission. They remain unknown.
                if (!preg_match('/^GRANT (.+) ON (.+) TO /D', $row[0], $match)
                    || !in_array($match[2], ['*.*', $scope, $escapedScope], true)) {
                    continue;
                }
                if ($match[1] === 'ALL PRIVILEGES') {
                    $observed = $required;
                    break;
                }
                $observed = array_merge($observed, explode(', ', $match[1]));
            }
        } catch (Throwable) {
            // Grant text is diagnostic only; native migrations report effective SQL permission failures.
            $observed = [];
        }
        $missing = array_values(array_diff($required, $observed));
        return $status + ['permissions_verified' => $missing === [], 'permissions_missing_or_unconfirmed' => $missing,
            'database_check' => 'ready',
            'permission_remedy' => $missing === [] ? null : '权限文本未能确认全部权限（可能由角色授予）；仅供诊断，不阻止安装。实际权限错误由原生迁移报告，本检查不授权或写入数据库。'];
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $status = $this->inspectStatus();
        $code = (string) ($status['code'] ?? 'INSTALL_STATE_UNKNOWN');
        $locked = $this->completionLockPresent();
        $localMarkers = file_exists($this->progressMarker()) || is_link($this->progressMarker());
        $resume = $code === 'INSTALL_RESUME_READY';
        $partial = $localMarkers
            || $code === 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD';
        $existingInstallation = $locked || $code === 'INSTALL_COMPLETION_LOCK_MISSING';
        $unknown = str_contains($code, 'UNAVAILABLE');
        $category = $status['state'] === 'installed' ? 'installed'
            : ($existingInstallation ? 'installed_fault' : ($unknown ? 'unknown' : ($partial ? 'partial' : 'prerequisite')));
        return $status + [
            'category' => $category,
            'stage' => $existingInstallation ? 'runtime' : ($resume ? 'install_modules' : ($partial ? 'execution'
                : ($code === 'INSTALL_PREFLIGHT_BLOCKED' ? 'environment' : 'database_check'))),
            'reason' => $code,
            'remedy' => $existingInstallation
                ? '恢复现有实例配置或数据库；不要删除完成锁来修复运行故障。'
                : ($resume ? '原生初始化已完成；按原模块选择继续安装，不重新初始化数据库或账号。'
                    : ($partial ? '保留现有数据库和执行记录，核对部分完成事实后由资源 owner 处理；不自动清库或重建身份。'
                    : '按环境、配置和数据库检查的具体诊断修复后重新检查。')),
            'writes' => ['status_request' => 'none', 'database_existing_state' => $category === 'partial' ? 'nonempty_or_partial' : ($existingInstallation ? 'installed_or_faulted' : 'see_status'), 'completion_lock_present' => $locked,
                'residual_origin' => $localMarkers ? 'local_markers_present_identity_not_inferred' : 'unconfirmed_existing_database_origin',
                'progress_present' => is_file($this->progressMarker())],
            'safe_retry' => !$locked && ($resume || !$partial) && $status['state'] === 'uninstalled',
            'completion_lock' => 'server/private/installation/installed.json',
        ];
    }

    /** No status request creates, replaces or removes installation anchors. */
    private function inspectStatus(): array
    {
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $preflight = (new InstallationPreflightHost($this->serverRoot))->inspect();
        $base = [
            'mode' => $this->mode(),
            'deployment_mode' => $this->deploymentMode(),
            'tenant_bootstrap' => $tenantBootstrap,
            'preflight' => $preflight,
            'official_modules' => $this->officialModules(),
        ];
        $complete = $this->completionLockPresent();
        if (($preflight['status'] ?? null) !== 'ready') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PREFLIGHT_BLOCKED',
                'retryable' => !$complete,
                'health' => null,
            ];
        }

        if ($complete && !$this->completionReceiptValid()) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_COMPLETION_LOCK_INVALID',
                'retryable' => false,
                'health' => null,
            ];
        }

        try {
            $database = \installationDatabaseState($this->serverRoot);
        } catch (Throwable) {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => $complete ? 'INSTALL_LOCKED_DATABASE_UNAVAILABLE' : 'INSTALL_DATABASE_UNAVAILABLE',
                'retryable' => !$complete,
                'health' => null,
            ];
        }

        $progress = is_file($this->progressMarker());
        if ($complete) {
            if ($database['state'] !== 'installed') {
                return [
                    ...$base,
                    'state' => 'blocked',
                    'code' => 'INSTALL_LOCKED_DATABASE_MISMATCH',
                    'retryable' => false,
                    'health' => null,
                ];
            }
            return [
                ...$base,
                'state' => 'installed',
                'code' => 'INSTALL_ALREADY_COMPLETED',
                'retryable' => false,
                'health' => $database['health'],
            ];
        }
        if ($progress && $database['state'] !== 'uninstalled') {
            try {
                $checkpoint = $this->initializedCheckpoint();
                return [...$base, 'state' => 'uninstalled', 'code' => 'INSTALL_RESUME_READY',
                    'retryable' => true, 'health' => $checkpoint['health'],
                    'resume_stage' => 'install_modules', 'official_module_selection' => $checkpoint['official_modules']];
            } catch (Throwable $exception) {
                // An unknown source or incomplete native initialization is never replayed.
                $resumeBlocker = preg_match('/^INSTALL_[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                    ? $exception->getMessage() : 'INSTALL_RESUME_STATE_UNCONFIRMED';
            }
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
                'resume_blocker' => $resumeBlocker,
                'retryable' => false,
                'health' => null,
            ];
        }
        if ($database['state'] === 'uninstalled') {
            if ($this->completionLockPresent()) {
                return [
                    ...$base,
                    'state' => 'blocked',
                    'code' => 'INSTALL_LOCKED_DATABASE_MISMATCH',
                    'retryable' => false,
                    'health' => null,
                ];
            }
            return [
                ...$base,
                'state' => 'uninstalled',
                'code' => $progress ? 'INSTALL_RETRY_READY' : 'INSTALL_READY',
                'retryable' => true,
                'health' => null,
            ];
        }
        if ($database['state'] === 'installed') {
            return [
                ...$base,
                'state' => 'blocked',
                'code' => 'INSTALL_COMPLETION_LOCK_MISSING',
                'retryable' => false,
                'health' => null,
            ];
        }

        return [
            ...$base,
            'state' => 'blocked',
            'code' => 'INSTALL_PARTIAL_STATE_REQUIRES_REBUILD',
            'retryable' => false,
            'health' => null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function executeGuided(string $token, array $input): array
    {
        if ($this->mode() !== 'guided') {
            throw new InstallationExecutionException(
                'INSTALL_GUIDED_MODE_DISABLED',
                '当前部署未启用 guided 安装模式。',
                403,
            );
        }
        $expected = (string) Config::get('peanut.installation.setup_token', '');
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $expected) !== 1
            || $token === ''
            || !hash_equals($expected, $token)) {
            throw new InstallationExecutionException(
                'INSTALL_SETUP_TOKEN_INVALID',
                'Setup token 无效。',
                403,
            );
        }
        return $this->execute($input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function executeAutomatic(array $input): array
    {
        if ($this->mode() !== 'automatic') {
            throw new InstallationExecutionException(
                'INSTALL_AUTOMATIC_MODE_DISABLED',
                '当前部署未启用 automatic 安装模式。',
                409,
            );
        }
        return $this->execute($input);
    }

    private function execute(array $input): array
    {
        $lock = $this->acquireExecutionLock();
        try {
            if ($this->completionLockPresent()) {
                throw new InstallationExecutionException(
                    'INSTALL_ALREADY_COMPLETED',
                    '安装完成锁已经存在，重复执行已被拒绝。',
                    409,
                );
            }
            $status = $this->status();
            if ($status['state'] === 'installed') {
                throw new InstallationExecutionException(
                    'INSTALL_ALREADY_COMPLETED',
                    '系统已经完成安装，重复执行已被拒绝。',
                    409,
                );
            }
            if ($status['state'] !== 'uninstalled') {
                throw new InstallationExecutionException(
                    (string) $status['code'],
                    $status['retryable']
                        ? '安装前置条件暂未满足。'
                        : '目标包含安装身份或未完成残留，保留原数据并由资源 owner 核对，不能自动重建。',
                    409,
                    null,
                    array_intersect_key($status, array_flip(['category', 'stage', 'reason', 'remedy', 'writes', 'safe_retry'])),
                );
            }

            $databaseCheck = $this->databaseCheckForStatus($status);
            if (($databaseCheck['database_check'] ?? null) !== 'ready') {
                throw new InstallationExecutionException((string) $databaseCheck['reason'],
                    '数据库连接或库身份未确认，尚未执行安装。', 409, null,
                    array_intersect_key($databaseCheck, array_flip(['category', 'stage', 'reason', 'remedy', 'writes', 'safe_retry'])));
            }
            $resuming = $status['code'] === 'INSTALL_RESUME_READY';
            if ($resuming && !array_key_exists('official_modules', $input)) {
                $input['official_modules'] = $status['official_module_selection'];
            }
            [$credentials, $moduleKeys] = $this->normalizeInput($input);
            if ($resuming && $moduleKeys !== $status['official_module_selection']) {
                throw new InstallationExecutionException('INSTALL_RESUME_SELECTION_MISMATCH', '继续安装须保留原模块选择。', 409);
            }
            $this->installationSource();
            $stage = $resuming ? 'install_modules' : 'fresh_database';
            $this->writeProgressMarker($moduleKeys, $stage);
            try {
                $migration = $resuming ? ['migration' => 'native', 'status' => 'already-initialized']
                    : \installFreshDatabase($this->serverRoot, $credentials, $moduleKeys);
                $this->writeProgressMarker($moduleKeys, 'install_modules');
                $completion = $this->completeInitializedInstallation($moduleKeys, $stage);

                return [
                    'state' => 'installed',
                    'code' => 'INSTALL_COMPLETED',
                    'deployment_mode' => $this->deploymentMode(),
                    'migration' => $migration,
                    ...$completion,
                ];
            } catch (Throwable $exception) {
                $failure = new InstallationExecutionException(
                    'INSTALL_EXECUTION_FAILED',
                    '安装执行失败；保留已写入的数据和安装记录，核对失败阶段后处理。',
                    409,
                    $exception,
                    ['category' => 'partial', 'stage' => $stage, 'reason' => 'INSTALL_EXECUTION_FAILED',
                        'remedy' => '修复具体失败后读取安装状态；仅 INSTALL_RESUME_READY 可通过原入口继续，保留数据库和身份。',
                        'writes' => ['progress_marker' => true, 'database' => 'may_have_partial_writes',
                            'completion_lock_present' => $this->completionLockPresent()],
                        'safe_retry' => false,
                        ...\installationFailureDiagnostic($this->serverRoot, $stage, $exception, $this->currentInstallationModule)],
                );
                \logInstallationFailure($this->serverRoot, $stage, $failure->errorCode, $failure, $this->currentInstallationModule);
                try {
                    $database = \installationDatabaseState($this->serverRoot);
                    if ($database['state'] === 'uninstalled') {
                        @unlink($this->progressMarker());
                    }
                } catch (Throwable) {
                }
                throw $failure;
            }
        } finally {
            $this->currentInstallationModule = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function completeInitializedInstallation(array $moduleKeys, string &$stage): array
    {
        $this->currentInstallationModule = null;
        $stage = 'install_modules';
        $modules = $this->installModules($moduleKeys, \installationTenantBootstrapContract($this->serverRoot));
        $stage = 'health'; $health = $this->health($moduleKeys);
        $stage = 'completion_marker'; $receipt = $this->writeCompletionMarker($moduleKeys);
        @unlink($this->progressMarker());
        return ['modules' => $modules, 'health' => $health, 'installation_receipt' => $receipt];
    }

    /** Identity comes from the actual native master PDO, never from a supplied window/endpoint declaration. */
    public static function migrationDatabaseIdentity(PDO $pdo): array
    {
        if (Db::connect()->getPdo() !== $pdo) { throw new RuntimeException('INSTALL_DATABASE_CONNECTION_MISMATCH'); }
        $statement = $pdo->query('SELECT @@server_uuid AS server_uuid,@@hostname AS server_hostname,@@port AS server_port,DATABASE() AS database_name');
        $row = $statement->fetch(PDO::FETCH_ASSOC); $statement->closeCursor();
        $transport = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        if (!is_array($row) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', (string) ($row['server_uuid'] ?? '')) !== 1
            || !is_string($row['server_hostname'] ?? null) || $row['server_hostname'] === ''
            || (int) ($row['server_port'] ?? 0) < 1 || (int) $row['server_port'] > 65535
            || !is_string($row['database_name'] ?? null) || $row['database_name'] === ''
            || !is_string($transport) || $transport === '') {
            throw new RuntimeException('INSTALL_DATABASE_IDENTITY_UNAVAILABLE');
        }
        return ['server_uuid' => strtolower($row['server_uuid']), 'server_hostname' => $row['server_hostname'],
            'server_port' => (int) $row['server_port'], 'database' => $row['database_name'], 'transport' => $transport];
    }

    /** @return resource */
    private function acquireExecutionLock()
    {
        $directory = dirname($this->progressMarker());
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new InstallationExecutionException(
                'INSTALL_STATE_UNAVAILABLE',
                '安装状态目录不可用。',
                503,
            );
        }
        $handle = fopen($directory . '/execution.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new InstallationExecutionException(
                'INSTALL_EXECUTION_IN_PROGRESS',
                '已有安装执行正在进行。',
                409,
            );
        }
        return $handle;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function normalizeInput(array $input): array
    {
        $allowed = [
            'admin_email',
            'admin_password',
            'platform_email',
            'platform_password',
            'official_modules',
        ];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InstallationExecutionException(
                'INSTALL_INPUT_INVALID',
                '安装请求包含不支持的字段。',
                422,
            );
        }
        $modules = $input['official_modules'] ?? array_column(array_filter($this->officialModules(), static fn(array $module): bool => $module['default']), 'key');
        if (!is_array($modules)
            || !array_is_list($modules)
            || array_filter($modules, static fn(mixed $key): bool => !is_string($key)) !== []) {
            throw new InstallationExecutionException('INSTALL_MODULE_SELECTION_INVALID', 'Module 选择无效。', 422);
        }
        $modules = array_values(array_unique($modules));
        $options = $this->officialModules();
        $available = array_column($options, 'key');
        if (array_diff($modules, $available) !== []) {
            throw new InstallationExecutionException('INSTALL_MODULE_SELECTION_INVALID', 'Module 选择无效。', 422);
        }
        foreach ($options as $option) {
            if ($option['required']) {
                $modules[] = $option['key'];
            }
        }
        $modules = array_values(array_unique($modules));
        $selected = array_fill_keys($modules, true);
        $definitions = [];
        foreach ($this->definitionRegistry()->modules as $manifest) {
            $key = (string) $manifest->data['key'];
            if (isset($selected[$key])) {
                $definitions[$key] = [
                    'version' => (string) $manifest->data['version'],
                    'dependencies' => $manifest->data['dependencies'] ?? [],
                ];
            }
        }
        // 与独立模块包使用同一依赖/版本规则，且在创建数据库之前完成校验。
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $projectRoot = dirname($this->serverRoot);
        $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
        if (ApplicationSourceIdentity::hasRuntime($this->serverRoot)) {
            ApplicationSourceIdentity::load($this->serverRoot);
            $packageRoot = $this->serverRoot;
        } elseif (file_exists($serverIdentity) || is_link($serverIdentity)) {
            $packageRoot = $this->serverRoot;
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)) {
            $packageRoot = $projectRoot;
        } elseif (file_exists($applicationManifest) || is_link($applicationManifest)) {
            if (!is_file($applicationManifest) || is_link($applicationManifest)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $packageRoot = $projectRoot;
        } else {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $modules = (new \app\common\module\PackageCheck($packageRoot))
            ->dependencyOrder($definitions, []);
        $credentials = array_intersect_key($input, array_flip([
            'admin_email', 'admin_password', 'platform_email', 'platform_password',
        ]));
        try {
            \normalizeInstallationCredentials($credentials);
        } catch (Throwable) {
            throw new InstallationExecutionException('INSTALL_IDENTITY_INVALID', '初始身份不符合安装要求。', 422);
        }
        return [$credentials, $modules];
    }

    /** @return list<array{key:string,label:string,description:string,required:bool,default:bool}> */
    private function officialModules(): array
    {
        $modules = [];
        $registry = $this->definitionRegistry();
        foreach ($registry->modules as $manifest) {
            $key = $manifest->data['key'] ?? null;
            if (is_string($key) && str_starts_with($key, 'peanut.')) {
                $required = $registry->isRequiredTenantFoundation($key);
                $modules[] = [
                    'key' => $key,
                    'label' => (string) ($manifest->data['name'] ?? substr($key, strlen('official.'))),
                    'description' => (string) ($manifest->data['description'] ?? ''),
                    'required' => $required,
                    'default' => in_array($key, \InitialInstallationSource::MODULES, true),
                ];
            }
        }
        usort($modules, static fn(array $left, array $right): int => $left['key'] <=> $right['key']);
        return $modules;
    }

    /**
     * @param list<string> $moduleKeys
     * @param array{kind:string,code:string,tenant_identity:string,rbac:string,execution_context:string,module_lifecycle:string} $tenantBootstrap
     * @return array<string,mixed>
     */
    private function installModules(array $moduleKeys, array $tenantBootstrap): array
    {
        $this->currentInstallationModule = null;
        $config = $this->moduleConfig();
        $registry = $this->definitionRegistry();
        $governance = new ThinkPhpModuleGovernanceProvider(
            $this->serverRoot,
            $config,
            $this->catalogs,
            $registry,
            \app\platform\infrastructure\plugin\PluginLockResolver::forRuntime($this->serverRoot, (string) $config['plugin_lock']),
        );
        $lifecycle = $governance->pluginLifecycle();
        $operations = [];
        foreach ($moduleKeys as $moduleKey) {
            $this->currentInstallationModule = $moduleKey;
            $result = $lifecycle->install($moduleKey);
            $this->currentInstallationModule = null;
            $operations[] = [
                'key' => $moduleKey,
                'operation' => (string) ($result['operation'] ?? ''),
            ];
        }
        $license = $this->licenses->initializeInstallation($moduleKeys, $tenantBootstrap['code']);
        return ['operations' => $operations, 'license' => $license];
    }

    /** @param list<string> $moduleKeys @return array<string,int> */
    private function health(array $moduleKeys): array
    {
        $pdo = $this->pdo();
        $tenantBootstrap = \installationTenantBootstrapContract($this->serverRoot);
        $health = \assertCurrentDatabase($pdo);
        if ($moduleKeys !== []) {
            if ($this->moduleStates->activeInstallationCount($moduleKeys) !== count($moduleKeys)) {
                throw new RuntimeException('Official Module installation is incomplete.');
            }
            $definitions = $this->definitionRegistry();
            $tenantManaged = array_values(array_filter(
                $moduleKeys,
                fn(string $moduleKey): bool => !$definitions->isRequiredTenantFoundation($moduleKey),
            ));
            if ($tenantManaged !== []) {
                if ($this->moduleStates->enabledTenantSelectionCount($tenantBootstrap['code'], $tenantManaged) !== count($tenantManaged)) {
                    throw new RuntimeException('Default Tenant Module selection is incomplete.');
                }
            }
            $ownedTables = [];
            foreach ($moduleKeys as $moduleKey) {
                foreach ($definitions->requireManifest($moduleKey)->data['database']['owned_tables'] ?? [] as $table) {
                    $ownedTables[$table] = true;
                }
            }
            if ($ownedTables !== []) {
                $names = array_keys($ownedTables);
                $placeholders = implode(',', array_fill(0, count($names), '?'));
                $query = $pdo->prepare(
                    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                );
                $query->execute($names);
                $present = $query->fetchAll(PDO::FETCH_COLUMN);
                $missing = array_diff($names, $present);
                if ($missing !== []) {
                    throw new RuntimeException('Installed Module tables are missing: ' . implode(', ', $missing));
                }
            }
        }
        $health['selected_module_count'] = count($moduleKeys);
        return $health;
    }

    private function pdo(): PDO
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('INSTALL_DATABASE_DRIVER_UNAVAILABLE');
        }
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('INSTALL_DATABASE_CONNECTION_UNAVAILABLE');
        }

        return $pdo;
    }

    /** @return array<string,mixed> */
    private function moduleConfig(): array
    {
        $config = Config::get('modules', []);
        if (!is_array($config)) {
            throw new RuntimeException('MODULE_REGISTRY_UNAVAILABLE');
        }

        return $config;
    }

    private function definitionRegistry(): CompiledModuleRegistry
    {
        return $this->definitions;
    }

    private function mode(): string
    {
        $mode = trim((string) Config::get('peanut.installation.mode', 'automatic'));
        if (!in_array($mode, self::MODES, true)) {
            throw new RuntimeException('PEANUT_INSTALLATION_MODE must be guided or automatic.');
        }
        return $mode;
    }

    private function deploymentMode(): string
    {
        $mode = trim((string) Config::get('deployment.mode', ''));
        if ($mode !== 'standalone' && $mode !== 'multi-tenant') {
            throw new RuntimeException('DEPLOYMENT_MODE must be standalone or multi-tenant.');
        }
        return $mode;
    }

    /** @param list<string> $moduleKeys */
    private function writeProgressMarker(array $moduleKeys, string $stage): void
    {
        $previous = is_file($this->progressMarker())
            ? json_decode((string) file_get_contents($this->progressMarker()), true) : null;
        $this->writeMarker($this->progressMarker(), [
            'schema_version' => 1,
            'state' => 'executing',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'stage' => $stage,
            'started_at' => is_string($previous['started_at'] ?? null) ? $previous['started_at'] : gmdate(DATE_ATOM),
        ]);
    }

    /** Reconcile the native initialization result; this path never initializes or seeds. */
    private function initializedCheckpoint(): array
    {
        $path = $this->progressMarker();
        $progress = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($progress['schema_version'] ?? null) !== 1 || ($progress['state'] ?? null) !== 'executing'
            || ($progress['deployment_mode'] ?? null) !== $this->deploymentMode()
            || !is_array($progress['official_modules'] ?? null) || !array_is_list($progress['official_modules'])
            || $progress['official_modules'] === []
            || array_filter($progress['official_modules'], static fn(mixed $key): bool => !is_string($key)) !== []
            || count(array_unique($progress['official_modules'])) !== count($progress['official_modules'])
            || array_diff($progress['official_modules'], array_column($this->officialModules(), 'key')) !== []) {
            throw new RuntimeException('INSTALL_PROGRESS_INVALID');
        }
        $modules = $progress['official_modules'];
        $this->installationSource();
        $pdo = $this->pdo();
        // This is the existing native post-initialization health check, including the
        // migration ledger, selected tables, seeded catalogs and active owner identity.
        $health = \assertCurrentDatabase($pdo);
        if (\coreIdentityCounts($pdo, \installationTenantBootstrapContract($this->serverRoot)['code'])
            !== ['tenant_count' => 1, 'owner_count' => 1, 'operator_count' => 1]) {
            throw new RuntimeException('INSTALL_RESUME_IDENTITY_INCOMPLETE');
        }
        return ['official_modules' => $modules, 'health' => $health];
    }

    /** @param list<string> $moduleKeys @return array<string,mixed> */
    private function writeCompletionMarker(array $moduleKeys): array
    {
        $versions = \applicationReleaseVersions($this->serverRoot);
        $source = $this->installationSource();
        $table = (string) Db::connect()->getConfig('prefix') . (string) Config::get('database.migration_table', 'migrations');
        $applied = array_map('strval', Db::table($table)->column('version'));
        $migrations = [];
        $selected = [];
        foreach ($this->definitions->modules as $manifest) {
            if (in_array($manifest->data['key'], $moduleKeys, true)) {
                $selected[$manifest->data['key']] = $manifest;
            }
        }
        foreach (ModuleMigrationRun::sources($selected, $this->serverRoot, true) as $migration) {
            $version = $migration['migration_key'];
            if (!in_array($version, $applied, true)) {
                throw new RuntimeException('INSTALL_MIGRATIONS_INCOMPLETE');
            }
            $migrations[] = ['version' => $version, 'sha256' => $migration['sha256']];
        }
        if ($migrations === [] || count($migrations) !== count($applied)) {
            throw new RuntimeException('INSTALL_MIGRATIONS_MISMATCH');
        }
        $payload = [
            'schema_version' => 1,
            'protocol' => 'peanut.installation-receipt.v2',
            'state' => 'installed',
            'deployment_mode' => $this->deploymentMode(),
            'official_modules' => $moduleKeys,
            'release' => $versions,
            'source' => $source,
            'database' => [
                'migration_table' => $table,
                'schema_version' => $migrations[array_key_last($migrations)]['version'],
                'migrations' => $migrations,
            ],
            'completed_at' => gmdate(DATE_ATOM),
        ];
        if (isset($source['application_manifest_sha256'])) {
            $payload['application_manifest_sha256'] = $source['application_manifest_sha256'];
        }
        $this->writeMarker($this->completionMarker(), $payload);
        $receiptSha256 = hash_file('sha256', $this->completionMarker());
        if (!is_string($receiptSha256)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return [
            'path' => 'server/private/installation/installed.json',
            'sha256' => $receiptSha256,
            'database' => $payload['database'],
        ];
    }

    /** @return array<string,string> */
    private function installationSource(): array
    {
        $projectRoot = dirname($this->serverRoot);
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $applicationManifest = $projectRoot . '/.peanut/application-manifest.json';
        if (ApplicationSourceIdentity::hasRuntime($this->serverRoot)) {
            $identity = ApplicationSourceIdentity::load($this->serverRoot);
            $source = [
                'kind' => 'development-runtime',
                'development_runtime_identity_sha256' => $identity->identitySha256(),
                'application_manifest_sha256' => $identity->manifestSha256(),
            ];
        } elseif (file_exists($serverIdentity) || is_link($serverIdentity)) {
            $identity = ServerReleaseIdentity::resolve($this->serverRoot);
            $source = [
                'kind' => 'server-release',
                'server_release_identity_sha256' => $identity->identitySha256(),
                'application_manifest_sha256' => $identity->manifestSha256(),
            ];
        } elseif (file_exists($applicationManifest) || is_link($applicationManifest)) {
            if (!is_file($applicationManifest) || is_link($applicationManifest)) {
                throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
            }
            $source = [
                'kind' => 'generated-application',
                'application_manifest_sha256' => $this->fileDigest($applicationManifest),
            ];
        } elseif (\installationSourceDevelopmentMode($this->serverRoot)) {
            $source = [
                'kind' => 'product-source',
                'inventory_sha256' => $this->fileDigest($projectRoot . '/scaffold/application-template-inventory.json'),
                'edition_profiles_sha256' => $this->fileDigest($projectRoot . '/scaffold/edition-profiles.json'),
                'release_versions_sha256' => $this->fileDigest($projectRoot . '/release-versions.json'),
            ];
        } else {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }

        return $source;
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException('INSTALL_RELEASE_IDENTITY_UNAVAILABLE');
        }
        return $digest;
    }

    /** @param array<string,mixed> $payload */
    private function writeMarker(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Installation state directory is unavailable.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $contents = $json . "\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Installation state marker cannot be written.');
        }
    }

    private function progressMarker(): string
    {
        return $this->serverRoot . '/private/installation/executing.json';
    }

    private function completionMarker(): string
    {
        return $this->serverRoot . '/private/installation/installed.json';
    }

    private function completionLockPresent(): bool
    {
        $path = $this->completionMarker();
        return file_exists($path) || is_link($path);
    }

    /** Historical installation anchors only; no database, catalog or target-source comparison. */
    public static function verifiedInstallationFacts(string $serverRoot): array
    {
        if (realpath($serverRoot) !== $serverRoot) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_ROOT_INVALID');
        }
        $read = static function (string $path): array {
            $stat = is_file($path) && !is_link($path) ? lstat($path) : false;
            if (!is_array($stat) || $stat['nlink'] !== 1 || ($stat['mode'] & 0777) !== 0600
                || $stat['size'] > 4194304) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_FILE_UNSAFE');
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_FILE_UNAVAILABLE');
            }
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $path))) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_FILE_CHANGED');
            }
            return ['value' => $value, 'sha256' => hash('sha256', $bytes)];
        };
        $receiptFile = $read($serverRoot . '/private/installation/installed.json');
        $receipt = $receiptFile['value'];
        if (($receipt['schema_version'] ?? null) !== 1
            || ($receipt['protocol'] ?? null) !== 'peanut.installation-receipt.v2'
            || ($receipt['state'] ?? null) !== 'installed'
            || !in_array($receipt['deployment_mode'] ?? null, ['standalone', 'multi-tenant'], true)
            || !is_array($receipt['official_modules'] ?? null) || !array_is_list($receipt['official_modules'])
            || !is_array($receipt['release'] ?? null)
            || !is_array($receipt['source'] ?? null)
            || !is_array($receipt['database'] ?? null)
            || !is_string($receipt['completed_at'] ?? null)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $receipt['completed_at']) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_RECEIPT_INVALID');
        }
        foreach ($receipt['official_modules'] as $module) {
            if (!is_string($module) || preg_match('/\A[a-z][a-z0-9_-]*\.[a-z][a-z0-9_.-]*\z/D', $module) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_MODULES_INVALID');
            }
        }
        if (count(array_unique($receipt['official_modules'])) !== count($receipt['official_modules'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_MODULES_INVALID');
        }
        foreach (['source_product_version', 'release_sequence_version', 'scaffold_template'] as $name) {
            if (!is_string($receipt['release'][$name] ?? null)
                || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D', $receipt['release'][$name]) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_RELEASE_INVALID');
            }
        }
        $source = $receipt['source'];
        $required = match (is_array($source) ? ($source['kind'] ?? null) : null) {
            'development-runtime' => ['development_runtime_identity_sha256', 'application_manifest_sha256'],
            'server-release' => ['server_release_identity_sha256', 'application_manifest_sha256'],
            'generated-application' => ['application_manifest_sha256'],
            'product-source' => ['inventory_sha256', 'edition_profiles_sha256', 'release_versions_sha256'],
            default => throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_SOURCE_INVALID'),
        };
        foreach ($required as $field) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', (string) ($source[$field] ?? '')) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_SOURCE_INVALID');
            }
        }
        $database = $receipt['database'];
        $chain = $database['migrations'] ?? null;
        if (!is_string($database['migration_table'] ?? null) || $database['migration_table'] === ''
            || !is_array($chain) || !array_is_list($chain) || $chain === []) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_CHAIN_INVALID');
        }
        $previous = '';
        foreach ($chain as $migration) {
            $version = is_array($migration) ? ($migration['version'] ?? null) : null;
            if (!is_string($version) || preg_match('/\A[0-9]{14}\z/D', $version) !== 1
                || strcmp($previous, $version) >= 0
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) ($migration['sha256'] ?? '')) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_CHAIN_INVALID');
            }
            $previous = $version;
        }
        if (($database['schema_version'] ?? null) !== $previous
            || (isset($source['application_manifest_sha256'])
                && ($receipt['application_manifest_sha256'] ?? null) !== $source['application_manifest_sha256'])) {
            throw new RuntimeException('APPLICATION_SCHEMA_INSTALLATION_IDENTITY_INVALID');
        }
        return ['receipt_sha256' => $receiptFile['sha256'], 'receipt' => $receipt];
    }

    private function completionReceiptValid(): bool
    {
        try {
            self::verifiedInstallationFacts($this->serverRoot);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
