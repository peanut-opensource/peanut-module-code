<?php

declare(strict_types=1);

final class PeanutRouteInventoryNode
{
    /** @param list<int> $indices */
    public function __construct(private readonly array $indices) {}

    public function middleware(mixed $middleware, mixed ...$arguments): self
    {
        PeanutRouteInventoryRoute::addMiddleware($this->indices, $middleware, $arguments);
        return $this;
    }

    public function option(array $options): self
    {
        PeanutRouteInventoryRoute::addOptions($this->indices, $options);
        return $this;
    }

    public function __call(string $name, array $arguments): self
    {
        return $this;
    }
}

final class PeanutRouteInventoryRoute
{
    /** @var list<array<string,mixed>> */
    private static array $endpoints = [];

    /** @var list<string> */
    private static array $prefixes = [];

    private static string $application = '';

    private static string $applicationPrefix = '';

    private static string $serverRoot = '';

    public static function reset(string $serverRoot): void
    {
        self::$endpoints = [];
        self::$prefixes = [];
        self::$application = '';
        self::$applicationPrefix = '';
        self::$serverRoot = rtrim($serverRoot, '/');
    }

    public static function beginApplication(string $application, string $externalPrefix): void
    {
        if (self::$prefixes !== []) {
            throw new RuntimeException('Route inventory application changed inside a route group');
        }
        self::$application = $application;
        self::$applicationPrefix = trim($externalPrefix, '/');
    }

    public static function get(string $path, mixed $handler): PeanutRouteInventoryNode
    {
        return self::add('GET', $path, $handler);
    }

    public static function post(string $path, mixed $handler): PeanutRouteInventoryNode
    {
        return self::add('POST', $path, $handler);
    }

    public static function put(string $path, mixed $handler): PeanutRouteInventoryNode
    {
        return self::add('PUT', $path, $handler);
    }

    public static function delete(string $path, mixed $handler): PeanutRouteInventoryNode
    {
        return self::add('DELETE', $path, $handler);
    }

    public static function patch(string $path, mixed $handler): PeanutRouteInventoryNode
    {
        return self::add('PATCH', $path, $handler);
    }

    public static function group(mixed $prefix, ?callable $callback = null): PeanutRouteInventoryNode
    {
        if (is_callable($prefix) && $callback === null) {
            $callback = $prefix;
            $prefix = '';
        }
        if ($callback === null) {
            throw new RuntimeException('Route inventory group callback is missing');
        }

        $before = count(self::$endpoints);
        self::$prefixes[] = trim((string) $prefix, '/');
        try {
            $callback();
        } finally {
            array_pop(self::$prefixes);
        }

        $after = count(self::$endpoints);
        return new PeanutRouteInventoryNode($after > $before ? range($before, $after - 1) : []);
    }

    /** @param list<int> $indices @param list<mixed> $arguments */
    public static function addMiddleware(array $indices, mixed $middleware, array $arguments): void
    {
        $descriptors = self::middlewareDescriptors($middleware, $arguments);
        foreach ($indices as $index) {
            foreach ($descriptors as $descriptor) {
                self::$endpoints[$index]['middleware'][] = $descriptor;
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public static function endpoints(): array
    {
        return self::$endpoints;
    }

    /** @param list<int> $indices @param array<string,mixed> $options */
    public static function addOptions(array $indices, array $options): void
    {
        if (!array_key_exists('peanut_permission', $options)) {
            return;
        }
        $permission = $options['peanut_permission'];
        if (!is_string($permission) || trim($permission) === '') {
            throw new RuntimeException('Route inventory permission must be a non-empty string');
        }
        foreach ($indices as $index) {
            self::$endpoints[$index]['permission'] = $permission;
        }
    }

    private static function add(string $method, string $path, mixed $handler): PeanutRouteInventoryNode
    {
        if (!is_array($handler) || count($handler) !== 2 || !is_string($handler[0]) || !is_string($handler[1])) {
            throw new RuntimeException('Route inventory requires a controller/action handler');
        }

        $parts = array_values(array_filter(
            [self::$applicationPrefix, ...self::$prefixes, trim($path, '/')],
            static fn(string $part): bool => $part !== '',
        ));
        [$source, $line] = self::sourceLocation();
        self::$endpoints[] = [
            'method' => $method,
            'path' => '/' . implode('/', $parts),
            'controller' => $handler[0],
            'action' => $handler[1],
            'source' => $source,
            'line' => $line,
            'application' => self::$application,
            'middleware' => [],
            'permission' => null,
        ];

        return new PeanutRouteInventoryNode([array_key_last(self::$endpoints)]);
    }

    /** @return array{string,int} */
    private static function sourceLocation(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '' || $file === __FILE__ || !str_starts_with($file, self::$serverRoot . '/')) {
                continue;
            }
            return ['server/' . substr($file, strlen(self::$serverRoot) + 1), (int) ($frame['line'] ?? 0)];
        }
        throw new RuntimeException('Route inventory source location is unavailable');
    }

    /** @param list<mixed> $arguments @return list<array{class:string,arguments:list<mixed>}> */
    private static function middlewareDescriptors(mixed $middleware, array $arguments): array
    {
        if ($arguments !== []) {
            return [self::middlewareDescriptor($middleware, $arguments)];
        }
        if (!is_array($middleware)) {
            return [self::middlewareDescriptor($middleware, [])];
        }
        if (count($middleware) === 2 && is_string($middleware[0] ?? null) && is_array($middleware[1] ?? null)) {
            return [self::middlewareDescriptor($middleware[0], array_values($middleware[1]))];
        }

        $descriptors = [];
        foreach ($middleware as $item) {
            if (is_array($item)) {
                $class = array_shift($item);
                $item = count($item) === 1 && is_array($item[0]) ? array_values($item[0]) : array_values($item);
                $descriptors[] = self::middlewareDescriptor($class, $item);
                continue;
            }
            $descriptors[] = self::middlewareDescriptor($item, []);
        }
        return $descriptors;
    }

    /** @param list<mixed> $arguments @return array{class:string,arguments:list<mixed>} */
    private static function middlewareDescriptor(mixed $middleware, array $arguments): array
    {
        if (!is_string($middleware) || $middleware === '') {
            throw new RuntimeException('Route inventory middleware class is invalid');
        }
        return ['class' => $middleware, 'arguments' => $arguments];
    }
}

/** Read the complete route registry for static contracts without executing it. */
function peanut_route_registry_source(string $serverRoot): string
{
    $serverRoot = rtrim($serverRoot, '/');
    $routeRoot = $serverRoot . '/route';
    $files = [
        '../app/adminapi/route/app.php',
        '../app/api/route/app.php',
        '../app/platform/route/app.php',
        '../app/installation/route/app.php',
        'app.php',
        'platform.php',
        'tenant.php',
        'admin.php',
        'public_api.php',
    ];

    foreach (glob($serverRoot . '/app/modules/*/*/route/app.php') ?: [] as $moduleRoute) {
        $files[] = '../' . substr($moduleRoute, strlen($serverRoot) + 1);
    }

    $source = '';
    foreach ($files as $file) {
        $path = $routeRoot . '/' . $file;
        if (!is_file($path)) {
            throw new RuntimeException('Route registry file is missing: ' . $file);
        }
        $source .= "\n/* {$file} */\n" . (string) file_get_contents($path);
    }
    return $source;
}

/**
 * Execute the static route declarations against a no-I/O facade.
 *
 * @return array{schema_version:int,summary:array<string,mixed>,endpoints:list<array<string,mixed>>}
 */
function peanut_route_endpoint_inventory(string $serverRoot): array
{
    $resolvedRoot = realpath($serverRoot);
    if ($resolvedRoot === false || !is_dir($resolvedRoot . '/route')) {
        throw new RuntimeException('Route inventory server root is invalid');
    }

    $routeFacade = 'think\\facade\\Route';
    if (class_exists($routeFacade, false)
        && !is_a($routeFacade, PeanutRouteInventoryRoute::class, true)) {
        throw new RuntimeException('Route inventory must run before the ThinkPHP Route facade is loaded');
    }
    if (!class_exists($routeFacade, false)
        && !class_alias(PeanutRouteInventoryRoute::class, $routeFacade)) {
        throw new RuntimeException('Route inventory facade could not be registered');
    }

    $moduleNamespaces = [];
    $moduleSources = [];
    $declaredModules = [];
    foreach (glob($resolvedRoot . '/app/modules/*/*/module.json') ?: [] as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        $moduleKey = $manifest['key'] ?? null;
        $provider = $manifest['backend']['provider'] ?? null;
        if (!is_string($moduleKey) || $moduleKey === '' || !is_string($provider)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]+$/D', $provider) !== 1) {
            throw new RuntimeException('Route inventory Module manifest is invalid: ' . $manifestPath);
        }
        $composerPath = dirname($manifestPath) . '/composer.json';
        $composer = json_decode((string) file_get_contents($composerPath), true, 32, JSON_THROW_ON_ERROR);
        $psr4 = is_array($composer) && is_array($composer['autoload']['psr-4'] ?? null)
            ? $composer['autoload']['psr-4']
            : null;
        $namespace = is_array($psr4) && count($psr4) === 1 ? array_key_first($psr4) : null;
        if (!is_string($namespace) || ($psr4[$namespace] ?? null) !== 'src/'
            || !str_ends_with($namespace, '\\') || !str_starts_with($provider, $namespace)) {
            throw new RuntimeException('Route inventory Module Composer namespace is invalid: ' . $composerPath);
        }

        $relative = substr(dirname($manifestPath), strlen($resolvedRoot . '/app/modules/'));
        $moduleNamespaces[$namespace] = $moduleKey;
        $moduleSources['server/app/modules/' . $relative . '/'] = $moduleKey;
        $declaredModules[$moduleKey] = dirname($manifestPath);
    }

    // Static collection uses the source application's locked selection without booting ThinkPHP.
    $lockPath = dirname($resolvedRoot) . '/plugins.lock';
    if (!is_file($lockPath) || is_link($lockPath)) {
        throw new RuntimeException('Route inventory Plugin lock is unavailable: ' . $lockPath);
    }
    $lock = json_decode((string) file_get_contents($lockPath), true, 64, JSON_THROW_ON_ERROR);
    if (($lock['schema_version'] ?? null) !== 1 || !is_array($lock['plugins'] ?? null)) {
        throw new RuntimeException('Route inventory Plugin lock is invalid: ' . $lockPath);
    }
    $peanutRouteModules = [];
    foreach ($lock['plugins'] as $plugin) {
        if (!is_array($plugin['modules'] ?? null)) {
            throw new RuntimeException('Route inventory Plugin Module selection is invalid.');
        }
        foreach ($plugin['modules'] as $module) {
            $key = $module['key'] ?? null;
            $root = $module['root'] ?? null;
            if (!is_string($key) || !is_string($root) || isset($peanutRouteModules[$key])
                || !isset($declaredModules[$key])
                || realpath(dirname($resolvedRoot) . '/' . $root) !== $declaredModules[$key]) {
                throw new RuntimeException('Route inventory locked Module does not match its declaration.');
            }
            $peanutRouteModules[$key] = $declaredModules[$key];
        }
    }

    PeanutRouteInventoryRoute::reset($resolvedRoot);
    foreach ([
        'adminapi' => 'adminapi',
        'api' => 'api',
        'platform' => 'platformapi',
        'installation' => 'installapi',
    ] as $application => $externalPrefix) {
        PeanutRouteInventoryRoute::beginApplication($application, $externalPrefix);
        require $resolvedRoot . '/app/' . $application . '/route/app.php';
    }

    $endpoints = PeanutRouteInventoryRoute::endpoints();
    foreach ($endpoints as &$endpoint) {
        $controller = (string) $endpoint['controller'];
        $owner = null;
        foreach ($moduleSources as $sourcePrefix => $moduleKey) {
            if (str_starts_with((string) $endpoint['source'], $sourcePrefix)) {
                $owner = ['type' => 'module', 'key' => $moduleKey];
                break;
            }
        }
        foreach ($moduleNamespaces as $namespace => $moduleKey) {
            if ($owner === null && str_starts_with($controller, $namespace)) {
                $owner = ['type' => 'module', 'key' => $moduleKey];
                break;
            }
        }
        if ($owner === null) {
            $owner = ['type' => 'application', 'key' => (string) $endpoint['application']];
        }
        if ($owner === null) {
            throw new RuntimeException('Route inventory owner is unknown: ' . $controller);
        }
        $endpoint['owner'] = $owner;
    }
    unset($endpoint);

    usort($endpoints, static fn(array $left, array $right): int => [
        $left['method'],
        $left['path'],
    ] <=> [
        $right['method'],
        $right['path'],
    ]);

    $methods = [];
    $owners = [];
    $applications = [];
    $identities = [];
    foreach ($endpoints as $endpoint) {
        $methods[$endpoint['method']] = ($methods[$endpoint['method']] ?? 0) + 1;
        $owner = $endpoint['owner']['type'] . ':' . $endpoint['owner']['key'];
        $owners[$owner] = ($owners[$owner] ?? 0) + 1;
        $application = (string) $endpoint['application'];
        $applications[$application] = ($applications[$application] ?? 0) + 1;
        $identity = $endpoint['method'] . ' ' . $endpoint['path'];
        $identities[$identity] = ($identities[$identity] ?? 0) + 1;
    }
    ksort($methods, SORT_STRING);
    ksort($owners, SORT_STRING);
    ksort($applications, SORT_STRING);
    $duplicates = array_keys(array_filter($identities, static fn(int $count): bool => $count > 1));
    if ($duplicates !== []) {
        throw new RuntimeException('Route inventory contains duplicate method/path: ' . implode(', ', $duplicates));
    }

    return [
        'schema_version' => 1,
        'modules' => array_map(
            static fn(string $root): string => 'server/' . substr($root, strlen($resolvedRoot) + 1),
            $peanutRouteModules,
        ),
        'summary' => [
            'total' => count($endpoints),
            'methods' => $methods,
            'applications' => $applications,
            'owners' => $owners,
            'duplicates' => [],
        ],
        'endpoints' => $endpoints,
    ];
}
