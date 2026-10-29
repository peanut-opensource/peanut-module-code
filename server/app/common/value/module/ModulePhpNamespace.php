<?php

declare(strict_types=1);

namespace app\common\value\module;

use InvalidArgumentException;

/** 模块 PHP 前缀策略；模块自身 composer.json 是已存在模块的唯一事实源。 */
final class ModulePhpNamespace
{
    /** @var non-empty-list<string> */
    private const RESERVED_PREFIXES = [
        'app\\',
        'PeanutAdmin\\DataPermission\\',
        'PeanutAdmin\\FileMedia\\',
        'PeanutAdmin\\IntegrationSecurity\\',
        'PeanutAdmin\\Kernel\\',
        'PeanutAdmin\\Settings\\',
    ];

    public static function production(string $moduleName): string
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $moduleName) !== 1) {
            throw new InvalidArgumentException('Invalid Module PHP name.');
        }
        return 'PeanutAdmin\\Modules\\' . $moduleName . '\\';
    }

    public static function generated(string $vendor, string $moduleName): string
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $vendor) !== 1
            || preg_match('/^[A-Z][A-Za-z0-9]*$/D', $moduleName) !== 1) {
            throw new InvalidArgumentException('Invalid generated Module PHP namespace input.');
        }
        if (in_array($vendor, ['official', 'peanut'], true)) {
            return self::production($moduleName);
        }
        if ($vendor === 'fixture') {
            return 'PeanutAdmin\\Fixtures\\' . $moduleName . '\\';
        }
        $vendorNamespace = implode('', array_map('ucfirst', explode('-', $vendor)));
        return self::validated($vendorNamespace . '\\Modules\\' . $moduleName . '\\');
    }

    public static function fromModuleRoot(string $moduleRoot): string
    {
        $composerPath = rtrim($moduleRoot, '/') . '/composer.json';
        if (!is_file($composerPath) || is_link($composerPath)) {
            throw new InvalidArgumentException('Module Composer manifest is unavailable.');
        }
        try {
            $composer = json_decode((string) file_get_contents($composerPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Module Composer manifest is invalid.', 0, $exception);
        }
        $psr4 = is_array($composer) && is_array($composer['autoload']['psr-4'] ?? null)
            ? $composer['autoload']['psr-4']
            : null;
        if ($psr4 === null || count($psr4) !== 1) {
            throw new InvalidArgumentException('Module must declare exactly one PSR-4 prefix.');
        }
        $prefix = array_key_first($psr4);
        $path = $prefix === null ? null : $psr4[$prefix];
        if (!is_string($prefix) || !is_string($path) || $path !== 'src/') {
            throw new InvalidArgumentException('Module PSR-4 prefix must be stable and map to src/.');
        }
        return self::validated($prefix);
    }

    /**
     * @param array<string,string> $moduleRoots Module key => verified absolute module root.
     * @return array<string,string> Module key => canonical PSR-4 prefix.
     */
    public static function map(array $moduleRoots): array
    {
        $namespaces = [];
        foreach ($moduleRoots as $moduleKey => $moduleRoot) {
            if (!is_string($moduleKey) || !is_string($moduleRoot) || $moduleKey === '' || $moduleRoot === '') {
                throw new InvalidArgumentException('Module namespace source is invalid.');
            }
            $prefix = self::fromModuleRoot($moduleRoot);
            $normalized = strtolower($prefix);
            foreach ($namespaces as $existingKey => $existingPrefix) {
                $existing = strtolower($existingPrefix);
                if (str_starts_with($normalized, $existing) || str_starts_with($existing, $normalized)) {
                    throw new InvalidArgumentException(
                        "Module PHP namespaces overlap: {$existingKey} and {$moduleKey}.",
                    );
                }
            }
            $namespaces[$moduleKey] = $prefix;
        }
        ksort($namespaces, SORT_STRING);
        return $namespaces;
    }

    public static function validated(string $prefix): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\\\\$/D', $prefix) !== 1) {
            throw new InvalidArgumentException('Module PSR-4 prefix is invalid.');
        }
        $normalized = strtolower($prefix);
        foreach (self::RESERVED_PREFIXES as $reserved) {
            $reservedNormalized = strtolower($reserved);
            if (str_starts_with($normalized, $reservedNormalized)
                || str_starts_with($reservedNormalized, $normalized)) {
                throw new InvalidArgumentException('Module PSR-4 prefix overlaps a host or Core namespace.');
            }
        }
        foreach (['PeanutAdmin\\Modules\\', 'PeanutAdmin\\Fixtures\\'] as $container) {
            if (strcasecmp($prefix, $container) === 0) {
                throw new InvalidArgumentException('Module PSR-4 prefix cannot claim a shared namespace root.');
            }
        }
        return $prefix;
    }
}
