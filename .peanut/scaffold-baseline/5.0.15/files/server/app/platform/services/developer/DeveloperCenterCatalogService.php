<?php

declare(strict_types=1);

namespace app\platform\services\developer;

use app\platform\infrastructure\plugin\DevelopmentModuleDiscovery;
use app\platform\services\plugin\PluginPackageArchiveService;
use app\platform\value\plugin\ModuleFrontendLayout;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use Throwable;

/**
 * Read-only projection over the Module manifests, route registry, generated API catalog and package tooling.
 * It deliberately keeps declaration, discovery, registration, runtime evidence and test evidence separate.
 */
final readonly class DeveloperCenterCatalogService
{
    public function __construct(
        private string $serverRoot,
        private CompiledModuleRegistry $registry,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(?string $selectedModuleKey = null): array
    {
        $selectedModuleKey = $this->selectedKey($selectedModuleKey);
        $projectRoot = dirname($this->serverRoot);
        $declarations = $this->declarations($selectedModuleKey);
        [$discovery, $discoveredRoots] = $this->discovery($projectRoot);
        [$registration, $registeredKeys, $registryRevision] = $this->registration();
        $apiCatalog = $this->apiCatalog();
        $routes = $apiCatalog['endpoints'];
        // 路由已发现不等于请求／响应合同已经完整，分别计算并展示。
        $documentedOperations = array_values(array_filter(
            $routes,
            static fn(array $route): bool => ($route['documented'] ?? false) === true,
        ));
        $completeOperations = array_values(array_filter(
            $documentedOperations,
            static fn(array $route): bool => ($route['contract_quality'] ?? null) === 'complete',
        ));
        $partialOperations = array_values(array_filter(
            $documentedOperations,
            static fn(array $route): bool => ($route['contract_quality'] ?? null) !== 'complete',
        ));
        $commands = $this->moduleCommands();
        $modules = [];
        foreach ($declarations as $key => $declaration) {
            $data = $declaration['data'];
            $root = $declaration['root'];
            $moduleRoutes = $this->moduleRoutes($routes, $key);
            $generatedApi = $this->moduleRoutes($documentedOperations, $key);
            $modules[] = [
                'key' => $key,
                'name' => (string) ($data['name'] ?? $key),
                'description' => (string) ($data['description'] ?? ''),
                'version' => is_string($data['version'] ?? null) ? $data['version'] : null,
                'source' => [
                    'manifest' => $this->relative($declaration['manifest']),
                    'manifest_sha256' => $declaration['sha256'],
                    'backend_root' => $this->relative($root),
                    'frontend_entry' => $data['frontend']['entry'] ?? null,
                    'frontend_clients' => $this->frontendContributions($data, $key),
                ],
                'evidence' => [
                    'declaration' => $this->state('declared', 'MODULE_DECLARATION_FOUND', 'module.json is readable.'),
                    'discovery' => isset($discoveredRoots[$key])
                        ? $this->state('discovered', 'MODULE_DISCOVERED', 'The strict development discovery accepted this Module.')
                        : $this->state($discovery['status'], $discovery['code'], $discovery['reason']),
                    'registration' => in_array($key, $registeredKeys, true)
                        ? $this->state('registered', 'MODULE_REGISTERED', 'The locked Module registry compiled this Module.')
                        : $this->state($registration['status'], $registration['code'], $registration['reason']),
                    'runtime_effective' => $this->state(
                        'not_checked',
                        'MODULE_RUNTIME_NOT_PROBED',
                        'This read-only catalog does not instantiate business services or perform database operations.',
                    ),
                    'tests' => $this->state(
                        'not_recorded',
                        'MODULE_TEST_EVIDENCE_NOT_RECORDED',
                        'No version-bound test receipt is registered for this catalog snapshot.',
                    ),
                ],
                'dependencies' => $this->dependencies($data, $declarations, $registeredKeys),
                'dependants' => $this->dependants($key, $declarations, $registeredKeys),
                'routes' => $moduleRoutes,
                'generated_api' => $generatedApi,
                'permissions' => $this->permissions($data),
                'public_services' => $this->publicServices($data),
                'provider' => $this->provider($data),
                'middleware' => $this->middleware($moduleRoutes),
                'lifecycle' => $this->lifecycle($data),
                'events' => array_values((array) ($data['contracts']['events'] ?? [])),
                'tasks' => $this->tasks($key, $data, $commands),
                'migrations' => $this->migrations(),
                'documentation' => $this->documentation($root),
                'package_preview' => $this->packagePreview($key),
            ];
        }

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'read_only' => true,
            'sources' => [
                'module_declarations' => 'server/app/modules/*/*/module.json',
                'registration' => 'Current ThinkPHP App CompiledModuleRegistry',
                'routes' => 'server/generated/api-catalog.json (generated from peanut_route_endpoint_inventory)',
                'generated_api' => 'server/generated/api-catalog.json',
                'package_preview' => 'PluginPackageArchiveService::previewModule',
            ],
            'status' => [
                'declaration' => $this->state('declared', 'MODULE_DECLARATIONS_READ', count($declarations) . ' declarations read.'),
                'discovery' => $discovery,
                'registration' => $registration + ['revision' => $registryRevision],
                'runtime_effective' => $this->state('not_checked', 'MODULE_RUNTIME_NOT_PROBED', 'No runtime business probe was requested.'),
                'tests' => $this->state('not_recorded', 'MODULE_TEST_EVIDENCE_NOT_RECORDED', 'No test receipt was supplied.'),
                'api_catalog' => $apiCatalog['status'],
            ],
            'summary' => [
                'modules' => count($modules),
                'discovered' => count($discoveredRoots),
                'registered' => count($registeredKeys),
                'routes' => count($routes),
                'generated_api_operations' => count($documentedOperations),
                'complete_api_operations' => count($completeOperations),
                'partial_api_operations' => count($partialOperations),
                'undocumented_routes' => count($routes) - count($documentedOperations),
            ],
            'modules' => $modules,
        ];
    }

    /** @return array<string,array{manifest:string,root:string,sha256:string,data:array<string,mixed>}> */
    private function declarations(?string $selectedModuleKey): array
    {
        $paths = glob($this->serverRoot . '/app/modules/*/*/module.json') ?: [];
        sort($paths, SORT_STRING);
        $modules = [];
        foreach ($paths as $path) {
            try {
                $document = (new ManifestLoader())->load(dirname($path));
                $data = $document->data;
            } catch (Throwable) {
                try {
                    $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }
            }
            $key = is_array($data) && is_string($data['key'] ?? null) ? trim($data['key']) : '';
            if ($key === '' || ($selectedModuleKey !== null && $key !== $selectedModuleKey)) {
                continue;
            }
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                continue;
            }
            $modules[$key] = [
                'manifest' => $path,
                'root' => dirname($path),
                'sha256' => $digest,
                'data' => $data,
            ];
        }
        ksort($modules, SORT_STRING);
        return $modules;
    }

    /** @return array{0:array{status:string,code:string,reason:string},1:array<string,string>} */
    private function discovery(string $projectRoot): array
    {
        if (\app\common\infrastructure\installation\ReadonlyHttpMount::active($this->serverRoot)) {
            return [$this->state(
                'not_checked',
                'MODULE_DEVELOPMENT_DISCOVERY_NOT_AVAILABLE',
                'The production HTTP mount contains only server source; strict development discovery requires the full application source tree.',
            ), []];
        }
        try {
            $roots = (new DevelopmentModuleDiscovery($projectRoot))->moduleRoots();
            return [$this->state('discovered', 'MODULE_DISCOVERY_READY', count($roots) . ' Modules passed strict discovery.'), $roots];
        } catch (Throwable $exception) {
            return [$this->failureState('MODULE_DISCOVERY_BLOCKED', $exception), []];
        }
    }

    /** @return array{0:array{status:string,code:string,reason:string},1:list<string>,2:?string} */
    private function registration(): array
    {
        try {
            $registry = $this->registry;
            return [
                $this->state('registered', 'MODULE_REGISTRY_READY', count($registry->modules) . ' locked Modules compiled.'),
                $registry->moduleKeys(),
                $registry->revision,
            ];
        } catch (Throwable $exception) {
            return [$this->failureState('MODULE_REGISTRY_BLOCKED', $exception), [], null];
        }
    }

    /** @return array{status:array{status:string,code:string,reason:string},endpoints:list<array<string,mixed>>} */
    private function apiCatalog(): array
    {
        $path = $this->serverRoot . '/generated/api-catalog.json';
        if (!is_file($path)) {
            return [
                'status' => $this->state('unavailable', 'API_CATALOG_UNAVAILABLE', 'Run the API catalog generator for this source revision.'),
                'endpoints' => [],
            ];
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
            $freshness = $this->apiCatalogFreshness($document['inputs'] ?? null);
            if ($freshness !== null) {
                return [
                    'status' => $this->state('stale', 'API_CATALOG_STALE', $freshness),
                    'endpoints' => [],
                ];
            }
            $endpoints = is_array($document['endpoints'] ?? null) && array_is_list($document['endpoints'])
                ? $document['endpoints']
                : [];
            return [
                'status' => $this->state('available', 'API_CATALOG_AVAILABLE', count($endpoints) . ' generated operations loaded.'),
                'endpoints' => $endpoints,
            ];
        } catch (Throwable $exception) {
            return ['status' => $this->failureState('API_CATALOG_INVALID', $exception), 'endpoints' => []];
        }
    }

    private function apiCatalogFreshness(mixed $inputs): ?string
    {
        if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []) {
            return 'The generated catalog does not register its source hashes. Regenerate it before using its routes.';
        }
        $projectRoot = dirname($this->serverRoot);
        $previous = null;
        foreach ($inputs as $input) {
            if (!is_array($input) || array_keys($input) !== ['path', 'sha256']) {
                return 'The generated catalog input registry is invalid. Regenerate it.';
            }
            $relative = $input['path'] ?? null;
            $expected = $input['sha256'] ?? null;
            if (!is_string($relative)
                || ($relative !== 'release-versions.json' && !str_starts_with($relative, 'server/'))
                || str_starts_with($relative, '/') || str_contains($relative, '\\')
                || array_intersect(explode('/', $relative), ['', '.', '..']) !== []
                || preg_match('/[\x00-\x1f]/', $relative) === 1
                || !is_string($expected) || preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1
                || ($previous !== null && strcmp($previous, $relative) >= 0)) {
                return 'The generated catalog input registry is invalid or not canonically sorted. Regenerate it.';
            }
            $sourceRelative = $relative;
            if ($relative === 'release-versions.json'
                && (file_exists($this->serverRoot . '/.peanut/release-identity.json')
                    || is_link($this->serverRoot . '/.peanut/release-identity.json'))) {
                // Server releases carry the exact APP version-source bytes inside their canonical inventory.
                $sourceRelative = 'server/.peanut/release-versions.json';
            }
            $cursor = $projectRoot;
            foreach (explode('/', $sourceRelative) as $part) {
                $cursor .= '/' . $part;
                if (is_link($cursor)) {
                    return 'A registered API source is linked: ' . $relative . '. Regenerate the catalog.';
                }
            }
            $source = $projectRoot . '/' . $sourceRelative;
            $actual = is_file($source) && !is_link($source) ? hash_file('sha256', $source) : false;
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                return 'A registered API source changed or disappeared: ' . $relative . '. Regenerate the catalog.';
            }
            $previous = $relative;
        }
        return null;
    }

    /** @return array<string,string> */
    private function moduleCommands(): array
    {
        $config = require $this->serverRoot . '/config/console.php';
        return is_array($config['module_commands'] ?? null) ? $config['module_commands'] : [];
    }

    /** @param list<array<string,mixed>> $routes @return list<array<string,mixed>> */
    private function moduleRoutes(array $routes, string $moduleKey): array
    {
        $aliases = [$moduleKey, str_replace('_', '-', $moduleKey)];
        return array_values(array_filter($routes, static function (array $route) use ($aliases): bool {
            $owner = $route['owner'] ?? null;
            if (is_array($owner)) {
                return ($owner['type'] ?? null) === 'module' && in_array($owner['key'] ?? null, $aliases, true);
            }
            return is_string($owner) && in_array(preg_replace('/^module:/', '', $owner), $aliases, true);
        }));
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $declarations @param list<string> $registeredKeys */
    private function dependencies(array $data, array $declarations, array $registeredKeys): array
    {
        $dependencies = [];
        foreach ((array) ($data['dependencies'] ?? []) as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            $key = is_string($dependency['module_key'] ?? null) ? $dependency['module_key'] : '';
            $dependencies[] = [
                'module_key' => $key,
                'version' => $dependency['version'] ?? null,
                'declared_target_found' => isset($declarations[$key]),
                'registered_target_found' => in_array($key, $registeredKeys, true),
            ];
        }
        return $dependencies;
    }

    /** @param array<string,array{data:array<string,mixed>}> $declarations @param list<string> $registeredKeys */
    private function dependants(string $moduleKey, array $declarations, array $registeredKeys): array
    {
        $dependants = [];
        foreach ($declarations as $key => $declaration) {
            foreach ((array) ($declaration['data']['dependencies'] ?? []) as $dependency) {
                if (!is_array($dependency) || ($dependency['module_key'] ?? null) !== $moduleKey) {
                    continue;
                }
                $dependants[] = [
                    'module_key' => $key,
                    'version' => $dependency['version'] ?? null,
                    'registered' => in_array($key, $registeredKeys, true),
                ];
            }
        }
        usort($dependants, static fn(array $left, array $right): int => $left['module_key'] <=> $right['module_key']);
        return $dependants;
    }

    /** @param array<string,mixed> $data */
    private function permissions(array $data): array
    {
        $items = array_values((array) ($data['catalog']['permissions'] ?? []));
        return array_map(static fn(mixed $item): mixed => is_array($item) ? $item : ['key' => $item], $items);
    }

    /** @param array<string,mixed> $data */
    private function publicServices(array $data): array
    {
        $provider = $data['backend']['provider'] ?? null;
        $bindings = [];
        if (is_string($provider) && $this->autoloadable($provider) && is_a($provider, ModuleProvider::class, true)) {
            try {
                $bindings = (new $provider())->bindings();
            } catch (Throwable) {
                $bindings = [];
            }
        }
        $services = [];
        foreach ((array) ($data['contracts']['exports'] ?? []) as $class) {
            if (!is_string($class)) {
                continue;
            }
            $services[] = [
                'class' => $class,
                'autoloadable' => $this->autoloadable($class),
                'provider_binding_declared' => array_key_exists($class, $bindings),
                'runtime_effective' => 'not_checked',
            ];
        }
        return $services;
    }

    /** @param array<string,mixed> $data */
    private function provider(array $data): array
    {
        $class = $data['backend']['provider'] ?? null;
        $autoloadable = is_string($class) && $this->autoloadable($class);
        $bindings = [];
        if ($autoloadable && is_a($class, ModuleProvider::class, true)) {
            try {
                foreach ((new $class())->bindings() as $contract => $target) {
                    $bindings[] = [
                        'contract' => $contract,
                        'target' => $target instanceof \Closure ? 'Closure' : $target,
                        'runtime_effective' => 'not_checked',
                    ];
                }
            } catch (Throwable) {
                $bindings = [];
            }
        }
        return [
            'class' => is_string($class) ? $class : null,
            'autoloadable' => $autoloadable,
            'implements_module_provider' => $autoloadable && is_a($class, ModuleProvider::class, true),
            'bindings' => $bindings,
            'runtime_effective' => 'not_checked',
        ];
    }

    /** @param list<array<string,mixed>> $routes */
    private function middleware(array $routes): array
    {
        $middleware = [];
        foreach ($routes as $route) {
            foreach ((array) ($route['middleware'] ?? []) as $entry) {
                if (!is_array($entry) || !is_string($entry['class'] ?? null)) {
                    continue;
                }
                $key = $entry['class'] . ':' . hash('sha256', json_encode($entry['arguments'] ?? [], JSON_UNESCAPED_SLASHES));
                $middleware[$key] = $entry;
            }
        }
        ksort($middleware, SORT_STRING);
        return array_values($middleware);
    }

    /** @param array<string,mixed> $data */
    private function lifecycle(array $data): array
    {
        return [
            'package_installation' => 'separate',
            'tenant_enablement' => (bool) ($data['tenant']['enableable'] ?? false),
            'person_authorization' => 'separate',
            'disable_behavior' => $data['tenant']['disable_behavior'] ?? null,
            'protected' => (bool) ($data['lifecycle']['protected'] ?? false),
            'upgrade' => 'separate_verified_package_operation',
            'uninstall' => 'separate_retire_operation',
            'data_deletion' => 'separate_double_confirmed_purge',
        ];
    }

    /** @param array<string,mixed> $data @param array<string,string> $commands */
    private function tasks(string $moduleKey, array $data, array $commands): array
    {
        $ownedCommands = [];
        foreach ($commands as $command => $owner) {
            if ($owner === $moduleKey) {
                $ownedCommands[] = $command;
            }
        }
        sort($ownedCommands, SORT_STRING);
        $contracts = array_values(array_filter(
            (array) ($data['contracts']['exports'] ?? []),
            static fn(mixed $class): bool => is_string($class) && preg_match('/(?:Task|Job|Worker|Scheduler)/i', $class) === 1,
        ));
        return ['commands' => $ownedCommands, 'public_contracts' => $contracts];
    }

    private function migrations(): array
    {
        $files = glob($this->serverRoot . '/database/migrations/*.php') ?: [];
        sort($files, SORT_STRING);
        return [
            'declared_path' => 'server/database/migrations',
            'files' => array_map(fn(string $path): array => [
                'path' => $this->relative($path),
                'sha256' => hash_file('sha256', $path),
            ], $files),
        ];
    }

    private function documentation(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink() && preg_match('/\.md$/i', $entry->getFilename()) === 1) {
                $files[] = $this->relative($entry->getPathname());
            }
        }
        sort($files, SORT_STRING);
        return ['status' => $files === [] ? 'missing' : 'available', 'files' => $files];
    }

    private function packagePreview(string $moduleKey): array
    {
        try {
            return ['status' => 'ready'] + (new PluginPackageArchiveService($this->serverRoot))->previewModule($moduleKey);
        } catch (Throwable $exception) {
            return ['status' => 'blocked'] + $this->exception($exception, 'MODULE_PACKAGE_PREVIEW_BLOCKED');
        }
    }

    private function selectedKey(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Module key is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $data @return list<array{client_key:string,entry:string,root:string}> */
    private function frontendContributions(array $data, string $moduleKey): array
    {
        try {
            return ModuleFrontendLayout::contributions(
                is_array($data['frontend'] ?? null) ? $data['frontend'] : [],
                $moduleKey,
            );
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    private function autoloadable(string $class): bool
    {
        try {
            return class_exists($class) || interface_exists($class) || enum_exists($class);
        } catch (Throwable) {
            return false;
        }
    }

    private function relative(string $path): string
    {
        $projectRoot = rtrim(dirname($this->serverRoot), '/') . '/';
        return str_starts_with($path, $projectRoot) ? substr($path, strlen($projectRoot)) : $path;
    }

    /** @return array{status:string,code:string,reason:string} */
    private function state(string $status, string $code, string $reason): array
    {
        return ['status' => $status, 'code' => $code, 'reason' => $reason];
    }

    /** @return array{status:string,code:string,reason:string} */
    private function failureState(string $fallback, Throwable $exception): array
    {
        $details = $this->exception($exception, $fallback);
        return $this->state('blocked', $details['code'], $details['reason']);
    }

    /** @return array{code:string,reason:string} */
    private function exception(Throwable $exception, string $fallback): array
    {
        $code = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : $fallback;
        return ['code' => $code, 'reason' => $exception->getMessage()];
    }
}
