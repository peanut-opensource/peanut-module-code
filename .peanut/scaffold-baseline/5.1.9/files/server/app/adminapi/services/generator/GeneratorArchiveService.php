<?php

declare(strict_types=1);

namespace app\adminapi\services\generator;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 代码生成归档服务。
 *
 * 每次生成仅操作当前管理员下的独立随机目录，避免并发任务互相覆盖或清理。
 */
class GeneratorArchiveService
{
    /**
     * @param array<int,array{path:string,content:string}> $files
     * @return array{archive_path:string,download_name:string}
     */
    public static function build(array $files, int $adminId, string $archiveName = 'peanut-code.zip'): array
    {
        if ($adminId <= 0) {
            throw new RuntimeException('管理员标识无效');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('服务器未安装 ZipArchive 扩展');
        }
        if ($files === []) {
            throw new RuntimeException('没有可归档的代码文件');
        }

        $runId = bin2hex(random_bytes(16));
        $relativeDirectory = 'generator/' . $adminId . '/' . $runId;
        $directory = self::runtimeDescendant($relativeDirectory, false);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('生成目录创建失败');
        }

        $fileName = self::archiveName($archiveName);
        $archivePath = $directory . DIRECTORY_SEPARATOR . $fileName;
        $zip = new ZipArchive();
        try {
            if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('代码压缩包创建失败');
            }
            foreach ($files as $file) {
                $entry = self::safeEntryPath((string) ($file['path'] ?? ''));
                if (!$zip->addFromString($entry, (string) ($file['content'] ?? ''))) {
                    throw new RuntimeException('代码文件写入压缩包失败: ' . $entry);
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('代码压缩包保存失败');
            }
        } catch (Throwable $exception) {
            $zip->close();
            self::removeRunDirectory($directory);
            throw $exception;
        }

        return [
            'archive_path' => $relativeDirectory . '/' . $fileName,
            'download_name' => $fileName,
        ];
    }

    /** @return array{archive_path:string,download_name:string} */
    public static function create(array $files, int $adminId, string $archiveName = 'peanut-code.zip'): array
    {
        return self::build($files, $adminId, $archiveName);
    }

    /**
     * 将数据库中保存的相对路径安全解析为可下载的真实文件。
     */
    public static function resolve(string $storedRelativePath, int $adminId): string
    {
        $path = self::ownedArchivePath($storedRelativePath, $adminId);
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw new RuntimeException('代码压缩包不存在或已过期');
        }
        self::assertDescendant($realPath, self::adminDirectory($adminId));
        return $realPath;
    }

    public static function resolveArchive(string $storedRelativePath, int $adminId): string
    {
        return self::resolve($storedRelativePath, $adminId);
    }

    /**
     * 仅清理该归档所属的随机运行目录，不触碰管理员的其他并发任务。
     */
    public static function cleanup(string $storedRelativePath, int $adminId): void
    {
        $archivePath = self::ownedArchivePath($storedRelativePath, $adminId);
        $runDirectory = dirname($archivePath);
        self::assertDescendant($runDirectory, self::adminDirectory($adminId));
        self::removeRunDirectory($runDirectory);
    }

    public static function cleanupAfterResponse(string $storedRelativePath, int $adminId): void
    {
        try {
            self::cleanup($storedRelativePath, $adminId);
        } catch (Throwable) {
            // The response has already been sent; the scheduled cleanup command owns retries.
        }
    }

    private static function ownedArchivePath(string $storedRelativePath, int $adminId): string
    {
        if ($adminId <= 0) {
            throw new RuntimeException('管理员标识无效');
        }
        $relative = self::safeEntryPath($storedRelativePath);
        $pattern = '#^generator/' . preg_quote((string) $adminId, '#')
            . '/[a-f0-9]{32}/[A-Za-z0-9._-]+\.zip$#';
        if (preg_match($pattern, $relative) !== 1) {
            throw new RuntimeException('代码压缩包路径无效');
        }
        return self::runtimeDescendant($relative, false);
    }

    private static function adminDirectory(int $adminId): string
    {
        return rtrim(runtime_path(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'generator' . DIRECTORY_SEPARATOR . $adminId;
    }

    private static function runtimeDescendant(string $relativePath, bool $mustExist): string
    {
        $relative = self::safeEntryPath($relativePath);
        $runtime = rtrim(runtime_path(), DIRECTORY_SEPARATOR);
        $target = $runtime . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        self::assertDescendant($target, $runtime);
        if ($mustExist && !file_exists($target)) {
            throw new RuntimeException('生成文件不存在');
        }
        return $target;
    }

    private static function safeEntryPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new RuntimeException('代码文件路径无效');
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException('禁止使用绝对路径');
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('代码文件路径包含非法目录');
            }
        }
        return implode('/', $segments);
    }

    private static function archiveName(string $requestedName): string
    {
        $name = trim(basename(str_replace('\\', '/', $requestedName)));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'peanut-code.zip';
        $name = preg_replace('/\.zip$/i', '', $name) ?: 'peanut-code';
        return substr($name, 0, 80) . '.zip';
    }

    private static function assertDescendant(string $target, string $parent): void
    {
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        $target = str_replace('\\', '/', $target);
        if (!str_starts_with($target . '/', $parent . '/')) {
            throw new RuntimeException('生成文件路径越界');
        }
    }

    private static function removeRunDirectory(string $directory): void
    {
        if (is_link($directory)) {
            @unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeRunDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
