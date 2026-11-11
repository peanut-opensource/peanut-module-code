<?php

declare(strict_types=1);

namespace app\common\infrastructure\runtime;

use RuntimeException;
use think\cache\driver\File;

/** File-driver read fix for ThinkPHP 8.1.4's path-based mtime race. */
final class FileCache extends File
{
    protected function getRaw(string $name)
    {
        $filename = $this->getCacheKey($name);
        $missing = false;
        $previousHandler = null;

        // PHP exposes no fopen errno. Handle only the native ENOENT warning,
        // for this single open; all other errors retain the installed handler.
        $previousHandler = set_error_handler(
            static function (int $severity, string $message, string $file, int $line) use (&$missing, &$previousHandler): bool {
                if ($severity === E_WARNING && str_ends_with($message, ': No such file or directory')) {
                    $missing = true;
                    return true;
                }

                return is_callable($previousHandler)
                    ? $previousHandler($severity, $message, $file, $line) !== false
                    : false;
            },
            E_WARNING,
        );

        try {
            $handle = fopen($filename, 'rb');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            if ($missing) {
                return null;
            }
            throw new RuntimeException('Unable to open cache file.');
        }

        try {
            // Native File::set uses LOCK_EX. Read content and metadata from
            // the same locked inode even if its pathname is removed/replaced.
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Unable to lock cache file for reading.');
            }
            $content = stream_get_contents($handle);
            $stat = fstat($handle);
            if ($content === false || $stat === false) {
                throw new RuntimeException('Unable to read cache file.');
            }
        } finally {
            fclose($handle);
        }

        $expire = (int) substr($content, 8, 12);
        if (0 != $expire && time() - $expire > $stat['mtime']) {
            // Do not unlink by pathname: another worker may already have
            // replaced it. Native set/delete/clear retain cleanup ownership.
            return null;
        }

        $content = substr($content, 32);
        if ($this->options['data_compress'] && function_exists('gzcompress')) {
            $content = gzuncompress($content);
        }

        return is_string($content) ? ['content' => $content, 'expire' => $expire] : null;
    }
}
