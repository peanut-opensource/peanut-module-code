<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\common\value\module\ModulePhpNamespace;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\validation\module\OpisManifestSchemaValidator;
use app\platform\value\plugin\ModuleFrontendLayout;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\Kernel\Module\ModuleProvider;

/** Discovers the development source tree without consulting plugins.lock. */
final readonly class DevelopmentModuleDiscovery
{
    public function __construct(private string $projectRoot) {}

    /** @return array<string,string> Module key => absolute backend root */
    public function moduleRoots(): array
    {
        $modulesRoot = $this->projectRoot . '/server/app/modules';
        if (!is_dir($modulesRoot)) {
            throw new PluginLifecycleException('MODULE_REGISTRY_UNAVAILABLE', 'Development Module source root is unavailable.');
        }
        $manifests = [];
        $phpNamespaces = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new PluginLifecycleException('MODULE_PATH_INVALID', 'Development Module source cannot contain symbolic links.');
            }
            if (!$entry->isFile() || $entry->getFilename() !== 'module.json') {
                continue;
            }
            try {
                $document = json_decode((string) file_get_contents($entry->getPathname()), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new PluginLifecycleException('MODULE_MANIFEST_INVALID', 'Development Module manifest is invalid.');
            }
            $keyValue = is_array($document) ? ($document['key'] ?? null) : null;
            try {
                $key = is_string($keyValue) ? ModuleKey::fromString($keyValue) : null;
            } catch (\InvalidArgumentException) {
                throw new PluginLifecycleException('MODULE_MANIFEST_INVALID', 'Development Module key is invalid.');
            }
            if (!$key instanceof ModuleKey) {
                throw new PluginLifecycleException('MODULE_MANIFEST_INVALID', 'Development Module key is missing.');
            }
            if (isset($manifests[$key->value()])) {
                throw new PluginLifecycleException('MODULE_REGISTRY_CONFLICT', 'Development Module key is duplicated.');
            }
            $layout = ModuleHostLayoutFactory::pathLayout();
            $expectedRoot = $this->projectRoot . '/' . rtrim($layout->backendRelativePath($key), '/');
            $actualRoot = realpath($entry->getPath());
            if ($actualRoot === false || $actualRoot !== realpath($expectedRoot)) {
                throw new PluginLifecycleException('MODULE_PATH_INVALID', 'Development Module backend path is not derived from its key.');
            }
            try {
                $manifest = (new ManifestLoader())->load($actualRoot);
                $kernelRoot = dirname((new \ReflectionClass(ModuleProvider::class))->getFileName(), 3);
                (new OpisManifestSchemaValidator($kernelRoot . '/resources/schemas/module-manifest.schema.json'))
                    ->assertValid($manifest->object);
            } catch (\Throwable) {
                throw new PluginLifecycleException('MODULE_MANIFEST_INVALID', 'Development Module manifest preflight failed.');
            }
            if (($manifest->data['key'] ?? null) !== $key->value()) {
                throw new PluginLifecycleException('MODULE_PATH_INVALID', 'Development Module key differs from its manifest path.');
            }
            try {
                $phpNamespace = ModulePhpNamespace::fromModuleRoot($actualRoot);
            } catch (\InvalidArgumentException $exception) {
                throw new PluginLifecycleException('MODULE_MANIFEST_INVALID', 'Development Module PHP namespace is invalid.', 0, $exception);
            }
            $namespaceKey = strtolower($phpNamespace);
            if (isset($phpNamespaces[$namespaceKey])) {
                throw new PluginLifecycleException(
                    'MODULE_REGISTRY_CONFLICT',
                    "Development Module PHP namespace is duplicated by {$phpNamespaces[$namespaceKey]} and {$key->value()}.",
                );
            }
            $phpNamespaces[$namespaceKey] = $key->value();
            $frontend = is_array($manifest->data['frontend'] ?? null) ? $manifest->data['frontend'] : [];
            try {
                $contributions = ModuleFrontendLayout::contributions($frontend, $key->value());
            } catch (\InvalidArgumentException $exception) {
                throw new PluginLifecycleException('MODULE_PACKAGE_FRONTEND_ENTRY_MISMATCH', $exception->getMessage(), 0, $exception);
            }
            foreach ($contributions as $contribution) {
                $entryPath = $this->projectRoot . '/' . $contribution['entry'];
                $rootPath = $this->projectRoot . '/' . $contribution['root'];
                if (!is_dir($rootPath) || is_link($rootPath)
                    || !is_file($entryPath) || is_link($entryPath)
                    || !is_file($rootPath . '/package.json') || is_link($rootPath . '/package.json')) {
                    throw new PluginLifecycleException(
                        'MODULE_PACKAGE_FRONTEND_ENTRY_MISSING',
                        'Development Module frontend contribution is unavailable: ' . $contribution['client_key'],
                    );
                }
            }
            $manifests[$key->value()] = $actualRoot;
        }
        if ($manifests === []) {
            throw new PluginLifecycleException('MODULE_REGISTRY_UNAVAILABLE', 'No development Module manifest was discovered.');
        }
        try {
            ModuleHostLayoutFactory::fromModuleRoots($manifests);
        } catch (\InvalidArgumentException $exception) {
            throw new PluginLifecycleException(
                'MODULE_REGISTRY_CONFLICT',
                'Development Module PHP namespaces overlap or claim a reserved prefix.',
                0,
                $exception,
            );
        }
        ksort($manifests, SORT_STRING);
        return $manifests;
    }
}
