#!/usr/bin/env php
<?php

declare(strict_types=1);

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\infrastructure\module\ModuleLockResolver;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;

$rootOption = '--application-root=';
$roots = array_values(array_filter(array_slice($argv, 1), static fn(string $argument): bool => str_starts_with($argument, $rootOption)));
$markdown = in_array('--markdown', $argv, true);
$inventoryJson = in_array('--inventory-json', $argv, true);

/** @return list<array{method:string,path:string,permission:string,access:string}> */
function adminApiMatrix(array $moduleRoots, array $inventory, array $accessConfig): array
{
    // Runtime API admission uses permission bundles, independently of menu paths.
    $permissionKeys = array_fill_keys(CorePermissionCatalog::TENANT, true);
    $loader = new ManifestLoader();
    foreach ($moduleRoots as $moduleRoot) {
        $manifest = $loader->load($moduleRoot)->data;
        $permissionPath = $manifest['backend']['permissions'] ?? null;
        if ($permissionPath === null) {
            continue;
        }
        $permissions = json_decode((string) file_get_contents($moduleRoot . '/' . $permissionPath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($permissions as $permission) {
            $permissionKeys[$permission['key']] = true;
        }
    }

    $authenticated = array_fill_keys(normalizedExceptionRoutes($accessConfig['authenticated'] ?? []), true);
    $matrix = [];
    foreach ($inventory['endpoints'] as $endpoint) {
        if (($endpoint['source'] ?? null) !== 'server/route/admin.php'
            || !hasMiddleware($endpoint, app\adminapi\http\middleware\AuthMiddleware::class)) {
            continue;
        }
        $method = strtoupper((string) $endpoint['method']);
        $path = strtolower(trim((string) $endpoint['path'], '/'));
        $permission = $endpoint['permission'] ?? substr($path, strlen('adminapi/'));
        $routeKey = $method . ' ' . $path;
        $access = isset($authenticated[$routeKey])
            ? 'authenticated'
            : (isset($permissionKeys[$permission]) ? 'permission' : 'unregistered');
        $matrix[] = compact('method', 'path', 'permission', 'access');
    }
    return $matrix;
}

function hasMiddleware(array $endpoint, string $middleware): bool
{
    foreach ($endpoint['middleware'] ?? [] as $descriptor) {
        if (($descriptor['class'] ?? null) === $middleware) {
            return true;
        }
    }
    return false;
}

/** @return list<string> */
function normalizedExceptionRoutes(mixed $routes): array
{
    if (!is_array($routes)) {
        throw new RuntimeException('admin API exception metadata must be an array');
    }
    $normalized = [];
    foreach ($routes as $route) {
        if (preg_match('/^([A-Z]+)\s+(.+)$/i', trim((string) $route), $match) !== 1) {
            throw new RuntimeException('invalid admin API exception route: ' . (string) $route);
        }
        $normalized[] = strtoupper($match[1]) . ' ' . strtolower(trim($match[2], '/'));
    }
    return array_values(array_unique($normalized));
}

try {
    $selectedRoot = isset($roots[0]) ? substr($roots[0], strlen($rootOption)) : dirname(__DIR__);
    $repositoryRoot = count($roots) <= 1 && $selectedRoot !== '' ? realpath($selectedRoot) : false;
    if ($repositoryRoot === false || !is_file($repositoryRoot . '/server/vendor/autoload.php')) {
        throw new RuntimeException('Use one application root with its own installed Composer lock.');
    }
    $serverRoot = $repositoryRoot . '/server';
    require_once $serverRoot . '/vendor/autoload.php';
    require_once $serverRoot . '/route/registry_source.php';
    $moduleRoots = [];
    foreach ((new ModuleLockResolver($serverRoot, '../modules.lock'))->all() as $module) {
        $moduleRoots += $module->moduleRoots;
    }
    if (!isset($moduleRoots['peanut.identity'])) {
        throw new RuntimeException('The application must adopt and lock the Identity module before permission checking.');
    }
    ModuleHostLayoutFactory::registerRuntimeAutoload($moduleRoots, $serverRoot);
    $inventory = peanut_route_endpoint_inventory($serverRoot);
    $accessConfig = require $serverRoot . '/config/admin_api_access.php';
    if ($markdown && $inventoryJson) {
        throw new RuntimeException('--markdown and --inventory-json cannot be combined');
    }
    if (($accessConfig['version'] ?? null) !== 1) {
        throw new RuntimeException('admin API exception metadata version must be exactly 1');
    }
    $public = normalizedExceptionRoutes($accessConfig['public'] ?? []);
    $authenticated = normalizedExceptionRoutes($accessConfig['authenticated'] ?? []);
    $overlap = array_values(array_intersect($public, $authenticated));
    if ($overlap !== []) {
        throw new RuntimeException('routes cannot be both public and authenticated: ' . implode(', ', $overlap));
    }
    $platformPublic = normalizedExceptionRoutes($accessConfig['platform_public'] ?? []);
    $platformAuthenticated = normalizedExceptionRoutes($accessConfig['platform_authenticated'] ?? []);
    $platformOverlap = array_values(array_intersect($platformPublic, $platformAuthenticated));
    if ($platformOverlap !== []) {
        throw new RuntimeException('Platform routes cannot be both public and authenticated: ' . implode(', ', $platformOverlap));
    }
    foreach (array_merge($public, $authenticated) as $exception) {
        if (str_contains($exception, ' platformapi/')) {
            throw new RuntimeException('platform routes cannot use Tenant Admin metadata: ' . $exception);
        }
    }
    foreach (array_merge($platformPublic, $platformAuthenticated) as $exception) {
        if (!str_contains($exception, ' platformapi/')) {
            throw new RuntimeException('non-platform route cannot use Platform metadata: ' . $exception);
        }
    }

    $platformExceptions = array_fill_keys(array_merge($platformPublic, $platformAuthenticated), true);
    foreach ($inventory['endpoints'] as $platformRoute) {
        if (($platformRoute['application'] ?? null) !== 'platform') {
            continue;
        }
        $method = strtoupper((string) $platformRoute['method']);
        $path = strtolower(trim((string) $platformRoute['path'], '/'));
        $routeKey = $method . ' ' . $path;
        if (isset($platformExceptions[$routeKey])) {
            continue;
        }
        if (!hasMiddleware($platformRoute, app\platform\http\middleware\PlatformLoginMiddleware::class)
            || !hasMiddleware($platformRoute, app\platform\http\middleware\PlatformPermissionMiddleware::class)) {
            throw new RuntimeException('Platform route lacks exact permission metadata: ' . $routeKey);
        }
    }

    $matrix = adminApiMatrix($moduleRoots, $inventory, $accessConfig);
    $known = [];
    foreach ($inventory['endpoints'] as $endpoint) {
        $known[strtoupper((string) $endpoint['method']) . ' ' . strtolower(trim((string) $endpoint['path'], '/'))] = true;
    }
    foreach (array_merge($public, $authenticated, $platformPublic, $platformAuthenticated) as $exception) {
        if (!isset($known[$exception])) {
            throw new RuntimeException('exception metadata does not match an exact route: ' . $exception);
        }
    }

    $unregistered = array_values(array_filter(
        $matrix,
        static fn(array $row): bool => $row['access'] === 'unregistered',
    ));

    if ($markdown) {
        echo "| Method | Admin API route | Access registration |\n";
        echo "|---|---|---|\n";
        foreach ($matrix as $row) {
            $registration = $row['access'] === 'permission'
                ? '`' . $row['permission'] . '`'
                : $row['access'];
            echo sprintf("| %s | `%s` | %s |\n", $row['method'], $row['path'], $registration);
        }
    }

    if ($unregistered !== []) {
        foreach ($unregistered as $row) {
            fwrite(STDERR, sprintf(
                "UNREGISTERED: %s %s (expected permission %s or authenticated metadata)\n",
                $row['method'],
                $row['path'],
                $row['permission'],
            ));
        }
        exit(1);
    }

    $permissionCount = count(array_filter(
        $matrix,
        static fn(array $row): bool => $row['access'] === 'permission',
    ));
    $authenticatedCount = count($matrix) - $permissionCount;
    if ($inventoryJson) {
        echo json_encode(
            $inventory,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    } else {
        echo sprintf(
            "Admin API permission gate passed: %d routes (%d permission, %d authenticated)\n",
            count($matrix),
            $permissionCount,
            $authenticatedCount,
        );
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Admin API permission gate failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
