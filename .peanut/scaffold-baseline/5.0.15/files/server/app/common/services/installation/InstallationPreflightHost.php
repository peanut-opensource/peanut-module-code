<?php

declare(strict_types=1);

namespace app\common\services\installation;

use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;
use app\common\infrastructure\scaffold\ReleaseDependencyIdentity;
use app\common\value\installation\ApplicationReleaseVersions;
use Closure;
use RuntimeException;
use Throwable;

final class InstallationPreflightHost
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = ['curl', 'mbstring', 'pdo_mysql', 'zip'];

    /** @var array<string,string> */
    private const WRITABLE_DIRECTORIES = [
        'runtime-cache' => 'runtime/cache',
        'runtime-log' => 'runtime/log',
        'runtime-session' => 'runtime/session',
        'runtime-temp' => 'runtime/temp',
        'runtime-storage' => 'runtime/storage',
        'runtime-generator' => 'runtime/generator',
        'runtime-file' => 'runtime/file',
        'installation-state' => 'private/installation',
        'pending-configuration' => 'private/resources/pending',
        'public-storage' => 'public/storage',
        'private-storage' => 'private/storage',
    ];

    private string $serverRoot;
    private Closure $extensionProbe;
    private Closure $databaseConfigLoader;
    private string $phpVersion;

    public function __construct(
        ?string $serverRoot = null,
        ?Closure $extensionProbe = null,
        ?Closure $databaseConfigLoader = null,
        ?string $phpVersion = null,
    ) {
        $this->serverRoot = rtrim($serverRoot ?? dirname(__DIR__, 4), '/');
        $this->extensionProbe = $extensionProbe
            ?? static fn(string $extension): bool => extension_loaded($extension);
        $this->databaseConfigLoader = $databaseConfigLoader
            ?? fn(): array => $this->loadDatabaseConfig();
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
    }

    /**
     * @return array{
     *   status:string,
     *   code:string,
     *   reason:string,
     *   remediation:string,
     *   checks:list<array{id:string,status:string,code:string,reason:string,remediation:string}>,
     *   database:array{host:string,port:string,database:string}|null
     * }
     */
    public function inspect(bool $includeDatabase = true): array
    {
        $checks = [
            $this->phpVersionCheck(),
            $this->extensionCheck(),
            $this->installationFilesCheck(),
            $this->composerDependenciesCheck(),
        ];
        foreach (self::WRITABLE_DIRECTORIES as $id => $relativePath) {
            $checks[] = $this->directoryCheck($id, $relativePath);
        }
        $resource = null;
        if ($includeDatabase) {
            [$databaseConfigurationCheck, $resource] = $this->databaseConfigurationCheck();
            $checks[] = $databaseConfigurationCheck;
        }

        $failed = array_values(array_filter(
            $checks,
            static fn(array $check): bool => $check['status'] === 'failed',
        ));
        if ($failed !== []) {
            return [
                'status' => 'blocked',
                'code' => 'INSTALL_PREFLIGHT_BLOCKED',
                'reason' => sprintf('安装预检有 %d 项未通过', count($failed)),
                'remediation' => '按 checks 中的修复建议处理后重新运行安装预检',
                'checks' => $checks,
                'database' => $resource,
            ];
        }

        return [
            'status' => 'ready',
            'code' => 'INSTALL_PREFLIGHT_READY',
            'reason' => $includeDatabase ? '安装运行环境和数据库配置已通过只读预检' : '安装运行环境已通过只读预检，尚未检查数据库配置',
            'remediation' => $includeDatabase ? '可以继续数据库连通、空库和安装锁检查' : '可以继续实例配置，随后检查数据库连接与权限',
            'checks' => $checks,
            'database' => $resource,
        ];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function phpVersionCheck(): array
    {
        if (version_compare($this->phpVersion, '8.3.0', '>=')) {
            return $this->passed(
                'php-version',
                'INSTALL_PHP_VERSION_READY',
                'PHP 版本满足 8.3 或更高版本要求',
            );
        }

        return $this->failed(
            'php-version',
            'INSTALL_PHP_VERSION_UNSUPPORTED',
            'PHP 版本低于 8.3',
            '安装 PHP 8.3 或更高版本后重新运行预检',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function extensionCheck(): array
    {
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!(bool) ($this->extensionProbe)($extension)) {
                $missing[] = $extension;
            }
        }
        if ($missing === []) {
            return $this->passed(
                'php-extensions',
                'INSTALL_PHP_EXTENSIONS_READY',
                '安装所需 PHP 扩展均已加载',
            );
        }

        return $this->failed(
            'php-extensions',
            'INSTALL_PHP_EXTENSIONS_MISSING',
            '缺少 PHP 扩展：' . implode(', ', $missing),
            '安装并启用缺失扩展后重新运行预检',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function installationFilesCheck(): array
    {
        $serverIdentity = $this->serverRoot . '/.peanut/release-identity.json';
        $sourceRoot = dirname($this->serverRoot);
        $applicationManifest = $sourceRoot . '/.peanut/application-manifest.json';
        $serverRelease = file_exists($serverIdentity) || is_link($serverIdentity);
        $runtimeIdentity = $this->serverRoot . '/.peanut/development-runtime.json';
        $developmentRuntime = file_exists($runtimeIdentity) || is_link($runtimeIdentity);
        $serverLayout = $serverRelease || $developmentRuntime;
        $sourceDevelopment = !$serverLayout
            && function_exists('env')
            && env('PEANUT_INSTALLATION_SOURCE_MODE', false) === 'development'
            && file_exists($sourceRoot . '/.git')
            && !is_link($sourceRoot . '/.git')
            && is_file($sourceRoot . '/release-versions.json')
            && (is_file($applicationManifest)
                || (is_file($sourceRoot . '/scaffold/application-template-inventory.json')
                    && is_file($sourceRoot . '/scaffold/edition-profiles.json')));
        $generatedApplication = !$serverLayout
            && !$sourceDevelopment
            && (file_exists($applicationManifest) || is_link($applicationManifest));
        $required = [
            'Composer autoload' => $this->serverRoot . '/vendor/autoload.php',
            '原生初始迁移' => $this->serverRoot . '/database/migrations/20261101000000_initial_baseline.php',
            '初始表结构' => $this->serverRoot . '/database/schema/baseline-schema.sql',
            '原生初始 Seeder' => $this->serverRoot . '/database/seeds/InitialSeeder.php',
            '初始数据' => $this->serverRoot . '/database/schema/baseline-data.sql',
            '品牌配置' => $this->serverRoot . '/config/brand.json',
            '发布身份' => $serverLayout
                ? ($developmentRuntime ? $runtimeIdentity : $serverIdentity)
                : ($generatedApplication ? $applicationManifest : $sourceRoot . '/RELEASE_METADATA.json'),
            '发布元数据' => $serverLayout
                ? $this->serverRoot . '/.peanut/RELEASE_METADATA.json'
                : $sourceRoot . '/RELEASE_METADATA.json',
            'Plugin lock' => $serverLayout
                ? $this->serverRoot . '/plugins.lock'
                : $sourceRoot . '/plugins.lock',
            'Plugin schema' => $this->serverRoot . '/resources/schemas/plugin.schema.json',
        ];
        if (!$serverLayout && !$generatedApplication && !$sourceDevelopment) {
            $required['源码开发身份'] = $sourceRoot . '/.git';
        }
        $missing = [];
        foreach ($required as $label => $path) {
            if (!is_file($path) || !is_readable($path)) {
                $missing[] = $label;
            }
        }
        if ($missing === []) {
            if ($serverLayout) {
                try {
                    require_once $this->serverRoot . '/vendor/autoload.php';
                    $identity = $developmentRuntime
                        ? ApplicationSourceIdentity::load($this->serverRoot)
                        : ServerReleaseIdentity::resolve($this->serverRoot);
                    $expected = $identity->projectedPluginLockSha256();
                    if (!hash_equals($expected, (string) hash_file('sha256', $this->serverRoot . '/plugins.lock'))) {
                        throw new RuntimeException('Server Plugin lock differs from release identity.');
                    }
                } catch (Throwable) {
                    return $this->failed(
                        'installation-files',
                        'INSTALL_FILES_INVALID',
                        'Server 运行身份或 Plugin lock 校验失败',
                        '恢复完整且同一身份的 Server 制品或开发运行投影后重新运行预检',
                    );
                }
            }
            return $this->passed(
                'installation-files',
                'INSTALL_FILES_READY',
                '安装器所需文件均存在且可读',
            );
        }

        return $this->failed(
            'installation-files',
            'INSTALL_FILES_MISSING',
            '缺少或无法读取：' . implode(', ', $missing),
            '恢复完整发布制品并安装锁定的 Composer 依赖后重新运行预检',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function composerDependenciesCheck(): array
    {
        $blocked = fn(): array => $this->failed(
            'composer-dependencies',
            'INSTALL_COMPOSER_DEPENDENCIES_INVALID',
            'Composer 已装运行依赖与本应用锁文件不一致或缺少迁移服务',
            '按 server/composer.lock 安装依赖，禁用 Composer scripts 和 plugins 后重新运行预检',
        );
        try {
            $read = static function (string $path): array {
                if (!is_file($path) || is_link($path) || !is_readable($path)) {
                    throw new RuntimeException('Composer dependency metadata unavailable');
                }
                $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($value)) {
                    throw new RuntimeException('Composer dependency metadata invalid');
                }
                return $value;
            };
            $manifest = $read($this->serverRoot . '/composer.json');
            $lock = $read($this->serverRoot . '/composer.lock');
            $installed = $read($this->serverRoot . '/vendor/composer/installed.json');
            if (!is_array($manifest['require'] ?? null)
                || !is_array($lock['packages'] ?? null) || !array_is_list($lock['packages'])
                || !is_array($lock['packages-dev'] ?? null) || !array_is_list($lock['packages-dev'])
                || !is_array($installed['packages'] ?? null) || !array_is_list($installed['packages'])) {
                return $blocked();
            }
            $localCore = null;
            foreach ($lock['packages'] as $package) {
                if (($package['name'] ?? null) !== 'peanut-admin/core' || isset($package['source'])) { continue; }
                require_once dirname(__DIR__, 2) . '/value/installation/ApplicationReleaseVersions.php';
                $root = dirname($this->serverRoot);
                $localCore = ApplicationReleaseVersions::load($root . '/release-versions.json')->toArray()['core_php'];
                if ($localCore['source_type'] !== 'local') { return $blocked(); }
                ReleaseDependencyIdentity::phpComposerReference($package, $localCore, 'Composer Core identity invalid');
            }
            $modulePackages = [];
            $moduleConfig = require $this->serverRoot . '/config/modules.php';
            $resolver = \app\platform\infrastructure\plugin\PluginLockResolver::forRuntime($this->serverRoot, $moduleConfig['plugin_lock']);
            foreach ($resolver->all() as $plugin) {
                foreach ($plugin->moduleRoots as $key => $root) {
                    $package = $read($root . '/composer.json');
                    $modulePackages[$package['name']] = ['version' => $package['version'],
                        'url' => substr($root, strlen($this->serverRoot) + 1)];
                    $modulePackages['peanut-module-dev/' . $key] = ['version' => $package['version'],
                        'require' => $package['require-dev'] ?? []];
                }
            }
            $identity = static function (array $package) use ($localCore, $modulePackages): array {
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (isset($modulePackages[$name])) {
                    $module = $modulePackages[$name];
                    if ($version !== $module['version'] || isset($package['source'])) {
                        throw new RuntimeException('Composer Module identity invalid');
                    }
                    if (isset($module['url'])) {
                        $dist = $package['dist'] ?? [];
                        if (($dist['type'] ?? null) !== 'path' || ($dist['url'] ?? null) !== $module['url']) {
                            throw new RuntimeException('Composer Module path identity invalid');
                        }
                        return [$name, $version, $dist];
                    }
                    if (($package['type'] ?? null) !== 'metapackage' || isset($package['dist'])
                        || ($package['require'] ?? []) != $module['require']) {
                        throw new RuntimeException('Composer Module development identity invalid');
                    }
                    return [$name, $version, $module['require']];
                }
                if ($name === 'peanut-admin/core' && $localCore !== null) {
                    $reference = ReleaseDependencyIdentity::phpComposerReference($package, $localCore, 'Composer Core identity invalid');
                    // Compare the installed distribution with the lock; its ZIP was verified when prepared.
                    return [$name, $version, [$reference, $package['dist']['type'], $package['dist']['url'], $package['dist']['shasum']]];
                }
                $source = $package['source']['reference'] ?? null;
                $dist = $package['dist']['reference'] ?? null;
                if (!is_string($name) || $name === '' || !is_string($version) || $version === ''
                    || !is_string($source) || preg_match('/^[0-9a-f]{40}$/D', $source) !== 1
                    || !is_string($dist) || $dist !== $source) {
                    throw new RuntimeException('Composer package identity invalid');
                }
                return [$name, $version, $source];
            };
            $map = static function (array $packages) use ($identity): array {
                $result = [];
                foreach ($packages as $package) {
                    if (!is_array($package)) {
                        throw new RuntimeException('Composer package entry invalid');
                    }
                    [$name, $version, $reference] = $identity($package);
                    if (isset($result[$name])) {
                        throw new RuntimeException('Duplicate Composer package');
                    }
                    $result[$name] = [$version, $reference];
                }
                return $result;
            };
            $runtime = $map($lock['packages']);
            $development = $map($lock['packages-dev']);
            $actual = $map($installed['packages']);
            $pinned = static function (string $name) use ($manifest, $runtime): bool {
                $constraint = $manifest['require'][$name] ?? null;
                return is_string($constraint)
                    && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $constraint) === 1
                    && in_array($runtime[$name][0] ?? null, [$constraint, 'v' . $constraint], true);
            };
            if (!$runtime || array_intersect_key($runtime, $development)
                || !$pinned('peanut-admin/core') || !$pinned('topthink/think-migration')) {
                return $blocked();
            }
            foreach ($runtime as $name => $expected) {
                if (($actual[$name] ?? null) !== $expected) {
                    return $blocked();
                }
            }
            foreach (array_diff_key($actual, $runtime) as $name => $identity) {
                if (($development[$name] ?? null) !== $identity) {
                    return $blocked();
                }
            }
            $vendorRoot = realpath($this->serverRoot . '/vendor');
            $service = $this->serverRoot . '/vendor/topthink/think-migration/src/Service.php';
            $serviceRoot = realpath($this->serverRoot . '/vendor/topthink/think-migration');
            if (!is_string($vendorRoot) || !is_string($serviceRoot)
                || !str_starts_with($serviceRoot, $vendorRoot . '/')
                || !is_file($service) || is_link($service)
                || realpath($service) !== $serviceRoot . '/src/Service.php') {
                return $blocked();
            }
        } catch (Throwable) {
            return $blocked();
        }
        return $this->passed(
            'composer-dependencies',
            'INSTALL_COMPOSER_DEPENDENCIES_READY',
            '已装运行依赖与锁文件版本及来源一致，迁移服务文件存在',
        );
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function directoryCheck(string $id, string $relativePath): array
    {
        $path = $this->serverRoot . '/' . $relativePath;
        if (is_dir($path) && is_readable($path) && is_writable($path)) {
            return $this->passed(
                'directory.' . $id,
                'INSTALL_DIRECTORY_READY',
                $relativePath . ' 目录存在且可读写',
            );
        }

        return $this->failed(
            'directory.' . $id,
            'INSTALL_DIRECTORY_NOT_WRITABLE',
            $relativePath . ' 目录不存在或不可读写',
            '由部署 owner 创建该目录并授予应用进程最小读写权限',
        );
    }

    /**
     * @return array{
     *   0:array{id:string,status:string,code:string,reason:string,remediation:string},
     *   1:array{host:string,port:string,database:string}|null
     * }
     */
    private function databaseConfigurationCheck(): array
    {
        try {
            $config = ($this->databaseConfigLoader)();
            if (!is_array($config)) {
                throw new RuntimeException('database config loader must return an array');
            }
            $identity = [];
            foreach (['host', 'port', 'database'] as $field) {
                $value = $config[$field] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    throw new RuntimeException('database configuration is incomplete');
                }
                $identity[$field] = $value;
            }

            return [
                $this->passed(
                    'database-configuration',
                    'INSTALL_DATABASE_CONFIGURATION_READY',
                    '数据库主机、端口和库名配置完整',
                ),
                $identity,
            ];
        } catch (Throwable) {
            return [
                $this->failed(
                    'database-configuration',
                    'INSTALL_DATABASE_CONFIGURATION_INVALID',
                    '数据库连接配置不完整或无效',
                    '在 server/.env 配置 DB_HOST、DB_PORT、DB_NAME、DB_USER 和 DB_PASS；本预检不连接数据库，下一步检查实际连接和权限',
                ),
                null,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function loadDatabaseConfig(): array
    {
        $configuration = require $this->serverRoot . '/config/database.php';
        $connection = $configuration['connections'][$configuration['default']] ?? null;
        if (!is_array($connection) || ($connection['type'] ?? null) !== 'mysql') {
            throw new RuntimeException('INSTALL_DATABASE_CONNECTION_INVALID');
        }
        return ['host' => (string) $connection['hostname'], 'port' => (string) $connection['hostport'],
            'database' => (string) $connection['database']];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function passed(string $id, string $code, string $reason): array
    {
        return [
            'id' => $id,
            'status' => 'passed',
            'code' => $code,
            'reason' => $reason,
            'remediation' => '',
        ];
    }

    /** @return array{id:string,status:string,code:string,reason:string,remediation:string} */
    private function failed(string $id, string $code, string $reason, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => 'failed',
            'code' => $code,
            'reason' => $reason,
            'remediation' => $remediation,
        ];
    }
}
