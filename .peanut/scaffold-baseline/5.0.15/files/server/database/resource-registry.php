<?php

declare(strict_types=1);

use app\common\value\installation\ApplicationSourceIdentity;
use app\common\value\installation\ServerReleaseIdentity;

// Pure APP registry selection; no environment loading or database connection.
/** @return array<string,mixed> */
function projectResourceRegistry(?string &$registrySha256 = null): array
{
    $root = dirname(__DIR__, 2);
    $serverRoot = dirname(__DIR__);
    $explicitPath = getenv('PEANUT_RESOURCE_REGISTRY');
    $serverIdentityPath = $serverRoot . '/.peanut/release-identity.json';
    $serverRegistryPath = $serverRoot . '/resources/project-resources.json';
    $instanceRegistryPath = $serverRoot . '/private/resources/project-resources.json';
    $serverOnly = file_exists($serverIdentityPath) || is_link($serverIdentityPath);
    $developmentRuntime = ApplicationSourceIdentity::hasRuntime($serverRoot);
    if ($serverOnly || $developmentRuntime) {
        // HTTP consumes the owner's admitted runtime; CLI verifies the full projection.
        // Both native readers retain development/release identity conflict checks.
        $expectedProjectId = $developmentRuntime
            ? ApplicationSourceIdentity::load($serverRoot)->applicationSlug()
            : ServerReleaseIdentity::resolve($serverRoot)->applicationSlug();
        if ($explicitPath !== false && trim($explicitPath) !== '') {
            throw new RuntimeException('APP 运行实例不允许通过环境变量读取外部资源登记');
        }
        if (file_exists($instanceRegistryPath) || is_link($instanceRegistryPath)) {
            if (!is_file($instanceRegistryPath) || is_link($instanceRegistryPath)) {
                throw new RuntimeException('实例资源登记不可用');
            }
            $path = $instanceRegistryPath;
        } else {
            $path = $serverRegistryPath;
        }
    } else {
        if (file_exists($serverRegistryPath) || is_link($serverRegistryPath)) {
            throw new RuntimeException('server 资源登记缺少 release identity');
        }
        $manifestPath = $root . '/.peanut/application-manifest.json';
        $sourceApp = file_exists($manifestPath) || is_link($manifestPath);
        $expectedProjectId = $sourceApp
            ? ApplicationSourceIdentity::load($serverRoot)->applicationSlug()
            : 'peanut-admin';
        if ($explicitPath !== false && trim($explicitPath) !== '') {
            $path = $explicitPath;
        } elseif (file_exists($instanceRegistryPath) || is_link($instanceRegistryPath)) {
            if (!$sourceApp || !is_file($instanceRegistryPath) || is_link($instanceRegistryPath)) {
                throw new RuntimeException('实例资源登记不可用或缺少 APP 身份');
            }
            $path = $instanceRegistryPath;
        } else {
            $path = $root . '/resources/project-resources.json';
        }
    }
    if ($path[0] !== '/' || is_link($path)) {
        throw new RuntimeException('PEANUT_RESOURCE_REGISTRY 必须是绝对路径且不能为符号链接');
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('无法读取项目资源登记');
    }
    try {
        $registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('项目资源登记不是有效 JSON', 0, $exception);
    }
    if (!is_array($registry)
        || ($registry['schema_version'] ?? null) !== 1
        || !is_string($registry['project_id'] ?? null)
        || $registry['project_id'] === ''
        || !in_array($registry['authority']['role'] ?? null, ['application', 'maintainer'], true)
        || !is_array($registry['resources']['databases'] ?? null)) {
        throw new RuntimeException('项目资源登记结构无效');
    }
    if ($registry['authority']['role'] === 'application') {
        if (!hash_equals($expectedProjectId, $registry['project_id'])) {
            throw new RuntimeException('应用资源登记与当前 APP 身份不匹配');
        }
    } elseif ($serverOnly || $developmentRuntime || $expectedProjectId !== 'peanut-admin'
        || $explicitPath === false || trim($explicitPath) === ''
        || $registry['project_id'] !== 'peanut-admin') {
        throw new RuntimeException('维护者登记只允许在 Code 维护入口显式使用');
    }
    $registrySha256 = hash('sha256', $raw);
    return $registry;
}

/** @param array<string,mixed> $registry @return array<string,mixed> */
function registeredDatabase(array $registry, string $resourceId): array
{
    $matches = [];
    foreach ($registry['resources']['databases'] as $database) {
        if (is_array($database) && ($database['stable_resource_id'] ?? null) === $resourceId) {
            $matches[] = $database;
        }
    }
    if (count($matches) !== 1) {
        throw new RuntimeException("数据库资源 {$resourceId} 必须在项目登记中唯一存在");
    }
    return $matches[0];
}

/** @return array{app_environment:string,resource_environment:string,default_consumer:string} */
function deploymentTargetContract(string $deploymentTarget): array
{
    return match ($deploymentTarget) {
        'development-test' => [
            'app_environment' => 'development',
            'resource_environment' => 'development-test',
            'default_consumer' => 'host',
        ],
        'local-development' => [
            'app_environment' => 'development',
            'resource_environment' => 'development',
            'default_consumer' => 'host',
        ],
        'local-production-preview' => [
            'app_environment' => 'production',
            'resource_environment' => 'local-production-preview',
            'default_consumer' => 'container',
        ],
        'local-multi-tenant-demo' => [
            'app_environment' => 'development',
            'resource_environment' => 'local-multi-tenant-demo',
            'default_consumer' => 'host',
        ],
        'production' => [
            'app_environment' => 'production',
            'resource_environment' => 'production',
            'default_consumer' => 'container',
        ],
        'production-candidate' => [
            'app_environment' => 'production',
            'resource_environment' => 'production-candidate',
            'default_consumer' => 'container',
        ],
        default => throw new RuntimeException("不支持的部署目标：{$deploymentTarget}"),
    };
}

/** @param array<string,mixed> $database @return array<string,mixed> */
function registeredDatabaseEndpoint(array $database, string $consumer): array
{
    $key = match ($consumer) {
        'host' => 'upstream_endpoint',
        'container' => 'container_endpoint',
        default => throw new RuntimeException("不支持的数据库 consumer：{$consumer}"),
    };
    $endpoint = $database[$key] ?? null;
    if (!is_array($endpoint) || !in_array($consumer, $endpoint['consumers'] ?? [], true)) {
        throw new RuntimeException("数据库资源缺少已登记的 {$consumer} endpoint");
    }
    foreach (['endpoint_id', 'host', 'port'] as $field) {
        if (!is_string($endpoint[$field] ?? null) && !is_int($endpoint[$field] ?? null)) {
            throw new RuntimeException("数据库 endpoint 缺少 {$field}");
        }
    }
    return $endpoint;
}

/** @param array{host:string|int,port:string|int,database:string|null} $expected */
function registeredMysqlConnectionMatches(array $expected): bool
{
    $connection = \think\facade\Config::get('database.connections.mysql', []);
    return \think\facade\Config::get('database.default') === 'mysql'
        && is_array($connection)
        && ($connection['type'] ?? null) === 'mysql'
        && (string) ($connection['hostname'] ?? '') === (string) $expected['host']
        && (string) ($connection['hostport'] ?? '') === (string) $expected['port']
        && (string) ($connection['database'] ?? '') === $expected['database'];
}

/**
 * Bind the paired-backup source inside this APP's own registry before comparing
 * its connection. A schema name never selects a resource or another deployment.
 *
 * @param array<string,mixed> $registry
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function registeredApplicationBackupSource(array $registry, array $rows): array
{
    if (($registry['authority']['role'] ?? null) !== 'application') {
        throw new RuntimeException('OPS_BACKUP_APPLICATION_REGISTRY_REQUIRED');
    }
    $selectedId = getenv('PEANUT_DATABASE_RESOURCE_ID');
    $sources = array_values(array_filter($rows, static fn (array $row): bool =>
        ($row['compose_file'] ?? null) === 'server/docker/compose.yaml'
        && is_string($row['backup_resource_id'] ?? null)
        && is_string($row['database_resource_id'] ?? null)
        && in_array('production', $row['environments'] ?? [], true)
        && ($selectedId === false || trim($selectedId) === '' || $row['database_resource_id'] === trim($selectedId))));
    if (count($sources) !== 1) {
        throw new RuntimeException('OPS_BACKUP_SOURCE_NOT_REGISTERED');
    }
    $source = $sources[0];
    $database = registeredDatabase($registry, $source['database_resource_id']);
    $endpoint = registeredDatabaseEndpoint($database, 'container');
    if (env('APP_ENV', false) !== 'production'
        || !registeredMysqlConnectionMatches([
            'host' => $endpoint['host'], 'port' => $endpoint['port'], 'database' => $database['database'] ?? null,
        ])
        || !in_array('production', $database['environments'] ?? [], true)
        || ($database['application_runtime'] ?? null) !== true
        || ($database['service_type'] ?? null) !== 'mysql'
        || ($database['fallback'] ?? null) !== 'none') {
        throw new RuntimeException('OPS_BACKUP_APPLICATION_CONNECTION_MISMATCH');
    }
    return $source;
}

