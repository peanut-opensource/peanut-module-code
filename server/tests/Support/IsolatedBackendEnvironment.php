<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Creates one temporary server/.env.<run-id> for an isolated PHP test process. */
final class IsolatedBackendEnvironment
{
    /** @var list<string> */
    private static array $paths = [];
    private static bool $cleanupRegistered = false;

    public static function activateDatabase(
        string $host,
        int|string $port,
        string $database,
        string $user,
        string $password,
        string $deploymentMode,
        string $prefix = 'pa_',
    ): string {
        if (!in_array($deploymentMode, ['standalone', 'multi-tenant'], true)) {
            throw new RuntimeException('ISOLATED_BACKEND_DEPLOYMENT_MODE_INVALID');
        }
        return self::activate([
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_NAME' => $database,
            'DB_USER' => $user,
            'DB_PASS' => $password,
            'DB_PREFIX' => $prefix,
            'DEPLOYMENT_MODE' => $deploymentMode,
        ]);
    }

    /** @param array<string,string|int|bool> $values */
    public static function activate(array $values): string
    {
        $serverRoot = dirname(__DIR__, 2);
        $app = \think\Container::getInstance();
        if (!$app instanceof \think\App) {
            $app = new \think\App($serverRoot);
        }
        require_once $app->getThinkPath() . 'helper.php';
        $basePath = getenv('PEANUT_SERVER_ENV_FILE');
        if (!is_string($basePath) || $basePath === '') {
            $envName = (string) env('ENV_NAME', '');
            $basePath = $serverRoot . '/.env' . ($envName === '' ? '' : '.' . $envName);
        }
        if (!str_starts_with($basePath, '/') || !is_file($basePath) || is_link($basePath)
            || !is_readable($basePath)) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_BASE_INVALID');
        }
        $baseValues = parse_ini_file($basePath, false, INI_SCANNER_RAW);
        if (!is_array($baseValues)) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_BASE_INVALID');
        }
        $runId = 'test-' . bin2hex(random_bytes(8));
        $path = $serverRoot . '/.env.' . $runId;
        $values = array_replace($baseValues, [
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DEPLOYMENT_MODE' => 'standalone',
            'DB_PREFIX' => 'pa_',
        ], $values);

        $lines = [];
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
                throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_KEY_INVALID');
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException("ISOLATED_BACKEND_ENVIRONMENT_VALUE_INVALID:{$key}");
            }
            $lines[] = $key . '="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        $contents = implode("\n", $lines) . "\n";
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_CREATE_FAILED');
        }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_WRITE_FAILED');
            }
        } finally {
            fclose($handle);
        }

        self::$paths[] = $path;
        self::registerCleanup();
        putenv('PEANUT_SERVER_ENV_FILE=' . $path);
        putenv('PHP_ENV_NAME=' . $runId);
        $app->env->set('ENV_NAME', $runId);
        $app->loadEnv($runId);

        return $path;
    }

    public static function required(string $key): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
            throw new RuntimeException('ISOLATED_BACKEND_ENVIRONMENT_KEY_INVALID');
        }
        $value = str_starts_with($key, 'DB_') ? env($key) : getenv($key);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("ISOLATED_BACKEND_ENVIRONMENT_REQUIRED:{$key}");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function requireRegisteredDatabase(string $registryPath, string $stableResourceId): array
    {
        if (!is_file($registryPath) || is_link($registryPath)) {
            throw new RuntimeException('ISOLATED_BACKEND_RESOURCE_REGISTRY_INVALID');
        }
        $registry = json_decode((string) file_get_contents($registryPath), true, 512, JSON_THROW_ON_ERROR);
        $databases = $registry['resources']['databases'] ?? null;
        if (!is_array($databases)) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_REGISTRY_INVALID');
        }
        $matches = array_values(array_filter(
            $databases,
            static fn(mixed $resource): bool => is_array($resource)
                && ($resource['stable_resource_id'] ?? null) === $stableResourceId,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_INVALID');
        }
        $resource = $matches[0];
        $host = $resource['host'] ?? null;
        $port = $resource['port'] ?? null;
        $database = $resource['database'] ?? null;
        if (!is_string($host) || $host === ''
            || !is_int($port) || $port < 1 || $port > 65535
            || !is_string($database) || preg_match('/^[a-z0-9_]+$/D', $database) !== 1) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_ENDPOINT_INVALID');
        }
        // A task-owned instance may register several exact synthetic databases.
        // Never infer a namespace from a prefix or accept an unlisted database.
        $registeredDatabases = [$database];
        foreach (($resource['synthetic_databases'] ?? []) as $names) {
            if (!is_array($names) || !array_is_list($names)) {
                throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_ENDPOINT_INVALID');
            }
            foreach ($names as $name) {
                if (!is_string($name) || preg_match('/^[a-z0-9_]+$/D', $name) !== 1) {
                    throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_ENDPOINT_INVALID');
                }
                $registeredDatabases[] = $name;
            }
        }
        if (!hash_equals($stableResourceId, self::required('PEANUT_DATABASE_RESOURCE_ID'))
            || !hash_equals($host, self::required('DB_HOST'))
            || (int) self::required('DB_PORT') !== $port
            || !in_array(self::required('DB_NAME'), $registeredDatabases, true)) {
            throw new RuntimeException('ISOLATED_BACKEND_DATABASE_RESOURCE_MISMATCH');
        }
        return $resource;
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$paths) as $path) {
            $serverRoot = dirname(__DIR__, 2);
            if (dirname($path) === $serverRoot
                && preg_match('/^\.env\.test-[a-f0-9]{16}$/D', basename($path)) === 1
                && is_file($path)
                && !is_link($path)) {
                unlink($path);
            }
        }
        self::$paths = [];
        putenv('PEANUT_SERVER_ENV_FILE');
        putenv('PHP_ENV_NAME');
    }

    private static function registerCleanup(): void
    {
        if (self::$cleanupRegistered) {
            return;
        }
        self::$cleanupRegistered = true;
        register_shutdown_function([self::class, 'cleanup']);
    }
}
