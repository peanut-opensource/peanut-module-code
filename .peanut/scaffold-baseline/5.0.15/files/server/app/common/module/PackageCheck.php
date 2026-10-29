<?php

declare(strict_types=1);

namespace app\common\module;

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\exception\plugin\PluginPackageException;
use app\platform\validation\module\OpisManifestSchemaValidator;
use app\platform\validation\module\StrictVersionConstraintMatcher;
use app\platform\value\plugin\ModuleFrontendLayout;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ManifestSchemaValidator;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleKey;

/** Validates package Module identity and all values derived from module.json. */
final readonly class PackageCheck
{
    private ModuleHostLayout $layout;
    private ManifestSchemaValidator $manifestValidator;

    public function __construct(private string $projectRoot, ?ManifestSchemaValidator $manifestValidator = null)
    {
        $this->layout = ModuleHostLayoutFactory::pathLayout();
        $this->manifestValidator = $manifestValidator ?? new OpisManifestSchemaValidator($this->moduleSchema());
    }

    /**
     * @return array{
     *   key:string,version:string,editions:list<string>,backend_relative:string,frontend_relative:?string,
     *   frontend_contributions:list<array{client_key:string,entry:string,root:string}>,
     *   manifest:ManifestDocument,dependencies:list<array{module_key:string,version:string}>,owned_tables:list<string>
     * }
     */
    public function inspect(string $moduleKey): array
    {
        try {
            $key = ModuleKey::fromString($moduleKey);
        } catch (\InvalidArgumentException $exception) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Module key is invalid.', 0, $exception);
        }
        $backendRelative = rtrim($this->layout->backendRelativePath($key), '/');
        $backendRoot = $this->projectRoot . '/' . $backendRelative;
        $editions = self::editions($backendRoot);
        try {
            $manifest = (new ManifestLoader())->load($backendRoot);
            $this->manifestValidator->assertValid($manifest->object);
        } catch (\Throwable $exception) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Module manifest validation failed.', 0, $exception);
        }
        if (($manifest->data['key'] ?? null) !== $moduleKey) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module key does not match its derived backend path.');
        }
        $backend = is_array($manifest->data['backend'] ?? null) ? $manifest->data['backend'] : null;
        $canonicalBackend = [
            'migrations' => ['database/migrations', 'directory'],
            'seeds' => ['database/seeds', 'directory'],
            'menus' => ['resources/menus.json', 'file'],
            'permissions' => ['resources/permissions.json', 'file'],
            'protected_resources' => ['resources/protected-resources.json', 'file'],
            'target_types' => ['resources/target-types.json', 'file'],
            'data_conditions' => ['resources/data-conditions.json', 'file'],
            'setting_definitions' => ['resources/setting-definitions.json', 'file'],
            'reference_code_sets' => ['resources/reference-code-sets.json', 'file'],
            'system_actors' => ['resources/system-actors.json', 'file'],
        ];
        if ($backend === null || array_key_exists('routes', $backend)) {
            throw new PluginPackageException('MODULE_PACKAGE_MANIFEST_INVALID', 'Module backend.routes is prohibited.');
        }
        foreach ($canonicalBackend as $field => [$path, $kind]) {
            if (!array_key_exists($field, $backend)) {
                continue;
            }
            if ($backend[$field] !== $path) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', "Module backend.{$field} is not canonical.");
            }
            $absolute = $backendRoot . '/' . $path;
            if (($kind === 'directory' && !is_dir($absolute)) || ($kind === 'file' && !is_file($absolute))) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISSING', "Module backend.{$field} source is unavailable.");
            }
        }
        if (array_key_exists('config_schema', $backend)) {
            $configRelative = $backend['config_schema'];
            if (!is_string($configRelative) || $configRelative === '' || str_contains($configRelative, "\0")
                || str_contains($configRelative, '\\') || str_starts_with($configRelative, '/')
                || preg_match('/^[A-Za-z]:/', $configRelative) === 1) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module backend.config_schema path is invalid.');
            }
            $configSegments = explode('/', $configRelative);
            if (array_intersect($configSegments, ['', '.', '..']) !== []) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module backend.config_schema path is invalid.');
            }
            $configProjectRoot = realpath($this->projectRoot);
            if ($configProjectRoot === false) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module backend.config_schema root is unavailable.');
            }
            $configModuleRoot = rtrim($configProjectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $backendRelative);
            $configCursor = rtrim($configProjectRoot, DIRECTORY_SEPARATOR);
            foreach ([...explode('/', $backendRelative), ...$configSegments] as $segment) {
                $configCursor .= DIRECTORY_SEPARATOR . $segment;
                if (is_link($configCursor)) {
                    throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module backend.config_schema path contains a link.');
                }
            }
            $configReal = realpath($configCursor);
            if ($configReal === false || !is_file($configReal)) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISSING', 'Module backend.config_schema source is unavailable.');
            }
            $configStat = lstat($configReal);
            if (realpath($configModuleRoot) !== $configModuleRoot || $configReal !== $configCursor
                || !str_starts_with($configReal, $configModuleRoot . DIRECTORY_SEPARATOR)
                || !is_array($configStat) || ($configStat['nlink'] ?? 0) !== 1) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module backend.config_schema is outside its ordinary Module files.');
            }
        }
        try {
            $moduleLayout = ModuleHostLayoutFactory::fromModuleRoots([$moduleKey => $backendRoot]);
            $phpNamespace = $moduleLayout->backendNamespace($key);
        } catch (\InvalidArgumentException $exception) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module PHP namespace declaration is not canonical.', 0, $exception);
        }
        $provider = $backend['provider'] ?? null;
        if (!is_string($provider) || $provider !== $phpNamespace . 'ModuleProvider') {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module provider namespace is not canonical.');
        }
        $route = $backendRoot . '/route/app.php';
        if ((file_exists($route) || is_link($route)) && (!is_file($route) || is_link($route))) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_MISMATCH', 'Module route contribution is invalid.');
        }

        $frontend = is_array($manifest->data['frontend'] ?? null) ? $manifest->data['frontend'] : [];
        try {
            $frontendContributions = ModuleFrontendLayout::contributions($frontend, $moduleKey);
        } catch (\InvalidArgumentException $exception) {
            throw new PluginPackageException('MODULE_PACKAGE_FRONTEND_ENTRY_MISMATCH', $exception->getMessage(), 0, $exception);
        }
        foreach ($frontendContributions as $contribution) {
            $absoluteRoot = $this->projectRoot . '/' . $contribution['root'];
            $absoluteEntry = $this->projectRoot . '/' . $contribution['entry'];
            $package = $absoluteRoot . '/package.json';
            if (!is_dir($absoluteRoot) || is_link($absoluteRoot)
                || !is_file($absoluteEntry) || is_link($absoluteEntry)
                || !is_file($package) || is_link($package)) {
                throw new PluginPackageException(
                    'MODULE_PACKAGE_FRONTEND_ENTRY_MISSING',
                    'Module frontend contribution is incomplete: ' . $contribution['client_key'],
                );
            }
        }
        $frontendRelative = null;
        foreach ($frontendContributions as $contribution) {
            if ($contribution['client_key'] === 'admin-web') {
                $frontendRelative = $contribution['root'];
            }
        }

        $catalog = is_array($manifest->data['catalog'] ?? null) ? $manifest->data['catalog'] : [];
        $permissions = [];
        foreach ($catalog['permissions'] ?? [] as $permission) {
            $permissionKey = is_array($permission) ? ($permission['key'] ?? null) : null;
            if (!is_string($permissionKey) || !str_starts_with($permissionKey, $moduleKey . '.')) {
                throw new PluginPackageException(
                    'MODULE_PACKAGE_PERMISSION_NOT_NAMESPACED',
                    'Every Module permission key must use its Module key namespace.',
                );
            }
            $permissions[$permissionKey] = true;
        }
        foreach ($catalog['menus'] ?? [] as $menu) {
            $required = is_array($menu) ? ($menu['required_permission'] ?? null) : null;
            if ($required !== null && (!is_string($required) || !isset($permissions[$required]))) {
                throw new PluginPackageException(
                    'MODULE_PACKAGE_PERMISSION_REFERENCE_INVALID',
                    'Module menu permission must reference a declared namespaced permission.',
                );
            }
        }

        $dependencies = [];
        foreach ($manifest->data['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency) || !is_string($dependency['module_key'] ?? null)
                || !is_string($dependency['version'] ?? null)) {
                throw new PluginPackageException('MODULE_PACKAGE_DEPENDENCY_INVALID', 'Module dependency is invalid.');
            }
            $dependencies[] = [
                'module_key' => $dependency['module_key'],
                'version' => $dependency['version'],
            ];
        }

        return [
            'key' => $moduleKey,
            'version' => (string) $manifest->data['version'],
            'editions' => $editions,
            'backend_relative' => $backendRelative,
            'frontend_relative' => $frontendRelative,
            'frontend_contributions' => $frontendContributions,
            'manifest' => $manifest,
            'dependencies' => $dependencies,
            'owned_tables' => array_values((array) ($manifest->data['database']['owned_tables'] ?? [])),
        ];
    }

    /** Product Edition is native Composer metadata; the generic Core manifest schema stays reusable. @return list<string> */
    public static function editions(string $moduleRoot): array
    {
        $composer = json_decode((string) file_get_contents($moduleRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $editions = $composer['extra']['peanut']['editions'] ?? null;
        if (!is_array($editions) || !array_is_list($editions) || $editions === []
            || array_filter($editions, static fn(mixed $edition): bool => !in_array($edition, ['standalone', 'multi-tenant'], true)) !== []
            || count(array_unique($editions)) !== count($editions)) {
            throw new PluginPackageException('MODULE_EDITION_DECLARATION_REQUIRED', 'Declare supported Editions in composer.json extra.peanut.editions.');
        }
        return $editions;
    }

    public static function assertEdition(string $moduleRoot, string $edition): void
    {
        if (!in_array($edition, self::editions($moduleRoot), true)) {
            throw new PluginPackageException('MODULE_EDITION_UNSUPPORTED', 'The Module does not support the selected application Edition.');
        }
    }

    /**
     * @param array<string,array{version:string,dependencies:list<array{module_key:string,version:string}>}> $modules
     * @param array<string,string> $availableVersions
     * @return list<string>
     */
    public function dependencyOrder(array $modules, array $availableVersions): array
    {
        $matcher = new StrictVersionConstraintMatcher();
        $visiting = [];
        $visited = [];
        $ordered = [];
        $visit = function (string $key) use (&$visit, &$visiting, &$visited, &$ordered, $modules, $availableVersions, $matcher): void {
            if (isset($visited[$key])) {
                return;
            }
            if (isset($visiting[$key])) {
                throw new PluginPackageException('MODULE_PACKAGE_DEPENDENCY_CYCLE', 'Module package dependency cycle detected.');
            }
            $visiting[$key] = true;
            foreach ($modules[$key]['dependencies'] as $dependency) {
                $dependencyKey = $dependency['module_key'];
                $version = $modules[$dependencyKey]['version'] ?? $availableVersions[$dependencyKey] ?? null;
                if (!is_string($version)) {
                    throw new PluginPackageException('MODULE_PACKAGE_DEPENDENCY_MISSING', 'Module package dependency is missing.');
                }
                if (!$matcher->matches($version, $dependency['version'])) {
                    throw new PluginPackageException('MODULE_PACKAGE_DEPENDENCY_INCOMPATIBLE', 'Module package dependency version is incompatible.');
                }
                if (isset($modules[$dependencyKey])) {
                    $visit($dependencyKey);
                }
            }
            unset($visiting[$key]);
            $visited[$key] = true;
            $ordered[] = $key;
        };
        $keys = array_keys($modules);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $visit($key);
        }
        return $ordered;
    }

    private function moduleSchema(): string
    {
        $kernelRoot = dirname((new \ReflectionClass(\PeanutAdmin\Kernel\Module\ModuleProvider::class))->getFileName(), 3);
        return $kernelRoot . '/resources/schemas/module-manifest.schema.json';
    }
}
