<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

final readonly class AiPrivateStore
{
    public function directory(int $tenantId, int $id): string
    {
        if ($tenantId < 1 || $id < 1) {
            throw new \InvalidArgumentException('AI_PRIVATE_ID_INVALID');
        }
        $root = app()->getRuntimePath() . 'ai-assistant';
        foreach ([$root,$root . '/t' . $tenantId . '-g' . $id] as $p) {
            if (!is_dir($p) && !mkdir($p, 0700) && !is_dir($p)) {
                throw new \RuntimeException('AI_PRIVATE_STORE_UNAVAILABLE');
            }if (is_link($p) || (fileperms($p) & 0077) !== 0) {
                throw new \RuntimeException('AI_PRIVATE_STORE_UNSAFE');
            }
        }
        return $root . '/t' . $tenantId . '-g' . $id;
    }
    public function exclusive(int $tenantId, int $id, callable $work): array
    {
        $path = $this->directory($tenantId, $id) . '/input.lock';
        if (is_link($path)) {
            throw new \RuntimeException('AI_PRIVATE_LOCK_INVALID');
        }
        $lock = fopen($path, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }throw \app\common\exception\BusinessException::conflict('AI_PREPARATION_BUSY', '同一任务正在准备或领取，请沿原请求稍后恢复');
        }
        chmod($path, 0600);
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    public function acknowledged(int $tenantId, int $id, string $job, string $requestHash): bool
    {
        $dir = $this->directory($tenantId, $id);
        if (!is_file($dir . '/response-meta.json') || !is_file($dir . '/provider-response.bin')) {
            return false;
        }
        $r = AiAccess::decode(file_get_contents($dir . '/response-meta.json'));
        return ($r['job_key'] ?? null) === $job && ($r['request_hash'] ?? null) === $requestHash && hash_file('sha256', $dir . '/provider-response.bin') === ($r['response_sha256'] ?? null);
    }
    public function write(string $directory, string $name, string $bytes): void
    {
        if (!in_array($name, ['candidate.bin','response-meta.json','candidate-meta.json','collection-receipt.json'], true)) {
            throw new \InvalidArgumentException('AI_PRIVATE_SLOT_INVALID');
        }
        $tmp = $directory . '/' . $name . '.tmp';
        if (is_link($tmp) || is_link($directory . '/' . $name) || file_put_contents($tmp, $bytes, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, $directory . '/' . $name)) {
            throw new \RuntimeException('AI_PRIVATE_WRITE_FAILED');
        }
    }
}
