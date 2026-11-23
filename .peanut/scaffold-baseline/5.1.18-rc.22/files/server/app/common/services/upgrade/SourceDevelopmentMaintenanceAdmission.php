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
        $head = $this->git($root, ['rev-parse', 'HEAD^{commit}']);
        $dirty = $this->git($root, ['status', '--porcelain', '--untracked-files=normal']);
        if ($dirty !== '') { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_DIRTY'); }
        $registry = \projectResourceRegistry($registrySha);
        $config = \guardedDatabaseConfig();
        $deployment = \deploymentTargetContract($config['deployment_target']);
        if ($config['environment'] !== 'development' || str_contains($config['deployment_target'], 'production')
            || !in_array($deployment['resource_environment'], ['development', 'development-test', 'local-multi-tenant-demo', 'local-standalone-demo'], true)) {
            throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_RESOURCE_REQUIRED');
        }
        $database = \registeredDatabase($registry, $config['resource_id']);
        \registeredDatabaseEndpoint($database, $config['consumer']);
        $sourceFiles = [];
        foreach (['release-versions.json', 'modules.lock', 'server/composer.lock', 'server/vendor/composer/installed.json'] as $file) { $sourceFiles[$file] = $this->fileDigest($root . '/' . $file); }
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
        return ['source' => ['root' => $root, 'head' => $head, 'tree' => $this->git($root, ['rev-parse', 'HEAD^{tree}']),
                'files_sha256' => $sourceDigest, 'php_binary_sha256' => $this->fileDigest(PHP_BINARY)],
            'environment_sha256' => $this->fileDigest($environmentPath),
            'installation_originals' => $originals, 'registry_sha256' => $registrySha,
            'resource_id' => $config['resource_id'], 'endpoint_id' => $config['endpoint_id'],
            'validation_only' => ($database['lease_gate'] ?? null) === 'migration-validation',
            'authority' => ['owner' => $actor['owner'], 'thread' => $actor['thread'], 'gate' => $actor['gate'],
                'session' => $actor['session'], 'operation' => 'exclusive']];
    }

    private function git(string $root, array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $root, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('APPLICATION_SCHEMA_GIT_UNAVAILABLE');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || trim((string) $stderr) !== '') {
            throw new RuntimeException('APPLICATION_SCHEMA_GIT_FAILED');
        }
        return trim((string) $stdout);
    }

    private function fileDigest(string $path): string
    {
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('APPLICATION_SCHEMA_SOURCE_FILE_INVALID'); }
        return (string) hash_file('sha256', $path);
    }
}
