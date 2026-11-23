<?php

declare(strict_types=1);

namespace app\common\validation\scaffold;

use app\common\value\scaffold\ScaffoldManifest;
use RuntimeException;

final class ScaffoldPathGuard
{
    public static function projectRoot(string $path): string
    {
        $root = realpath($path);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException("SCAFFOLD_PROJECT_ROOT_INVALID: {$path}");
        }
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    public static function projectPath(string $root, string $relative): string
    {
        ScaffoldManifest::path($relative);
        $cursor = $root;
        foreach (explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new RuntimeException("SCAFFOLD_PATH_SYMLINK_REJECTED: {$relative}");
            }
        }
        return $cursor;
    }

    public static function existingFileWithin(string $root, string $path, string $error): string
    {
        $resolvedRoot = realpath($root);
        $resolvedPath = realpath($path);
        if ($resolvedRoot === false || $resolvedPath === false || !is_file($resolvedPath) || is_link($path)
            || ($resolvedPath !== $resolvedRoot && !str_starts_with($resolvedPath, rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException("{$error}: {$path}");
        }
        $stat = lstat($resolvedPath);
        if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException("{$error}: {$path}");
        }
        return $resolvedPath;
    }

    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (file_exists($path) || !mkdir($path, 0775, true)) {
            throw new RuntimeException("SCAFFOLD_DIRECTORY_CREATE_FAILED: {$path}");
        }
    }
}
