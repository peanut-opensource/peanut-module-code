<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use Custom\Modules\AiAssistant\Definition\AiDefinitions as D;

/** Guzzle's public string sink requires is_dir(dirname(sink)); this bounded PHP stream preserves that contract. */
final class BoundedResponseSink
{
    public $context;
    private static array $issued = [];
    private $handle = null;
    private int $written = 0;
    public static function uri(string $path): string
    {
        if (is_link($path) || !is_dir(dirname($path)) || !str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('AI_RESPONSE_PATH_INVALID');
        }
        if (!in_array('ai-response', stream_get_wrappers(), true) && !stream_wrapper_register('ai-response', self::class)) {
            throw new \RuntimeException('AI_RESPONSE_BOUND_UNAVAILABLE');
        }
        $token = bin2hex(random_bytes(16));
        self::$issued[$token] = $path;
        return 'ai-response://' . $token . '/response';
    }
    public static function release(string $uri): void
    {
        $u = parse_url($uri);
        if (is_array($u) && ($u['scheme'] ?? '') === 'ai-response') {
            unset(self::$issued[$u['host'] ?? '']);
        }
    }
    public function url_stat($path, $flags): array|false
    {
        $u = parse_url($path);
        if (!is_array($u) || !isset(self::$issued[$u['host'] ?? ''])) {
            return false;
        }
        if (($u['path'] ?? '') === '') {
            return ['mode' => 0040700,'size' => 0,'nlink' => 1];
        }
        return ($u['path'] ?? '') === '/response' && is_file(self::$issued[$u['host']]) ? stat(self::$issued[$u['host']]) : false;
    }
    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        $u = parse_url($path);
        $file = is_array($u) ? (self::$issued[$u['host'] ?? ''] ?? null) : null;
        if ($file === null || ($u['path'] ?? '') !== '/response' || !in_array($mode, ['w+','w+b','wb+'], true) || is_link($file)) {
            return false;
        }
        $this->handle = fopen($file, 'w+b');
        if ($this->handle === false) {
            return false;
        }chmod($file, 0600);
        $this->written = 0;
        $openedPath = $path;
        return true;
    }
    public function stream_write($bytes): int
    {
        $length = strlen($bytes);
        if ($this->written + $length > D::RESPONSE_LIMIT) {
            return 0;
        }
        $n = fwrite($this->handle, $bytes);
        if ($n === false) {
            return 0;
        }$this->written += $n;
        return $n;
    }
    public function stream_read($count): string|false
    {
        return fread($this->handle, $count);
    }
    public function stream_seek($offset, $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }
    public function stream_tell(): int|false
    {
        return ftell($this->handle);
    }
    public function stream_eof(): bool
    {
        return feof($this->handle);
    }
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }
    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }
    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
