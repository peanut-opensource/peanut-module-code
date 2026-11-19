<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use RuntimeException;

/** Writes a deterministic gzip-compressed USTAR tree without host metadata. */
final class DeterministicEditionArchive
{
    public function write(string $sourceRoot, string $archiveRoot, string $target): void
    {
        if (!is_dir($sourceRoot) || is_link($sourceRoot)
            || preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $archiveRoot) !== 1) {
            throw new RuntimeException('EDITION_ARCHIVE_SOURCE_INVALID');
        }
        $entries = $this->entries($sourceRoot, $archiveRoot);
        if ($entries === []) {
            throw new RuntimeException('EDITION_ARCHIVE_SOURCE_EMPTY');
        }
        $directory = dirname($target);
        if ((!is_dir($directory) && !mkdir($directory, 0775, true)) || is_link($directory)) {
            throw new RuntimeException('EDITION_ARCHIVE_OUTPUT_INVALID');
        }
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'xb');
        if (!is_resource($stream)) {
            throw new RuntimeException('EDITION_ARCHIVE_OUTPUT_INVALID');
        }
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        if ($deflate === false) {
            fclose($stream);
            unlink($temporary);
            throw new RuntimeException('EDITION_ARCHIVE_COMPRESSION_UNAVAILABLE');
        }

        try {
            foreach ($entries as $entry) {
                $this->writeCompressed($stream, $deflate, $this->header(
                    $entry['archive_path'],
                    $entry['mode'],
                    $entry['size'],
                    $entry['type'],
                ));
                if ($entry['type'] === '0') {
                    $input = fopen($entry['source'], 'rb');
                    if (!is_resource($input)) {
                        throw new RuntimeException('EDITION_ARCHIVE_SOURCE_UNREADABLE: ' . $entry['relative']);
                    }
                    try {
                        while (!feof($input)) {
                            $chunk = fread($input, 1048576);
                            if (!is_string($chunk)) {
                                throw new RuntimeException('EDITION_ARCHIVE_SOURCE_UNREADABLE: ' . $entry['relative']);
                            }
                            if ($chunk !== '') {
                                $this->writeCompressed($stream, $deflate, $chunk);
                            }
                        }
                    } finally {
                        fclose($input);
                    }
                    $padding = (512 - ($entry['size'] % 512)) % 512;
                    if ($padding !== 0) {
                        $this->writeCompressed($stream, $deflate, str_repeat("\0", $padding));
                    }
                }
            }
            $this->writeCompressed($stream, $deflate, str_repeat("\0", 1024));
            $tail = deflate_add($deflate, '', ZLIB_FINISH);
            if (!is_string($tail)) {
                throw new RuntimeException('EDITION_ARCHIVE_COMPRESSION_FAILED');
            }
            $this->writeAll($stream, $tail);
            if (!fflush($stream)) {
                throw new RuntimeException('EDITION_ARCHIVE_WRITE_FAILED');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $target)) {
                throw new RuntimeException('EDITION_ARCHIVE_PROMOTION_FAILED');
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

    /** @return list<array{relative:string,archive_path:string,source:string,type:string,size:int,mode:int}> */
    private function entries(string $sourceRoot, string $archiveRoot): array
    {
        $root = realpath($sourceRoot);
        if (!is_string($root)) {
            throw new RuntimeException('EDITION_ARCHIVE_SOURCE_INVALID');
        }
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isLink()) {
                throw new RuntimeException('EDITION_ARCHIVE_SYMLINK_REJECTED');
            }
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($relative === '' || str_contains($relative, '../')) {
                throw new RuntimeException('EDITION_ARCHIVE_PATH_INVALID');
            }
            $stat = lstat($path);
            if (!is_array($stat) || (!$file->isDir() && ($stat['nlink'] ?? 0) !== 1)) {
                throw new RuntimeException('EDITION_ARCHIVE_HARDLINK_REJECTED: ' . $relative);
            }
            $type = $file->isDir() ? '5' : ($file->isFile() ? '0' : '');
            if ($type === '') {
                throw new RuntimeException('EDITION_ARCHIVE_FILE_TYPE_REJECTED: ' . $relative);
            }
            $entries[] = [
                'relative' => $relative,
                'archive_path' => $archiveRoot . '/' . $relative,
                'source' => $path,
                'type' => $type,
                'size' => $type === '0' ? (int) $file->getSize() : 0,
                'mode' => $file->getPerms() & 0777,
            ];
        }
        usort($entries, static fn(array $left, array $right): int => strcmp($left['archive_path'], $right['archive_path']));
        return $entries;
    }

    private function header(string $path, int $mode, int $size, string $type): string
    {
        [$name, $prefix] = $this->splitPath($path);
        $header = str_pad($name, 100, "\0")
            . sprintf('%07o', $mode) . "\0"
            . "0000000\0"
            . "0000000\0"
            . sprintf('%011o', $size) . "\0"
            . "00000000000\0"
            . str_repeat(' ', 8)
            . $type
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
            throw new RuntimeException('EDITION_ARCHIVE_HEADER_INVALID');
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
        throw new RuntimeException('EDITION_ARCHIVE_PATH_TOO_LONG: ' . $path);
    }

    /** @param resource $stream @param resource $deflate */
    private function writeCompressed($stream, $deflate, string $bytes): void
    {
        $compressed = deflate_add($deflate, $bytes, ZLIB_NO_FLUSH);
        if (!is_string($compressed)) {
            throw new RuntimeException('EDITION_ARCHIVE_COMPRESSION_FAILED');
        }
        $this->writeAll($stream, $compressed);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('EDITION_ARCHIVE_WRITE_FAILED');
            }
            $offset += $written;
        }
    }
}
