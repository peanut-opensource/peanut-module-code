<?php

declare(strict_types=1);

use app\common\value\module\ModulePhpNamespace;
use app\platform\infrastructure\module\ModuleLockResolver;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Menu\CoreMenuCatalog;

/** @param list<string> $roots */
function installationContractAssertClassSource(string $class, string|false $path, string $namespace, array $roots): void
{
    if ($path === false || !str_starts_with($class, $namespace)) {
        throw new RuntimeException();
    }
    $resolved = realpath($path);
    $relative = '/' . str_replace('\\', '/', substr($class, strlen($namespace))) . '.php';
    foreach ($roots as $root) {
        if ($resolved === $root . $relative) {
            return;
        }
    }
    throw new RuntimeException();
}

function installationContractVerifyDigest(string $path, string $expected): void
{
    $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if (!is_string($digest) || !hash_equals($expected, $digest)) {
        throw new RuntimeException();
    }
}

/** @return array<string,string> */
function installationContractSourceDigests(string $root): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $digests = [];
    foreach ($files as $file) {
        if ($file->isLink() || !$file->isFile() || ($digest = hash_file('sha256', $file->getPathname())) === false) {
            throw new RuntimeException();
        }
        $digests[substr($file->getPathname(), strlen($root) + 1)] = $digest;
    }
    ksort($digests, SORT_STRING);
    return $digests;
}

// Load the prepared consumer, without creating or initializing a ThinkPHP application.
$failure = 'INSTALL_SEED_CONTRACT_SOURCE_REQUIRED';
try {
    $root = $argv[1] ?? dirname(__DIR__);
    if (PHP_SAPI !== 'cli' || count($argv) > 2 || !str_starts_with($root, '/')
        || ($root = realpath($root)) === false) {
        throw new RuntimeException();
    }
    $server = $root . '/server';
    if (!is_file($server . '/vendor/autoload.php') || !is_file($root . '/modules.lock')) {
        throw new RuntimeException();
    }
    $loader = require $server . '/vendor/autoload.php';
    if (!$loader instanceof ClassLoader) {
        throw new RuntimeException();
    }

    $identity = ModuleLockResolver::forRuntime($server, '../modules.lock')->require('peanut.identity');
    $identityRoot = realpath($identity->moduleRoots['peanut.identity'] ?? '');
    $source = $identityRoot === false ? '' : $identityRoot . '/src';
    if ($source === '' || !is_dir($source) || realpath($source) !== $source) {
        throw new RuntimeException();
    }

    $namespace = ModulePhpNamespace::fromModuleRoot($identityRoot);
    $prefixes = $loader->getPrefixesPsr4();
    foreach (array_keys($prefixes) as $prefix) {
        if ($prefix !== $namespace && (str_starts_with($prefix, $namespace) || str_starts_with($namespace, $prefix))) {
            throw new RuntimeException();
        }
    }
    $autoloadRoots = array_values(array_unique(array_map('realpath', $prefixes[$namespace] ?? []), SORT_REGULAR));
    if ($autoloadRoots === [] || in_array(false, $autoloadRoots, true)) {
        throw new RuntimeException();
    }

    $otherRoots = array_values(array_diff($autoloadRoots, [$source]));
    if ($otherRoots !== []) {
        $package = $identity->composer[0] ?? null;
        if (count($identity->composer) !== 1 || !is_array($package)
            || $package['version'] !== $identity->version
            || !InstalledVersions::isInstalled($package['name'])
            || InstalledVersions::getPrettyVersion($package['name']) !== $identity->version) {
            throw new RuntimeException();
        }
        $installed = $server . '/vendor/' . $package['name'];
        $installPath = InstalledVersions::getInstallPath($package['name']);
        if (!is_string($installPath) || realpath($installPath) !== $installed
            || $otherRoots !== [$installed . '/src']) {
            throw new RuntimeException();
        }
        installationContractVerifyDigest($installed . '/composer.json', $package['sha256']);
        installationContractVerifyDigest($installed . '/module.json', $identity->manifestDigest);
        if (installationContractSourceDigests($source) !== installationContractSourceDigests($otherRoots[0])) {
            throw new RuntimeException();
        }
    }

    foreach ($loader->getClassMap() as $class => $path) {
        if (str_starts_with($class, $namespace)) {
            installationContractAssertClassSource($class, $path, $namespace, $autoloadRoots);
        }
    }
    foreach ([CorePermissionCatalog::class, CorePermissionCatalogSynchronizer::class, CoreMenuCatalog::class] as $class) {
        installationContractAssertClassSource($class, $loader->findFile($class), $namespace, $autoloadRoots);
        installationContractAssertClassSource($class, (new ReflectionClass($class))->getFileName(), $namespace, $autoloadRoots);
    }

    $failure = 'INSTALL_SEED_CONTRACT_UNAVAILABLE';
    $catalog = new class implements AuthorizationCatalogRepository {
        public array $keys = [];

        public function syncPermission(PermissionDefinition $definition): int
        {
            if (isset($this->keys[$definition->key])) {
                throw new RuntimeException();
            }
            $this->keys[$definition->key] = true;
            return count($this->keys);
        }

        public function permissionId(string $key): int
        {
            throw new LogicException();
        }

        public function registryRevision(): string
        {
            throw new LogicException();
        }
    };

    (new CorePermissionCatalogSynchronizer($catalog))->synchronize();
    $expected = array_fill_keys([...CorePermissionCatalog::TENANT, ...CorePermissionCatalog::PLATFORM], true);
    if ($expected === [] || count($catalog->keys) !== count($expected) || array_diff_key($expected, $catalog->keys) !== []) {
        throw new RuntimeException();
    }
    $menus = CoreMenuCatalog::definitions();
    if ($menus === []) {
        throw new RuntimeException();
    }
    foreach ($menus as $menu) {
        if (!$menu instanceof MenuDefinition) {
            throw new RuntimeException();
        }
    }
} catch (Throwable) {
    fwrite(STDERR, $failure . PHP_EOL);
    exit(1);
}

echo 'INITIAL-INSTALL-SEED-CONTRACT passed' . PHP_EOL;
