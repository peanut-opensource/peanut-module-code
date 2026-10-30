<?php

declare(strict_types=1);

namespace app\platform\composition\plugin;

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\validation\module\OpisManifestSchemaValidator;
use PeanutAdmin\Kernel\Idempotency\IdempotencySchema;
use PeanutAdmin\Kernel\Migration\ModuleSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleBoundaryChecker;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use think\cache\driver\File;

/** Compiles immutable deployment metadata without opening a database connection. */
final readonly class ModuleDefinitionRegistryFactory
{
    public function __construct(private string $serverRoot) {}

    /** HTTP restores pure declarations; it never scans or compiles application source. */
    public function fromCompiled(array $deploymentConfig): CompiledModuleRegistry
    {
        $registry = $this->compiledRecord($deploymentConfig)['registry'];
        $roots = [];
        foreach ($registry->modules as $manifest) {
            $roots[$manifest->data['key']] = $manifest->root;
        }
        ModuleHostLayoutFactory::registerRuntimeAutoload($roots, $this->serverRoot);
        return $registry;
    }

    /** @return array<string,\app\platform\value\plugin\PluginDescriptor> */
    public function pluginsFromCompiled(array $deploymentConfig): array
    {
        return $this->compiledRecord($deploymentConfig)['plugins'];
    }

    public function publishCompiled(CompiledModuleRegistry $registry, array $deploymentConfig, array $plugins): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new ModuleException('MODULE_REGISTRY_COMPILE_CLI_REQUIRED', 'Module compilation requires CLI.');
        }
        $key = $this->runtimeKey($deploymentConfig);
        $lock = (string) $deploymentConfig['plugin_lock'];
        $digest = hash_file('sha256', str_starts_with($lock, '/') ? $lock : $this->serverRoot . '/' . $lock);
        foreach ($plugins as $plugin) {
            if (!$plugin instanceof \app\platform\value\plugin\PluginDescriptor || $plugin->lockDigest !== $digest) {
                throw new ModuleException('MODULE_REGISTRY_COMPILE_FAILED', 'Plugin lock changed during compilation.');
            }
        }
        if (!$this->runtimeStore()->set($key, ['registry' => $registry, 'plugins' => $plugins], 0)
            || $key !== $this->runtimeKey($deploymentConfig)) {
            throw new ModuleException('MODULE_REGISTRY_COMPILE_FAILED', 'Unable to write the compiled Module registry.');
        }
    }

    private function compiledRecord(array $deploymentConfig): array
    {
        $journal = dirname($this->serverRoot) . '/.local/module-source-adoption/journal.json';
        if (file_exists($journal) || is_link($journal)) {
            throw new ModuleException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Complete source adoption before serving application source.');
        }
        $record = $this->runtimeStore()->get($this->runtimeKey($deploymentConfig));
        if (!is_array($record) || !($record['registry'] ?? null) instanceof CompiledModuleRegistry
            || !is_array($record['plugins'] ?? null)) {
            throw new ModuleException('MODULE_REGISTRY_NOT_COMPILED', 'Run peanut module compile before serving application source.');
        }
        return $record;
    }

    private function runtimeStore(): File
    {
        return new File(\think\Container::getInstance(), ['path' => $this->serverRoot . '/runtime/module-registry',
            'prefix' => '', 'cache_subdir' => false, 'expire' => 0]);
    }

    /** Small version inputs only; no recursive source checks on the request path. */
    private function runtimeKey(array $deploymentConfig): string
    {
        $lock = (string) ($deploymentConfig['plugin_lock'] ?? '');
        $lockPath = str_starts_with($lock, '/') ? $lock : $this->serverRoot . '/' . $lock;
        $inputs = [];
        foreach ([$lockPath, $this->serverRoot . '/composer.lock',
            $this->serverRoot . '/vendor/composer/installed.json', __FILE__] as $path) {
            $digest = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($digest)) {
                throw new ModuleException('MODULE_REGISTRY_NOT_COMPILED', 'Module compilation inputs are unavailable.');
            }
            $inputs[] = $digest;
        }
        return hash('sha256', json_encode([$this->serverRoot, $deploymentConfig, $inputs], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $deploymentConfig */
    public function fromDeploymentConfig(array $deploymentConfig): CompiledModuleRegistry
    {
        $roots = is_array($deploymentConfig['roots'] ?? null)
            ? array_values($deploymentConfig['roots'])
            : [];
        return $this->compile($this->resolveRoots($roots, true), $deploymentConfig);
    }

    /** @param array<string,mixed> $deploymentConfig */
    public function fromPluginLock(
        PluginLockResolver $resolver,
        array $deploymentConfig,
        bool $includeDeploymentRoots = true,
    ): CompiledModuleRegistry {
        $configuredRoots = $includeDeploymentRoots && is_array($deploymentConfig['roots'] ?? null)
            ? array_values($deploymentConfig['roots'])
            : [];
        $roots = [
            ...$this->resolveRoots($configuredRoots, false),
            ...$resolver->moduleRoots(),
        ];
        return $this->compile(array_values(array_unique($roots)), $deploymentConfig);
    }

    /**
     * @param list<mixed> $roots
     * @return list<string>
     */
    private function resolveRoots(array $roots, bool $required): array
    {
        if (!array_is_list($roots) || ($required && $roots === [])) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'No deployed Module roots are configured.');
        }
        $resolvedRoots = [];
        foreach ($roots as $root) {
            if (!is_string($root) || trim($root) === '') {
                throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'A deployed Module root is invalid.');
            }
            $candidate = str_starts_with($root, DIRECTORY_SEPARATOR)
                ? $root
                : $this->serverRoot . '/' . ltrim($root, '/');
            $resolved = realpath($candidate);
            if ($resolved === false || !is_dir($resolved)) {
                throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'A deployed Module root is unavailable.');
            }
            $resolvedRoots[] = $resolved;
        }
        return $resolvedRoots;
    }

    /** @param list<string> $roots @param array<string,mixed> $deploymentConfig */
    private function compile(array $roots, array $deploymentConfig): CompiledModuleRegistry
    {
        if (PHP_SAPI !== 'cli') {
            throw new ModuleException('MODULE_REGISTRY_COMPILE_CLI_REQUIRED', 'HTTP cannot compile Module declarations.');
        }
        if ($roots === []) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'No locked Module roots are available.');
        }
        $kernelVersion = trim((string) ($deploymentConfig['kernel_version'] ?? ''));
        $clients = is_array($deploymentConfig['registered_client_keys'] ?? null)
            ? array_values($deploymentConfig['registered_client_keys'])
            : [];
        if ($kernelVersion === '' || $clients === [] || !array_is_list($clients)) {
            throw new ModuleException('MODULE_REGISTRY_UNAVAILABLE', 'Module deployment metadata is invalid.');
        }

        $loader = new ManifestLoader();
        $documents = array_map(
            static fn(string $root) => $loader->load($root),
            $roots,
        );
        $moduleRoots = [];
        foreach ($documents as $document) {
            \app\common\module\PackageCheck::assertEdition($document->root, (string) \think\facade\Config::get('deployment.mode', ''));
            $moduleKey = $document->data['key'] ?? null;
            if (!is_string($moduleKey) || isset($moduleRoots[$moduleKey])) {
                throw new ModuleException('MODULE_REGISTRY_CONFLICT', 'Module namespace owner is missing or duplicated.');
            }
            $moduleRoots[$moduleKey] = $document->root;
        }
        try {
            $layout = ModuleHostLayoutFactory::registerRuntimeAutoload($moduleRoots, $this->serverRoot);
        } catch (\InvalidArgumentException $exception) {
            throw new ModuleException('MODULE_REGISTRY_CONFLICT', $exception->getMessage(), 0, $exception);
        }

        $kernelRoot = dirname((new \ReflectionClass(ModuleProvider::class))->getFileName(), 3);
        $reservedTableOwners = $this->historicalBusinessTableOwners();
        $compiler = new ModuleRegistryCompiler(
            new OpisManifestSchemaValidator($kernelRoot . '/resources/schemas/module-manifest.schema.json'),
            $kernelVersion,
            $this->frontendComponents($documents),
            $layout,
            [
                ...array_diff(ModuleSchema::tableNames(), ['pa_module_migration']),
                ...IdempotencySchema::tableNames(),
                ...array_keys($reservedTableOwners),
            ],
            $clients,
            [
                ...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::TENANT,
                ...\PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog::PLATFORM,
            ],
            $reservedTableOwners,
        );
        $registry = $compiler->compile($documents);
        (new ModuleBoundaryChecker($registry, $layout, ['pa_']))->check();
        return $registry;
    }

    /**
     * The protected, shipped IAM manifest is the ownership source. Arbitrary modules
     * cannot opt themselves into the historical table exception by declaring a table.
     * Core may still provide bootstrap DDL for tables whose runtime/business ownership
     * has moved to a protected shipped Module. Keep that transfer explicit and narrow:
     * Active technical tables remain Core-owned/reserved.
     * @return array<string,string>
     */
    private function historicalBusinessTableOwners(): array
    {
        $technical = [...array_diff(ModuleSchema::tableNames(), ['pa_module_migration']), ...IdempotencySchema::tableNames()];
        $transferredTechnicalOwners = [
            'peanut.identity' => ['pa_menu_definition', 'pa_module_installation'],
        ];
        $owners = [];
        foreach (['peanut.identity' => 'identity', 'peanut.ops' => 'ops'] as $key => $directory) {
            $path = $this->serverRoot . '/app/modules/peanut/' . $directory . '/module.json';
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['key'] ?? null) !== $key
                || ($manifest['lifecycle']['protected'] ?? false) !== true) {
                throw new ModuleException('MODULE_MANIFEST_INVALID', "The protected {$key} manifest is unavailable.");
            }
            foreach (($manifest['database']['owned_tables'] ?? []) as $table) {
                $isTransferredTechnicalTable = is_string($table)
                    && in_array($table, $transferredTechnicalOwners[$key] ?? [], true);
                if (!is_string($table)
                    || (in_array($table, $technical, true) && !$isTransferredTechnicalTable)
                    || isset($owners[$table])) {
                    throw new ModuleException('MODULE_REGISTRY_CONFLICT', "{$key} historical table ownership is invalid.");
                }
                $owners[$table] = $key;
            }
        }
        return $owners;
    }

    /** @param list<\PeanutAdmin\Kernel\Module\ManifestDocument> $documents @return list<string> */
    private function frontendComponents(array $documents): array
    {
        $components = [];
        foreach ($documents as $manifest) {
            $catalog = is_array($manifest->data['catalog'] ?? null) ? $manifest->data['catalog'] : [];
            foreach ((array) ($catalog['menus'] ?? []) as $menu) {
                if (!is_array($menu) || ($menu['type'] ?? null) !== 'page') {
                    continue;
                }
                $component = $menu['component_key'] ?? null;
                if (is_string($component) && $component !== '') {
                    $components[] = $component;
                }
            }
        }
        $components = array_values(array_unique($components));
        sort($components, SORT_STRING);
        return $components;
    }
}
