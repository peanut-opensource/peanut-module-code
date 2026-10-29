<?php

declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\validation\scaffold\ScaffoldPathGuard;
use RuntimeException;

final class ScaffoldUpgradeLedger
{
    public function __construct(private readonly string $path) {}

    /** @param array<string,mixed> $entry */
    public function append(array $entry): void
    {
        ScaffoldPathGuard::ensureDirectory(dirname($this->path));
        $handle = fopen($this->path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('SCAFFOLD_LEDGER_OPEN_FAILED');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('SCAFFOLD_LEDGER_LOCK_FAILED');
            }
            $entry = ['recorded_at' => gmdate('c')] + $entry;
            $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json . "\n") !== strlen($json) + 1 || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('SCAFFOLD_LEDGER_APPEND_FAILED');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $directory = fopen(dirname($this->path), 'r');
        if ($directory === false || !fsync($directory)) {
            throw new RuntimeException('SCAFFOLD_LEDGER_SYNC_FAILED');
        }
        fclose($directory);
    }

    /** @return list<array<string,mixed>> */
    public function entries(string $candidate): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $result = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('SCAFFOLD_LEDGER_CORRUPT', 0, $exception);
            }
            if (is_array($entry) && ($entry['candidate'] ?? null) === $candidate) {
                $result[] = $entry;
            }
        }
        return $result;
    }
}
