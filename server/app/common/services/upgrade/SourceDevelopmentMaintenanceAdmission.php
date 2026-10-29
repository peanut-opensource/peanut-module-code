<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use RuntimeException;

/** Shared native source/actor/lease facts; owns no PDO, migration or recovery window. */
final readonly class SourceDevelopmentMaintenanceAdmission
{
    public function __construct(private string $serverRoot)
    {
        if (realpath($serverRoot) !== $serverRoot) { throw new RuntimeException('APPLICATION_SCHEMA_ROOT_INVALID'); }
        require_once $serverRoot . '/database/install.php';
        require_once $serverRoot . '/database/environment-guard.php';
    }

    public function capture(array $actor, bool $ddl, array $writeTables = []): array
    {
        foreach (['owner', 'thread', 'session', 'gate'] as $key) {
            if (!is_string($actor[$key] ?? null) || trim($actor[$key]) === '' || preg_match('/[\x00-\x1f]/', $actor[$key])) {
                throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_ACTOR_REQUIRED');
            }
        }
        $root = dirname($this->serverRoot);
        if (($actor['scope'] ?? 'source-development') !== 'source-development') {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_CONTEXT_REQUIRED');
        }
        foreach ([$root . '/.peanut/application-manifest.json', $root . '/.peanut/release-identity.json',
            $root . '/.peanut/development-runtime.json', $this->serverRoot . '/.peanut/release-identity.json',
            $this->serverRoot . '/.peanut/development-runtime.json'] as $marker) {
            if (file_exists($marker) || is_link($marker)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_CONTEXT_REQUIRED'); }
        }
        if (!\installationSourceDevelopmentMode($this->serverRoot)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_CONTEXT_REQUIRED'); }
        $owner = fileowner($root);
        if (!is_int($owner) || !function_exists('posix_geteuid') || !in_array(posix_geteuid(), [0, $owner], true)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_OWNER_REQUIRED'); }
        $head = \leaseGit(['-C', $root, 'rev-parse', 'HEAD^{commit}']);
        $dirty = \leaseGit(['-C', $root, 'status', '--porcelain', '--untracked-files=normal']);
        if ($dirty !== '') { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DIRTY'); }
        $registry = \projectResourceRegistry($registrySha);
        $config = \guardedDatabaseConfig();
        $deployment = \deploymentTargetContract($config['deployment_target']);
        if ($config['environment'] !== 'development' || str_contains($config['deployment_target'], 'production')
            || !in_array($deployment['resource_environment'], ['development', 'development-test', 'local-multi-tenant-demo', 'local-standalone-demo'], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RESOURCE_REQUIRED');
        }
        $database = \registeredDatabase($registry, $config['resource_id']);
        $endpoint = \registeredDatabaseEndpoint($database, $config['consumer']);
        $schema = \mysqlSchemaLeaseResource($database, $endpoint, $config);
        if ($schema === null) { throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_SCHEMA_LEASE_REQUIRED'); }
        $path = \requiredMaintenanceEnvironment('PEANUT_RESOURCE_LEASE_PROOF');
        $meta = \activeLeaseMetadata($path, time(), $actor['gate']); $resources = \activeLeaseResources($path);
        if ($meta['owner'] !== $actor['owner'] || $meta['thread'] !== $actor['thread'] || $meta['candidate'] !== $head
            || $meta['gate'] !== ($database['lease_gate'] ?? null)
            || !in_array($actor['session'], $resources['workflow-session'] ?? [], true)) { throw new RuntimeException('APPLICATION_SCHEMA_NATIVE_ACTOR_MISMATCH'); }
        $nativeProof = \assertNativeMysqlSchemaLease($root, $path, $meta, $resources, $config['resource_id'], $schema, $config['endpoint_id'], $registrySha);
        // Preserve the registered legacy-exclusive contract; shared DB1 uses its native access declaration.
        $legacyExclusive = !isset($database['lease_policy']['shared_access']) && !isset($resources['mysql-access']);
        $access = $legacyExclusive; $nativeOperation = $legacyExclusive ? 'exclusive' : null;
        foreach ($resources['mysql-access'] ?? [] as $value) {
            $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            if (($value['schema'] ?? null) === $schema) { $nativeOperation = $value['operation'] ?? null; }
            $access = $access || (($value['schema'] ?? null) === $schema && ($value['complete'] ?? null) === true
                && in_array($value['operation'] ?? null, ['ddl', 'exclusive'], true) && ($value['tables'] ?? null) === []);
        }
        if ($ddl && !$access) { throw new RuntimeException('APPLICATION_SCHEMA_DDL_LEASE_REQUIRED'); }
        if ($writeTables !== []) { self::assertWriteTables($resources, $schema, $writeTables, $legacyExclusive); }
        $sourceFiles = [];
        foreach (['release-versions.json', 'plugins.lock', 'server/composer.lock', 'server/vendor/composer/installed.json'] as $file) { $sourceFiles[$file] = $this->fileDigest($root . '/' . $file); }
        $core = dirname((string) (new \ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 4);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($core, \FilesystemIterator::SKIP_DOTS),
            static fn($item): bool => !in_array($item->getFilename(), ['.git', '.local', 'vendor', 'tests', 'node_modules'], true)));
        foreach ($iterator as $file) {
            if ($file->isLink()) { throw new RuntimeException('APPLICATION_SCHEMA_CORE_IDENTITY_INVALID'); }
            if ($file->isFile() && ($file->getExtension() === 'php' || $file->getFilename() === 'composer.json')) {
                $sourceFiles['core/' . substr($file->getPathname(), strlen($core) + 1)] = $this->fileDigest($file->getPathname());
            }
        }
        // This is a flat path=>digest map; the original canonical digest sorts these same keys.
        ksort($sourceFiles, SORT_STRING);
        $sourceDigest = hash('sha256', json_encode($sourceFiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $originals = [];
        foreach (['installed.json'] as $name) {
            $file = $this->serverRoot . '/private/installation/' . $name;
            $originals[$name] = file_exists($file) || is_link($file) ? $this->fileDigest($file) : null;
        }
        $environmentName = (string) env('env_name', '');
        $environmentPath = $this->serverRoot . '/.env' . ($environmentName !== '' ? '.' . $environmentName : '');
        return ['source' => ['root' => $root, 'head' => $head, 'tree' => \leaseGit(['-C', $root, 'rev-parse', 'HEAD^{tree}']),
                'files_sha256' => $sourceDigest, 'php_binary_sha256' => $this->fileDigest(PHP_BINARY)],
            'environment_sha256' => $this->fileDigest($environmentPath),
            'installation_originals' => $originals, 'registry_sha256' => $registrySha,
            'resource_id' => $config['resource_id'], 'endpoint_id' => $config['endpoint_id'],
            'validation_only' => $database['lease_gate'] === 'migration-validation',
            'authority' => ['lease' => $meta['lease'], 'owner' => $meta['owner'], 'thread' => $meta['thread'], 'gate' => $meta['gate'],
                'session' => $actor['session'], 'created_at' => $meta['created_at'],
                'expires_at' => $nativeProof['binding_expires_at'] ?? $meta['expires_at'],
                'operation' => $nativeOperation, 'resources_sha256' => hash_file('sha256', $path . '/resources.tsv')]];
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_FILE_INVALID'); }
        return (string) hash_file('sha256', $path);
    }

    /** Called only after the native verifier has admitted this exact live declaration. */
    private static function assertWriteTables(array $resources, string $schema, array $tables, bool $legacyExclusive): void
    {
        if (!array_is_list($tables) || $tables === [] || count(array_unique($tables)) !== count($tables)) {
            throw new RuntimeException('PLUGIN_SOURCE_WRITE_SCOPE_INVALID');
        }
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $table) !== 1) {
                throw new RuntimeException('PLUGIN_SOURCE_WRITE_SCOPE_INVALID');
            }
        }
        if ($legacyExclusive) { return; }
        foreach ($resources['mysql-access'] ?? [] as $raw) {
            $scope = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (($scope['schema'] ?? null) !== $schema || ($scope['complete'] ?? null) !== true) { continue; }
            if (in_array($scope['operation'] ?? null, ['ddl', 'exclusive'], true) && ($scope['tables'] ?? null) === []) { return; }
            if (($scope['operation'] ?? null) !== 'transaction') { continue; }
            $writes = [];
            foreach ($scope['tables'] ?? [] as $entry) {
                if (($entry['access'] ?? null) === 'write' && ($entry['rows'] ?? null) === '*') { $writes[] = $entry['table']; }
            }
            if (array_diff($tables, $writes) === []) { return; }
        }
        throw new RuntimeException('PLUGIN_SOURCE_CATALOG_WRITE_SCOPE_REQUIRED');
    }

}
