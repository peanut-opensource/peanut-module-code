<?php

declare(strict_types=1);

use app\platform\infrastructure\module\ModuleLockResolver;
use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalog;
use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Menu\CoreMenuCatalog;

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
    require $server . '/vendor/autoload.php';

    $identity = ModuleLockResolver::forRuntime($server, '../modules.lock')->require('peanut.identity');
    $identityRoot = $identity->moduleRoots['peanut.identity'] ?? null;
    if (!is_string($identityRoot) || ($identityRoot = realpath($identityRoot)) === false) {
        throw new RuntimeException();
    }
    foreach ([CorePermissionCatalog::class, CorePermissionCatalogSynchronizer::class, CoreMenuCatalog::class] as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        if (!is_string($file) || !str_starts_with((string) realpath($file), $identityRoot . '/')) {
            throw new RuntimeException();
        }
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
