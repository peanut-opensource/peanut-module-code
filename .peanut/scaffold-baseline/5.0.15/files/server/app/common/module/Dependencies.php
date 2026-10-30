<?php

declare(strict_types=1);

namespace app\common\module;

use app\platform\exception\plugin\PluginPackageException;
use app\common\infrastructure\module\ModuleHostLayoutFactory;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\value\plugin\ModuleFrontendLayout;
use app\platform\value\plugin\PluginDescriptor;
use Composer\Semver\Semver;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PeanutAdmin\Kernel\Module\ModuleKey;

/** Projects module requirements into the application's native manifests; native tools own resolution and locks. */
final readonly class Dependencies
{
    public function __construct(private string $serverRoot) {}

    /** @param array<string,array{version:string,status:string}> $installed @return array<string,mixed> */
    public function install(PluginDescriptor $plugin, PluginLockResolver $resolver, array $installed): array
    {
        if (PHP_SAPI !== 'cli') {
            return $this->prepare($plugin->moduleRoots, $resolver, $installed);
        }
        return $this->withLock(fn(): array => $this->prepare($plugin->moduleRoots, $resolver, $installed));
    }

    /** Verify release-machine output without changing native manifests or invoking build tools. */
    public function verifyInstalled(PluginDescriptor $plugin, PluginLockResolver $resolver, array $installed): array
    {
        return $this->prepare($plugin->moduleRoots, $resolver, $installed, verifyOnly: true);
    }

    /** Prepare admitted real Module nodes once; a native consumer may reuse that result under the same lock.
     * @param null|list<string> $selectedModuleKeys
     * @param null|callable(array<string,mixed>):array<string,mixed> $consume
     */
    public function prepareRelease(PluginLockResolver $resolver, array $clients, ?array $selectedModuleKeys = null,
        array $installed = [], ?callable $consume = null): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new PluginPackageException('MODULE_RELEASE_PREPARATION_REQUIRED', 'Release preparation requires the deployment CLI.');
        }
        $roots = [];
        foreach ($resolver->all() as $plugin) {
            $roots += $plugin->moduleRoots;
        }
        if ($selectedModuleKeys !== null) {
            $selected = [];
            foreach ($selectedModuleKeys as $key) {
                $selected[$key] = $roots[$key] ?? throw new PluginPackageException('MODULE_APPROVED_SOURCE_REQUIRED', 'Selected Module has no admitted source.');
            }
            $roots = $selected;
        }
        return $this->withLock(function () use ($roots, $resolver, $installed, $clients, $consume): array {
            $prepared = $this->prepare($roots, $resolver, $installed, $clients);
            return $consume === null ? $prepared : $consume($prepared);
        });
    }

    /** Source has already entered the existing quarantine; shared workspace globs no longer select it.
     * @param array<string,string> $moduleRoots Module key => quarantined backend root.
     */
    public function remove(array $moduleRoots): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new PluginPackageException('MODULE_RELEASE_PREPARATION_REQUIRED', 'Module dependency removal requires the existing CLI worker.');
        }
        return $this->withLock(function () use ($moduleRoots): array {
            $project = dirname($this->serverRoot);
            $composerPath = $this->serverRoot . '/composer.json';
            $composer = $this->json($composerPath);
            $originalComposer = $composer;
            $names = [];
            $devNames = [];
            $repositories = [];
            $clients = [];
            foreach ($moduleRoots as $key => $root) {
                $package = $this->json($root . '/composer.json');
                $manifest = (new ManifestLoader())->load($root)->data;
                $name = $package['name'];
                $devName = 'peanut-module-dev/' . $key;
                $names[] = $name;
                $names[] = $devName;
                $devNames[] = $devName;
                unset($composer['require'][$name], $composer['require-dev'][$devName]);
                $relative = rtrim(ModuleHostLayoutFactory::pathLayout()->backendRelativePath(ModuleKey::fromString($key)), '/');
                $repositories[] = substr($relative, strlen('server/'));
                foreach (ModuleFrontendLayout::contributions($manifest['frontend'] ?? [], $key) as $contribution) {
                    $client = explode('/', $contribution['root'])[0];
                    $clients[$client][] = substr($contribution['root'], strlen($client) + 1);
                }
            }
            $existing = $composer['repositories'] ?? [];
            $retained = array_filter($existing, static fn(mixed $repository): bool => !is_array($repository)
                || (!(($repository['type'] ?? null) === 'path' && in_array($repository['url'] ?? null, $repositories, true))
                    && !(($repository['type'] ?? null) === 'package' && in_array($repository['package']['name'] ?? null, $devNames, true))));
            $composer['repositories'] = array_is_list($existing) ? array_values($retained) : $retained;
            if (($composer['require-dev'] ?? null) === []
                && array_intersect($devNames, array_keys($originalComposer['require-dev'] ?? [])) !== []) {
                unset($composer['require-dev']);
            }
            $composerChanged = $composer !== $originalComposer;
            if ($composerChanged) { $this->write($composerPath, $composer); }
            $lock = $this->json($this->serverRoot . '/composer.lock');
            $locked = array_column([...$lock['packages'], ...($lock['packages-dev'] ?? [])], 'name');
            $updates = array_values(array_intersect($names, $locked));
            $installedPath = $this->serverRoot . '/vendor/composer/installed.php';
            $needsInstall = !is_file($installedPath);
            if (!$needsInstall) {
                $native = require $installedPath;
                InstalledVersions::reload($native);
                foreach ($names as $name) {
                    if (InstalledVersions::isInstalled($name)) { $needsInstall = true; }
                }
                $expected = [...$lock['packages'], ...(($native['root']['dev'] ?? false) ? ($lock['packages-dev'] ?? []) : [])];
                foreach ($expected as $package) {
                    if (!InstalledVersions::isInstalled($package['name'])
                        || InstalledVersions::getPrettyVersion($package['name']) !== $package['version']) {
                        $needsInstall = true;
                        break;
                    }
                    $directory = InstalledVersions::getInstallPath($package['name']);
                    if ($directory !== null && !is_dir($directory)) { $needsInstall = true; break; }
                }
            }
            $operations = [];
            if ($composerChanged || $updates !== []) {
                $operations[] = $this->run(['composer', 'update', ...($updates === [] ? ['--lock'] : $updates),
                    '--with-all-dependencies', '--minimal-changes', '--no-interaction', '--no-progress', '--no-scripts'], $this->serverRoot);
                $this->refreshAutoload();
            } elseif ($needsInstall) {
                $operations[] = $this->run(['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts'], $this->serverRoot);
                $this->refreshAutoload();
            }
            foreach ($clients as $client => $modules) {
                $directory = $project . '/' . $client;
                if ($client === 'web') {
                    $path = $directory . '/pnpm-workspace.yaml';
                    $source = (string) file_get_contents($path);
                    foreach ($modules as $module) {
                        $source = preg_replace('/^[ \t]*-[ \t]*[\'\"]?' . preg_quote($module, '/') . '[\'\"]?[ \t]*(?:#[^\n]*)?\r?\n/m', '', $source);
                    }
                    if (file_put_contents($path, $source) === false) {
                        throw new PluginPackageException('MODULE_DEPENDENCY_MANIFEST_WRITE_FAILED', 'Cannot update native pnpm workspace configuration.');
                    }
                    $operations[] = $this->run(['pnpm', 'install', '--prod=false', '--no-frozen-lockfile', '--strict-peer-dependencies', '--ignore-scripts'], $directory);
                } else {
                    $path = $directory . '/package.json';
                    $package = $this->json($path);
                    $workspaces = $package['workspaces'] ?? [];
                    if (isset($workspaces['packages'])) {
                        $workspaces['packages'] = array_values(array_diff($workspaces['packages'], $modules));
                    } else {
                        $workspaces = array_values(array_diff($workspaces, $modules));
                    }
                    $package['workspaces'] = $workspaces;
                    $this->write($path, $package);
                    $operations[] = $this->run(['npm', 'install', '--include=dev', '--strict-peer-deps', '--ignore-scripts', '--no-audit', '--no-fund'], $directory);
                }
            }
            return ['status' => 'dependencies-removed', 'operations' => $operations,
                'frontend_adoption' => $this->adoptFrontend(array_keys($clients))];
        });
    }

    private function withLock(\Closure $operation): array
    {
        $project = dirname($this->serverRoot);
        $directory = $project . '/.local';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new PluginPackageException('MODULE_DEPENDENCIES_UNAVAILABLE', 'Application local directory is unavailable.');
        }
        $lock = fopen($directory . '/module-dependencies.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new PluginPackageException('MODULE_DEPENDENCIES_BUSY', 'Another operation is updating application dependency manifests or locks.');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepare(array $moduleRoots, PluginLockResolver $resolver, array $installed, ?array $releaseClients = null, bool $verifyOnly = false): array
    {
        $project = dirname($this->serverRoot);
        $known = [];
        foreach ($resolver->all() as $descriptor) {
            foreach ($descriptor->moduleRoots as $key => $root) {
                $known[$key] = (new ManifestLoader())->load($root)->data['version'];
            }
        }
        $composerPath = $this->serverRoot . '/composer.json';
        $composer = $this->json($composerPath);
        $composerNames = [];
        $runtimeRequirements = [];
        $clients = [];
        $optional = [];
        $standalone = \think\facade\Config::get('deployment.mode') === 'standalone';
        foreach ($releaseClients ?? [] as $client) {
            if ($standalone && $client === 'platform') { continue; }
            $clients[$client] = ['manifest' => $this->json($project . '/' . $client . '/package.json'), 'modules' => [], 'optional' => []];
        }
        foreach ($moduleRoots as $key => $root) {
            $manifest = (new ManifestLoader())->load($root)->data;
            foreach (['dependencies', 'optional_dependencies'] as $field) {
                foreach ($manifest[$field] ?? [] as $dependency) {
                    $required = $dependency['module_key'];
                    $version = isset($moduleRoots[$required]) ? ($known[$required] ?? null) : ($installed[$required]['version'] ?? null);
                    $reason = match (true) {
                        !isset($known[$required]) => 'MODULE_APPROVED_SOURCE_REQUIRED',
                        $version === null => 'MODULE_NOT_INSTALLED',
                        !Semver::satisfies($version, $dependency['version']) => 'MODULE_VERSION_UNSATISFIED',
                        !isset($moduleRoots[$required]) && ($installed[$required]['status'] ?? null) !== 'active' => 'MODULE_NOT_ACTIVE',
                        default => null,
                    };
                    if ($field === 'dependencies' && $reason !== null) {
                        throw new PluginPackageException('MODULE_REQUIRED_DEPENDENCY_UNAVAILABLE', "{$key} requires {$required} {$dependency['version']}: {$reason}. Install a compatible package from an explicitly approved source first.");
                    }
                    if ($field === 'optional_dependencies') {
                        $optional[] = ['module_key' => $key, 'dependency' => $required, 'available' => $reason === null, 'unavailable_reason' => $reason];
                    }
                }
            }
            $package = $this->json($root . '/composer.json');
            $runtimeRequirements[] = [$package['name'] => $package['version']];
            $runtimeRequirements[] = $package['require'] ?? [];
            $name = $package['name'];
            $relative = substr($root, strlen($this->serverRoot) + 1);
            $repository = ['type' => 'path', 'url' => $relative, 'options' => ['symlink' => false]];
            $repositories = $composer['repositories'] ?? [];
            $registered = false;
            foreach ($repositories as $index => $existing) {
                if (is_array($existing) && ($existing['type'] ?? null) === 'path' && ($existing['url'] ?? null) === $relative) {
                    $composer['repositories'][$index]['options']['symlink'] = false;
                    $registered = true;
                }
            }
            if (!$registered) {
                // Composer checks explicit application repositories before its public default registry.
                $composer['repositories'] = array_is_list($repositories)
                    ? [$repository, ...$repositories]
                    : ['module-' . $key => $repository, ...$repositories];
            }
            // The module itself is a native package node, so upgrades replace that node's requirements.
            // Its own version is authoritative here; module.json has an independently versioned protocol.
            $composer['require'][$name] = $package['version'];
            $composerNames[$name] = true;
            // Composer does not inherit a dependency's require-dev. A native local-only metapackage
            // carries those requirements as a root development node without overwriting host dev ranges.
            $devName = 'peanut-module-dev/' . $key;
            $devRequirements = $package['require-dev'] ?? [];
            if ($devRequirements !== [] || isset($composer['require-dev'][$devName])) {
                $metadata = ['type' => 'package', 'canonical' => true, 'package' => [
                    'name' => $devName, 'version' => $package['version'], 'type' => 'metapackage',
                    'require' => $devRequirements === [] ? new \stdClass() : $devRequirements,
                ]];
                $found = false;
                foreach ($composer['repositories'] ?? [] as $index => $existing) {
                    if (is_array($existing) && ($existing['type'] ?? null) === 'package'
                        && ($existing['package']['name'] ?? null) === $devName) {
                        if (($existing['package']['type'] ?? null) !== 'metapackage') {
                            throw new PluginPackageException('MODULE_DEPENDENCY_SOURCE_CONFLICT', "Reserved local development node {$devName} conflicts with an application package.");
                        }
                        $composer['repositories'][$index] = $metadata;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    if (isset($composer['require'][$devName]) || isset($composer['require-dev'][$devName])) {
                        throw new PluginPackageException('MODULE_DEPENDENCY_SOURCE_CONFLICT', "Reserved local development node {$devName} already has an application source.");
                    }
                    $repositories = $composer['repositories'] ?? [];
                    $composer['repositories'] = array_is_list($repositories)
                        ? [$metadata, ...$repositories] : ['module-dev-' . $key => $metadata, ...$repositories];
                }
                $composer['require-dev'][$devName] = $package['version'];
                $composerNames[$devName] = true;
            }
            foreach (ModuleFrontendLayout::contributions($manifest['frontend'] ?? [], $key) as $contribution) {
                $client = explode('/', $contribution['root'])[0];
                if ($standalone && $client === 'platform') { continue; }
                if ($releaseClients !== null && !in_array($client, $releaseClients, true)) { continue; }
                if (PHP_SAPI !== 'cli' || $verifyOnly) {
                    $clients[$client] = [];
                    continue;
                }
                $directory = $project . '/' . $client;
                $clientPackage = $this->json($project . '/' . $contribution['root'] . '/package.json');
                $local = substr($contribution['root'], strlen($client) + 1);
                $clients[$client] ??= ['manifest' => $this->json($directory . '/package.json'), 'modules' => [], 'optional' => []];
                $clients[$client]['modules'][] = $local;
                foreach ($clientPackage['optionalDependencies'] ?? [] as $optionalName => $range) {
                    $clients[$client]['optional'][] = ['module' => $local, 'package' => $optionalName, 'range' => $range];
                }
                // All dependency categories remain in the real workspace package, including dev/peer/optional.
                // Root dependencies are left intact for the native solver to evaluate alongside them.
            }
        }
        if (PHP_SAPI !== 'cli' || $verifyOnly) {
            $parser = new VersionParser();
            foreach ($runtimeRequirements as $requirements) {
                foreach ($requirements as $name => $range) {
                    $satisfied = match (true) {
                        $name === 'php' => Semver::satisfies(PHP_VERSION, $range),
                        str_starts_with($name, 'ext-') => extension_loaded(substr($name, 4)) && is_string(phpversion(substr($name, 4)))
                            && Semver::satisfies(phpversion(substr($name, 4)), $range),
                        default => InstalledVersions::isInstalled($name) && InstalledVersions::satisfies($parser, $name, $range),
                    };
                    if (!$satisfied) {
                        throw new PluginPackageException('MODULE_RELEASE_PREPARATION_REQUIRED', "{$name} {$range} requires native dependency preparation through the deployment CLI.");
                    }
                }
            }
            $frontend = [];
            foreach (array_keys($clients) as $client) {
                $entry = $this->frontendEntry($client);
                if (!is_file($project . '/' . $entry)) {
                    throw new PluginPackageException('MODULE_RELEASE_PREPARATION_REQUIRED', 'Prepare the selected Module frontend through scripts/deploy-update.sh --prepare-release before HTTP installation.');
                }
                $frontend[$client] = ['entry' => $entry];
            }
            return ['status' => 'dependencies-already-installed', 'operations' => [], 'optional_modules' => $optional, 'optional_packages' => [], 'frontend_adoption' => $frontend];
        }
        $operations = [];
        $optionalPackages = [];
        $this->write($composerPath, $composer);
        if ($composerNames !== []) {
            $operations[] = $this->run(['composer', 'update', ...array_keys($composerNames), '--with-all-dependencies', '--minimal-changes', '--no-interaction', '--no-progress', '--no-scripts'], $this->serverRoot);
        }
        $this->refreshAutoload();
        foreach ($clients as $client => $plan) {
            $directory = $project . '/' . $client;
            $path = $directory . '/package.json';
            if ($client === 'web') {
                $this->pnpmWorkspaces($directory, $plan['modules']);
                $this->write($path, $plan['manifest']);
                $operations[] = $this->run(['pnpm', 'install', '--prod=false', '--no-frozen-lockfile', '--strict-peer-dependencies', '--ignore-scripts'], $directory);
            } else {
                $workspaces = $plan['manifest']['workspaces'] ?? [];
                if (isset($workspaces['packages'])) {
                    $workspaces['packages'] = array_values(array_unique([...$workspaces['packages'], ...$plan['modules']]));
                } else {
                    $workspaces = array_values(array_unique([...$workspaces, ...$plan['modules']]));
                }
                $plan['manifest']['workspaces'] = $workspaces;
                $this->write($path, $plan['manifest']);
                $operations[] = $this->run(['npm', 'install', '--include=dev', '--strict-peer-deps', '--ignore-scripts', '--no-audit', '--no-fund'], $directory);
            }
            foreach ($plan['optional'] as $dependency) {
                $present = is_dir($directory . '/' . $dependency['module'] . '/node_modules/' . $dependency['package'])
                    || is_dir($directory . '/node_modules/' . $dependency['package']);
                $optionalPackages[] = $dependency + ['client' => $client, 'installed' => $present,
                    'unavailable_reason' => $present ? null : 'OPTIONAL_PACKAGE_NOT_INSTALLED'];
            }
        }
        $frontend = $this->adoptFrontend(array_keys($clients));
        return ['status' => 'dependencies-installed', 'operations' => $operations, 'optional_modules' => $optional, 'optional_packages' => $optionalPackages, 'frontend_adoption' => $frontend];
    }

    private function frontendEntry(string $client): string
    {
        return match ($client) {
            'web' => 'server/public/admin/index.html',
            'platform' => 'server/public/platform/index.html',
            'pc' => 'pc/.output/server/index.mjs',
            'uniapp' => 'uniapp/dist/build/h5/index.html',
            default => throw new PluginPackageException('MODULE_FRONTEND_ADOPTION_REQUIRED', 'The required client has no Module contribution reader.'),
        };
    }

    /** Build the clients whose native entries consume the selected Module contributions. */
    private function adoptFrontend(array $clients): array
    {
        if ($clients === []) { return []; }
        if (array_diff($clients, ['web', 'platform', 'pc', 'uniapp']) !== []) {
            throw new PluginPackageException('MODULE_FRONTEND_ADOPTION_REQUIRED', 'The required client has no Module contribution reader.',
                details: ['pending_actions' => ['frontend_module_assembly', 'frontend_build', 'frontend_adoption']]);
        }
        $edition = (string) app()->config->get('deployment.mode', '');
        if (!in_array($edition, ['standalone', 'multi-tenant'], true)) {
            throw new PluginPackageException('MODULE_FRONTEND_EDITION_REQUIRED', 'The application deployment Edition is required for the native admin build.');
        }
        $results = [];
        if (array_intersect($clients, ['web', 'platform']) !== []) {
            $project = dirname($this->serverRoot);
            $results['api-contracts'] = $this->run(['php', '-d', 'zend.exception_ignore_args=1',
                $project . '/scripts/generate-api-contracts.php', '--application-root=' . $project], $project);
        }
        foreach ($clients as $client) {
            if (in_array($client, ['web', 'platform'], true)) {
                $target = dirname($this->serverRoot) . '/' . dirname($this->frontendEntry($client));
                if (is_link($this->serverRoot . '/public') || is_link($target) || (file_exists($target) && !is_dir($target))) {
                    throw new PluginPackageException('MODULE_FRONTEND_TARGET_INVALID', 'The application admin static destination must be an ordinary directory.');
                }
            }
            [$key, $command, $entry] = match ($client) {
                'web' => ['admin-web', ['pnpm', 'run', 'build', '--mode', $edition, '--outDir', '../server/public/admin', '--emptyOutDir'], $this->frontendEntry($client)],
                'platform' => ['platform-web', ['npm', 'run', 'build', '--', '--outDir', '../server/public/platform', '--emptyOutDir'], 'server/public/platform/index.html'],
                'pc' => ['pc-web', ['npm', 'run', 'build'], 'pc/.output/server/index.mjs'],
                'uniapp' => ['uniapp', ['npm', 'run', 'build:h5'], 'uniapp/dist/build/h5/index.html'],
            };
            $result = $this->run($command, dirname($this->serverRoot) . '/' . $client);
            if (!is_file(dirname($this->serverRoot) . '/' . $entry)) {
                throw new PluginPackageException('MODULE_FRONTEND_ADOPTION_REQUIRED', "The native {$key} build did not produce its application entry.");
            }
            $results[$key] = $result + [($client === 'web' ? 'static_entry' : 'runtime_entry') => $entry];
        }
        return $results;
    }

    /** Composer ran in a child process; this invocation must see its newly installed package mappings. */
    private function refreshAutoload(): void
    {
        $vendor = realpath($this->serverRoot . '/vendor');
        foreach (ClassLoader::getRegisteredLoaders() as $path => $loader) {
            if (realpath($path) !== $vendor) { continue; }
            foreach (require $vendor . '/composer/autoload_psr4.php' as $prefix => $directories) {
                $loader->setPsr4($prefix, $directories);
            }
            foreach (require $vendor . '/composer/autoload_namespaces.php' as $prefix => $directories) {
                $loader->set($prefix, $directories);
            }
            $loader->addClassMap(require $vendor . '/composer/autoload_classmap.php');
            $files = $vendor . '/composer/autoload_files.php';
            if (is_file($files)) {
                foreach (require $files as $identifier => $file) {
                    if (!isset($GLOBALS['__composer_autoload_files'][$identifier])) {
                        $GLOBALS['__composer_autoload_files'][$identifier] = true;
                        (static function (string $path): void { require $path; })($file);
                    }
                }
            }
            InstalledVersions::reload(require $vendor . '/composer/installed.php');
            return;
        }
        throw new PluginPackageException('MODULE_DEPENDENCY_AUTOLOAD_UNAVAILABLE', 'The current application Composer loader is unavailable after dependency installation.');
    }

    /** Preserve existing pnpm settings and workspace entries; only append admitted module directories. */
    private function pnpmWorkspaces(string $directory, array $modules): void
    {
        $path = $directory . '/pnpm-workspace.yaml';
        $source = is_file($path) ? (string) file_get_contents($path) : '';
        if ($source !== '' && !str_ends_with($source, "\n")) { $source .= "\n"; }
        $entries = '';
        foreach (array_unique($modules) as $module) {
            if (preg_match('/^\s*-\s*[\'\"]?' . preg_quote($module, '/') . '[\'\"]?\s*$/m', $source) === 1) {
                continue;
            }
            $entries .= '  - ' . json_encode($module, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($entries === '') { return; }
        if (preg_match('/^packages:[ \t]*(?:#[^\n]*)?$/m', $source) === 1) {
            $next = preg_replace('/^(packages:[ \t]*(?:#[^\n]*)?\n)/m', '$1' . $entries, $source, 1);
        } elseif (preg_match('/^packages:/m', $source) === 1) {
            throw new PluginPackageException('MODULE_WORKSPACE_CONFIGURATION_REQUIRED', 'Existing pnpm packages configuration must use the native block-list form before adding Module workspaces.');
        } else {
            $next = "packages:\n" . $entries . $source;
        }
        if (file_put_contents($path, $next) === false) {
            throw new PluginPackageException('MODULE_DEPENDENCY_MANIFEST_WRITE_FAILED', 'Cannot update native pnpm workspace configuration.');
        }
    }

    private function write(string $path, array $manifest): void
    {
        $text = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $text) === false) {
            throw new PluginPackageException('MODULE_DEPENDENCY_MANIFEST_WRITE_FAILED', "Cannot update {$path}.");
        }
    }

    private function json(string $path): array
    {
        if (!is_file($path)) {
            throw new PluginPackageException('MODULE_DEPENDENCY_MANIFEST_MISSING', "Dependency manifest is unavailable: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new PluginPackageException('MODULE_DEPENDENCY_INVALID', "Dependency manifest must be an object: {$path}");
        }
        return $data;
    }

    private function run(array $command, string $directory): array
    {
        $project = dirname($this->serverRoot);
        $tool = $command[0];
        $selectionPath = $project . '/.local/core-dev.json';
        if ($tool === 'composer' && (file_exists($selectionPath) || is_link($selectionPath))) {
            $selection = $this->json($selectionPath);
            if (is_link($selectionPath) || ($selection['schema_version'] ?? null) !== 1
                || ($selection['application_root'] ?? null) !== realpath($project)) {
                throw new PluginPackageException('MODULE_DEPENDENCY_SOURCE_CONFLICT', 'The local Core selection must belong to this application.');
            }
            foreach (['php_core_dir', 'backend_env'] as $field) {
                $value = $selection[$field] ?? null;
                if (!is_string($value) || !str_starts_with($value, '/') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    throw new PluginPackageException('MODULE_DEPENDENCY_SOURCE_CONFLICT', 'The local Core selection requires absolute source and environment paths.');
                }
            }
            $command = [$project . '/scripts/local-core-composer', 'install',
                '--core-dir', $selection['php_core_dir'], '--backend-env', $selection['backend_env']];
            $tool = 'local-core-composer';
        }
        $host = $this->json($project . '/.local/toolchain/host.json');
        $python = $host['commands']['python3'] ?? null;
        if (!is_array($python) || !is_string($python[0] ?? null) || !str_starts_with($python[0], '/')) {
            throw new PluginPackageException('MODULE_DEPENDENCY_TOOLCHAIN_REQUIRED', 'Register the application Python command with project-env before installing module dependencies.');
        }
        $runner = [...$python, $project . '/scripts/project-env', '--root', $project];
        // Prepare the registered tool environment; business dependency changes alone
        // do not invalidate it. Never fall back to ambient tools.
        $this->execute([...$runner, 'prepare'], $project, 'project-env');
        return $this->execute([...$runner, 'exec', '--', ...$command], $directory, $tool);
    }

    private function execute(array $command, string $directory, string $tool): array
    {
        $output = tmpfile();
        if ($output === false) { throw new PluginPackageException('MODULE_DEPENDENCIES_UNAVAILABLE', 'Cannot capture native dependency output.'); }
        try {
            $process = proc_open($command,
                [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output], $pipes, $directory);
            if (!is_resource($process)) {
                throw new PluginPackageException('MODULE_DEPENDENCY_PROCESS_FAILED', 'Cannot start the registered dependency tool.');
            }
            $exit = proc_close($process);
            rewind($output);
            $text = stream_get_contents($output);
            if ($exit !== 0) {
                throw new PluginPackageException('MODULE_DEPENDENCY_INSTALL_FAILED', "Native {$tool} dependency operation failed with exit {$exit}:\n{$text}", $exit,
                    details: ['tool' => $tool, 'exit_code' => $exit, 'directory' => $directory, 'output' => $text]);
            }
            return ['tool' => $tool, 'exit_code' => $exit, 'directory' => $directory, 'output' => $text];
        } finally {
            fclose($output);
        }
    }
}
