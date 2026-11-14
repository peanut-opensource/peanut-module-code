<?php

declare(strict_types=1);

namespace app\platform\validation\module;

use PeanutAdmin\Kernel\Module\ModuleException;

/** Public declaration policy; internal implementation classes remain available to their owning module. */
final class ModulePublicSurfacePolicy
{
    public static function isInternalPersistence(string $symbol): bool
    {
        $segments = explode('\\', ltrim($symbol, '\\'));
        $leaf = array_pop($segments);

        foreach ($segments as $segment) {
            if (in_array(strtolower($segment), ['model', 'models', 'persistence', 'repository', 'repositories'], true)) {
                return true;
            }
        }

        return preg_match('/(?:Repository|Store|QueryBuilder)$/Di', (string) $leaf) === 1
            || in_array(strtolower((string) $leaf), ['model', 'query'], true);
    }

    public static function assertValid(object $manifest): void
    {
        $exports = $manifest->contracts->exports ?? [];
        if (!is_array($exports)) {
            throw new ModuleException('MODULE_PUBLIC_SURFACE_INVALID', 'Module exports must be an explicit list.');
        }
        $seen = [];
        foreach ($exports as $symbol) {
            if (!is_string($symbol) || $symbol === '') {
                throw new ModuleException('MODULE_PUBLIC_SURFACE_INVALID', 'Module export must be a named capability.');
            }
            if (self::isInternalPersistence($symbol)) {
                throw new ModuleException('MODULE_PUBLIC_PERSISTENCE_FORBIDDEN', 'Module public capabilities cannot expose persistence: ' . $symbol);
            }
            $key = strtolower($symbol);
            if (isset($seen[$key])) {
                throw new ModuleException('MODULE_PUBLIC_SURFACE_DUPLICATE', 'Module exports must not contain duplicate or case-alias capabilities.');
            }
            $seen[$key] = true;
        }
    }
}
