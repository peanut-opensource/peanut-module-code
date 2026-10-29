<?php

declare(strict_types=1);

namespace app\platform\infrastructure\plugin;

use app\platform\exception\plugin\PluginPackageException;

/** Serializes development source adoption and durably rolls an interrupted source/lock pair forward. */
final class PluginPackageSourcePromoter
{
    private string $root;
    private string $state;

    public function __construct(string $serverRoot)
    {
        $this->root = rtrim(dirname($serverRoot), '/');
        if (!is_dir($serverRoot) || realpath($this->root) !== $this->root) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Use the canonical application root.');
        }
        $this->assertPath($this->root);
        $this->assertPath($serverRoot);
        $this->state = $this->root . '/.local/module-source-adoption';
        $this->assertPath($this->state);
        $this->assertPath($this->root . '/.local/module-staging');
        $this->assertPath($this->root . '/plugins.lock');
        foreach (['plugins', 'server/app/modules', 'web/src/modules', 'platform/src/modules', 'pc/modules', 'uniapp/src/modules'] as $relative) {
            $path = $this->root . '/' . $relative;
            $this->assertPath($path);
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Application package roots cannot contain symlinks.');
                }
            }
        }
    }

    /** One source writer owns verification, recovery and commit; no Runtime database is opened. */
    public function run(callable $operation): mixed
    {
        $this->directory($this->state);
        $this->assertPath($this->state . '/writer.lock');
        $handle = fopen($this->state . '/writer.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new PluginPackageException('MODULE_PACKAGE_SOURCE_BUSY', 'Application source writer is busy.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Prepare all recovery inputs before exposing a journal; after that point recovery always rolls forward. */
    public function promote(VerifiedPluginPackage $package, string $nextLock, bool $replace): void
    {
        if (file_exists($this->state . '/journal.json')) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Recover the pending source adoption first.');
        }
        $scopes = [dirname($package->manifestRelative)];
        foreach ($package->modules as $module) {
            $scopes[] = $module['backend_relative'];
            foreach ($module['frontend_contributions'] as $contribution) {
                $scopes[] = $contribution['root'];
            }
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);
        $transaction = bin2hex(random_bytes(16));
        $base = $this->state . '/' . $transaction;
        $this->directory($base);
        $entries = [];
        foreach ($scopes as $scope) {
            $target = $this->target($scope);
            $before = $this->tree($target);
            if ($before !== null && !$replace) {
                throw new PluginPackageException('MODULE_PACKAGE_TARGET_CONFLICT', 'Unowned package target already exists.');
            }
            $after = $this->tree($package->stageRoot . '/' . $scope);
            if ($after === null) {
                throw new PluginPackageException('MODULE_PACKAGE_PROMOTION_FAILED', 'Verified source is missing.');
            }
            $expected = [];
            foreach ($package->inventory as $path => $digest) {
                if (str_starts_with($path, $scope . '/')) {
                    $expected[substr($path, strlen($scope) + 1)] = $digest;
                }
            }
            ksort($expected, SORT_STRING);
            if ($after !== $expected) {
                throw new PluginPackageException('MODULE_PACKAGE_FILE_DIGEST_MISMATCH', 'Verified source identity changed.');
            }
            $payload = $base . '/payload/' . $scope;
            $this->directory($payload);
            foreach ($after as $relative => $digest) {
                $this->directory(dirname($payload . '/' . $relative));
                $bytes = file_get_contents($package->stageRoot . '/' . $scope . '/' . $relative);
                if (!is_string($bytes) || !hash_equals($digest, hash('sha256', $bytes))) {
                    throw new PluginPackageException('MODULE_PACKAGE_FILE_DIGEST_MISMATCH', 'Verified source changed before adoption.');
                }
                $this->write($payload . '/' . $relative, $bytes);
            }
            $entries[] = ['scope' => $scope, 'before' => $before, 'after' => $after];
        }
        $this->write($base . '/next.lock', $nextLock);
        $lock = is_file($this->root . '/plugins.lock') ? file_get_contents($this->root . '/plugins.lock') : null;
        $journal = ['schema_version' => 1, 'transaction' => $transaction, 'package_key' => $package->packageKey,
            'archive_sha256' => $package->archiveSha256, 'lock_before' => is_string($lock) ? hash('sha256', $lock) : null,
            'lock_after' => hash('sha256', $nextLock), 'entries' => $entries];
        $bytes = json_encode($journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->write($base . '/transaction.json', $bytes);
        $this->write($this->state . '/journal.json', $bytes);
        $this->recover();
    }

    /** Idempotently resume root renames then the lock rename; unexpected edits stop without overwriting them. */
    public function recover(): array
    {
        $journalPath = $this->state . '/journal.json';
        $this->assertPath($journalPath);
        if (!is_file($journalPath)) {
            return ['status' => 'clean'];
        }
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        if (($journal['schema_version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', (string) ($journal['transaction'] ?? '')) !== 1
            || !is_array($journal['entries'] ?? null) || $journal['entries'] === []) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Source adoption journal is invalid.');
        }
        $base = $this->state . '/' . $journal['transaction'];
        $this->assertPath($base . '/next.lock');
        $next = file_get_contents($base . '/next.lock');
        if (!is_string($next) || !hash_equals($journal['lock_after'], hash('sha256', $next))) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Source adoption lock payload changed.');
        }
        $this->assertPath($this->root . '/plugins.lock');
        $currentLock = is_file($this->root . '/plugins.lock') ? hash_file('sha256', $this->root . '/plugins.lock') : null;
        if ($currentLock !== $journal['lock_before'] && $currentLock !== $journal['lock_after']) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Application lock changed outside the pending adoption.');
        }
        // Validate every destination and payload before resuming any mutation.
        foreach ($journal['entries'] as $entry) {
            $target = $this->target($entry['scope']);
            $actual = $this->tree($target);
            $backup = $base . '/before/' . $entry['scope'];
            $saved = $this->tree($backup);
            if ($actual === $entry['after']) {
                continue;
            }
            if ($actual !== $entry['before'] && !($actual === null && $saved === $entry['before'])) {
                throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Source changed outside the pending adoption.');
            }
            if ($this->tree($base . '/payload/' . $entry['scope']) !== $entry['after']) {
                throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Source adoption payload changed.');
            }
        }
        foreach ($journal['entries'] as $entry) {
            $target = $this->target($entry['scope']);
            if ($this->tree($target) === $entry['after']) {
                continue;
            }
            $backup = $base . '/before/' . $entry['scope'];
            if (is_dir($target)) {
                $this->directory(dirname($backup));
                if (file_exists($backup) || !rename($target, $backup)) {
                    throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Cannot preserve previous source.');
                }
            }
            $this->directory(dirname($target));
            if (!rename($base . '/payload/' . $entry['scope'], $target)) {
                throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Cannot promote source payload.');
            }
        }
        $this->write($this->root . '/plugins.lock', $next);
        if (!unlink($journalPath)) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Cannot finish source adoption.');
        }
        // Preserve before/next.lock as owner-readable recovery evidence; no automatic destructive cleanup.
        return ['status' => 'recovered', 'package_key' => $journal['package_key'], 'transaction' => $journal['transaction']];
    }

    /** Prove the canonical root, then inspect only owned paths within that root. */
    private function assertPath(string $path): void
    {
        if (($path !== $this->root && !str_starts_with($path, $this->root . '/'))
            || preg_match('#/(?:\.|\.\.)(?:/|$)#D', $path) === 1) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package paths must remain inside the application root.');
        }
        // Canonical resolution detects root/ancestor aliases without probing
        // directories outside a consumer's open_basedir. Refresh it so a prior
        // cached root cannot hide a subsequent alias replacement.
        clearstatcache(true);
        if (realpath($this->root) !== $this->root) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Use the canonical application root.');
        }
        $cursor = $path;
        while (true) {
            if (is_link($cursor)) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package paths cannot contain symlinks.');
            }
            if ($cursor === $this->root) {
                return;
            }
            $cursor = dirname($cursor);
        }
    }

    /** Only canonical Module-owned directories can be changed, including when replaying a journal. */
    private function target(string $scope): string
    {
        if (preg_match('#^(?:plugins/[a-z][a-z0-9.-]*|server/app/modules/[a-z][a-z0-9-]*/[a-z][a-z0-9_]*|(?:web/src|platform/src|pc|uniapp/src)/modules/[a-z][a-z0-9-]*)$#D', $scope) !== 1) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package scope is invalid.');
        }
        $path = $this->root . '/' . $scope;
        $this->assertPath($path);
        return $path;
    }

    /** Return exact regular-file identity, rejecting directory and leaf links rather than following them. */
    private function tree(string $root): ?array
    {
        $this->assertPath($root);
        if (!file_exists($root)) {
            return null;
        }
        if (!is_dir($root)) {
            throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package root must be a directory.');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new PluginPackageException('MODULE_PACKAGE_PATH_INVALID', 'Package source must contain regular files only.');
            }
            if ($entry->isFile()) {
                $files[substr($entry->getPathname(), strlen($root) + 1)] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /** Create only checked local directories; the development owner must keep external writers stopped. */
    private function directory(string $path): void
    {
        $this->assertPath($path);
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new PluginPackageException('MODULE_PACKAGE_PROMOTION_FAILED', 'Cannot create source transaction directory.');
        }
    }

    /** Flush each payload/journal before atomic rename; journal survives process termination. */
    private function write(string $path, string $bytes): void
    {
        $this->assertPath($path);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Cannot write source transaction.');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
                throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Cannot persist source transaction.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new PluginPackageException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Cannot publish source transaction.');
        }
    }
}
