<?php

declare(strict_types=1);

namespace app\common\infrastructure\module;

use app\common\value\module\ModulePhpNamespace;
use Composer\Autoload\ClassLoader;
use InvalidArgumentException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;

/** Translates verified module Composer declarations into the Core host layout and runtime loader. */
final class ModuleHostLayoutFactory
{
    /** @param array<string,string> $moduleRoots Module key => verified absolute module root. */
    public static function fromModuleRoots(array $moduleRoots): ModuleHostLayout
    {
        return self::fromNamespaces(ModulePhpNamespace::map($moduleRoots));
    }

    public static function pathLayout(): ModuleHostLayout
    {
        return self::fromNamespaces([]);
    }

    /** @param array<string,string> $existingRoots Module key => verified absolute module root. */
    public static function assertNamespaceAvailable(
        string $moduleKey,
        string $namespace,
        array $existingRoots,
    ): void {
        $namespaces = ModulePhpNamespace::map($existingRoots);
        if (isset($namespaces[$moduleKey])) {
            throw new InvalidArgumentException('Module key already owns a PHP namespace.');
        }
        $namespaces[$moduleKey] = ModulePhpNamespace::validated($namespace);
        self::fromNamespaces($namespaces);
    }

    /**
     * Registers only roots already selected by deployment configuration or plugins.lock.
     * @param array<string,string> $moduleRoots Module key => verified absolute module root.
     */
    public static function registerRuntimeAutoload(array $moduleRoots, string $serverRoot): ModuleHostLayout
    {
        $namespaces = ModulePhpNamespace::map($moduleRoots);
        $layout = self::fromNamespaces($namespaces);
        $vendorRoot = realpath(rtrim($serverRoot, '/') . '/vendor');
        if ($vendorRoot === false) {
            throw new InvalidArgumentException('Host Composer vendor root is unavailable.');
        }
        $loader = null;
        foreach (ClassLoader::getRegisteredLoaders() as $registeredVendor => $candidate) {
            if (realpath($registeredVendor) === $vendorRoot) {
                $loader = $candidate;
                break;
            }
        }
        if (!$loader instanceof ClassLoader) {
            throw new InvalidArgumentException('Host Composer loader is unavailable.');
        }

        $registered = $loader->getPrefixesPsr4();
        $pending = [];
        foreach ($namespaces as $moduleKey => $prefix) {
            $moduleRoot = realpath($moduleRoots[$moduleKey]);
            $source = realpath($moduleRoots[$moduleKey] . '/src');
            if ($moduleRoot === false || $source === false || !is_dir($source)
                || !str_starts_with($source, $moduleRoot . DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException("Module PHP source root is unavailable: {$moduleKey}.");
            }
            foreach ($registered as $existingPrefix => $directories) {
                $samePrefix = $prefix === $existingPrefix;
                $overlaps = str_starts_with(strtolower($prefix), strtolower($existingPrefix))
                    || str_starts_with(strtolower($existingPrefix), strtolower($prefix));
                $sameSource = $samePrefix && count($directories) === 1
                    && realpath((string) $directories[0]) === $source;
                if ($overlaps && !$sameSource) {
                    throw new InvalidArgumentException(
                        "Module PHP namespace {$prefix} conflicts with an existing Composer prefix.",
                    );
                }
            }
            $pending[$prefix] = [$source];
            $registered[$prefix] = [$source];
        }
        // 先核验完整集合，再变更当前加载器；后一模块失败不能留下部分注册。
        foreach ($pending as $prefix => $sources) {
            $loader->setPsr4($prefix, $sources);
        }
        return $layout;
    }

    /** @param array<string,string> $namespaces */
    private static function fromNamespaces(array $namespaces): ModuleHostLayout
    {
        return new ModuleHostLayout(
            'server/app/modules',
            'app\\modules',
            'web/src/modules',
            $namespaces,
        );
    }
}
