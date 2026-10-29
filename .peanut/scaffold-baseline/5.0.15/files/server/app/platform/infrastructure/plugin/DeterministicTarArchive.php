<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\exception\plugin\PluginPackageException;

/** Minimal deterministic USTAR writer/reader for self-contained Module packages. */
final class DeterministicTarArchive
{
    public const MAX_ENTRIES = 10000;
    public const MAX_ENTRY_BYTES = 67108864;
    public const MAX_TOTAL_BYTES = 268435456;

    /**
     * @param array<string,array{source?:string,contents?:string}> $entries
     */
    public function write(string $target, array $entries): void
    {
        ksort($entries, SORT_STRING);
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PluginPackageException('MODULE_PACKAGE_WRITE_FAILED', 'Package output directory is unavailable.');
        }
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'xb');
        if (!is_resource($stream)) {
            throw new PluginPackageException('MODULE_PACKAGE_WRITE_FAILED', 'Package output cannot be created.');
        }

        try {
            foreach ($entries as $path => $entry) {
                $this->assertPath($path);
                $contents = array_key_exists('contents', $entry)
                    ? (string) $entry['contents']
                    : $this->readSource((string) ($entry['source'] ?? ''));
                $size = strlen($contents);
                if ($size > self::MAX_ENTRY_BYTES) {
                    throw new PluginPackageException('MODULE_PACKAGE_LIMIT_EXCEEDED', 'Package entry is too large.');
                }
                $this->writeAll($stream, $this->header($path, $size));
                $this->writeAll($stream, $contents);
                $padding = (512 - ($size % 512)) % 512;
                if ($padding !== 0) {
                    $this->writeAll($stream, str_repeat("\0", $padding));
                }
            }
            $this->writeAll($stream, str_repeat("\0", 1024));
            fflush($stream);
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $target)) {
                throw new PluginPackageException('MODULE_PACKAGE_WRITE_FAILED', 'Package output cannot be promoted.');
            }
        } catch (\Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        }
    }

    /** @return array<string,array{offset:int,size:int}> */
    public function scan(string $archive): array
    {
        $stream = fopen($archive, 'rb');
        if (!is_resource($stream)) {
            throw new PluginPackageException('MODULE_PACKAGE_UNREADABLE', 'Package archive is unreadable.');
        }
        $entries = [];
        $total = 0;
        $zeroBlocks = 0;
        try {
            while (true) {
                $headerOffset = ftell($stream);
                $header = fread($stream, 512);
                if ($header === '' && feof($stream)) {
                    break;
                }
                if (!is_string($header) || strlen($header) !== 512) {
                    throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar header is truncated.');
                }
                if ($header === str_repeat("\0", 512)) {
                    $zeroBlocks++;
                    if ($zeroBlocks >= 2) {
                        break;
                    }
                    continue;
                }
                if ($zeroBlocks !== 0) {
                    throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar has data after its end marker.');
                }
                $this->assertHeaderChecksum($header);
                $type = $header[156];
                if ($type !== "\0" && $type !== '0') {
                    throw new PluginPackageException('MODULE_PACKAGE_MEMBER_TYPE_INVALID', 'Package contains a non-regular member.');
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $path = $prefix === '' ? $name : $prefix . '/' . $name;
                $this->assertPath($path);
                if (isset($entries[$path])) {
                    throw new PluginPackageException('MODULE_PACKAGE_DUPLICATE_PATH', 'Package contains a duplicate path.');
                }
                $size = $this->octal(substr($header, 124, 12));
                if ($size > self::MAX_ENTRY_BYTES) {
                    throw new PluginPackageException('MODULE_PACKAGE_LIMIT_EXCEEDED', 'Package entry is too large.');
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES || count($entries) >= self::MAX_ENTRIES) {
                    throw new PluginPackageException('MODULE_PACKAGE_LIMIT_EXCEEDED', 'Package exceeds the configured limits.');
                }
                $contentOffset = (int) $headerOffset + 512;
                $entries[$path] = ['offset' => $contentOffset, 'size' => $size];
                $next = $contentOffset + $size + ((512 - ($size % 512)) % 512);
                if (fseek($stream, $next, SEEK_SET) !== 0) {
                    throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar member is truncated.');
                }
            }
            if ($zeroBlocks !== 2) {
                throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar end marker is missing.');
            }
            if (count($entries) === 0) {
                throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar is empty.');
            }
            return $entries;
        } finally {
            fclose($stream);
        }
    }

    /** @param array{offset:int,size:int} $entry */
    public function read(string $archive, array $entry): string
    {
        $stream = fopen($archive, 'rb');
        if (!is_resource($stream) || fseek($stream, $entry['offset'], SEEK_SET) !== 0) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new PluginPackageException('MODULE_PACKAGE_UNREADABLE', 'Package member is unreadable.');
        }
        try {
            $remaining = $entry['size'];
            $contents = '';
            while ($remaining > 0) {
                $chunk = fread($stream, min(1048576, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package member is truncated.');
                }
                $contents .= $chunk;
                $remaining -= strlen($chunk);
            }
            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string,array{offset:int,size:int}> $entries */
    public function extract(string $archive, array $entries, string $destination): void
    {
        if (file_exists($destination)) {
            throw new PluginPackageException('MODULE_PACKAGE_STAGING_INVALID', 'Package staging path already exists.');
        }
        if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new PluginPackageException('MODULE_PACKAGE_STAGING_INVALID', 'Package staging path cannot be created.');
        }
        foreach ($entries as $path => $entry) {
            $target = $destination . '/' . $path;
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new PluginPackageException('MODULE_PACKAGE_STAGING_INVALID', 'Package staging directory cannot be created.');
            }
            if (file_put_contents($target, $this->read($archive, $entry), LOCK_EX) === false) {
                throw new PluginPackageException('MODULE_PACKAGE_STAGING_INVALID', 'Package member cannot be extracted.');
            }
        }
    }

    private function header(string $path, int $size): string
    {
        [$name, $prefix] = $this->splitPath($path);
        $header = str_pad($name, 100, "\0")
            . "0000644\0"
            . "0000000\0"
            . "0000000\0"
            . sprintf('%011o', $size) . "\0"
            . "00000000000\0"
            . str_repeat(' ', 8)
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0"
            . '00'
            . str_repeat("\0", 32)
            . str_repeat("\0", 32)
            . "0000000\0"
            . "0000000\0"
            . str_pad($prefix, 155, "\0")
            . str_repeat("\0", 12);
        if (strlen($header) !== 512) {
            throw new PluginPackageException('MODULE_PACKAGE_WRITE_FAILED', 'Package tar header is invalid.');
        }
        $checksum = array_sum(unpack('C*', $header));
        return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    }

    /** @return array{string,string} */
    private function splitPath(string $path): array
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }
        $offset = strrpos(substr($path, 0, 256), '/');
        while ($offset !== false) {
            $prefix = substr($path, 0, $offset);
            $name = substr($path, $offset + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                return [$name, $prefix];
            }
            $offset = strrpos(substr($path, 0, $offset), '/');
        }
        throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package path is too long for USTAR.');
    }

    private function assertPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package path is unsafe.');
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package path is unsafe.');
            }
        }
        $this->splitPath($path);
    }

    private function assertHeaderChecksum(string $header): void
    {
        $expected = $this->octal(substr($header, 148, 8));
        $actual = array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)));
        if ($expected !== $actual) {
            throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar checksum is invalid.');
        }
    }

    private function octal(string $value): int
    {
        $value = trim($value, " \0");
        if ($value === '' || preg_match('/^[0-7]+$/D', $value) !== 1) {
            throw new PluginPackageException('MODULE_PACKAGE_INVALID_TAR', 'Package tar numeric field is invalid.');
        }
        return intval($value, 8);
    }

    private function readSource(string $source): string
    {
        if ($source === '' || !is_file($source) || is_link($source)) {
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Package source file is invalid.');
        }
        $contents = file_get_contents($source);
        if (!is_string($contents)) {
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_INVALID', 'Package source file is unreadable.');
        }
        return $contents;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new PluginPackageException('MODULE_PACKAGE_WRITE_FAILED', 'Package archive write failed.');
            }
            $offset += $written;
        }
    }
}
