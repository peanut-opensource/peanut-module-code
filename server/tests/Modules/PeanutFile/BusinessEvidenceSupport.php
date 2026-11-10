<?php

declare(strict_types=1);

namespace BusinessEvidenceFixture;

use PeanutAdmin\FileMedia\Storage\Driver\LocalStorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageDriverCapabilities;
use PeanutAdmin\FileMedia\Storage\StorageObjectObservation;
use PeanutAdmin\FileMedia\Storage\StorageOperationReceipt;
use PeanutAdmin\FileMedia\Storage\StorageVersionCursor;
use PeanutAdmin\FileMedia\Storage\StorageVersionPage;
use app\common\exception\storage\StorageProviderException;

/** Deployment directory boundary only; no existing Identity database is contacted. */
final class Directory
{
    public function tenantStatus(int $tenantId): ?string { return in_array($tenantId, [1, 2], true) ? 'active' : null; }
}

/** Only provider fault injection; every byte operation delegates to the shipped LocalStorageDriver. */
final class Drivers
{
    public string $failure = '';
    public int $puts = 0;
    public ?\Closure $duringPut = null;

    public function __construct(public string $root) {}

    public function make(array $account, array $space): StorageDriver
    {
        return new Driver(new LocalStorageDriver($this->root . '/' . $space['local_path'], $space['access_type'] === 'private'), $this);
    }
}

final readonly class Driver implements StorageDriver
{
    public function __construct(private LocalStorageDriver $driver, private Drivers $control) {}
    public function capabilities(): StorageDriverCapabilities { return $this->driver->capabilities(); }
    public function inspect(string $key, ?string $versionId = null): StorageObjectObservation { return $this->driver->inspect($key, $versionId); }
    public function put(string $key, string $path): void
    {
        ++$this->control->puts;
        $this->control->duringPut?->__invoke($key);
        if ($this->control->failure === 'before') throw StorageProviderException::unavailable();
        $this->driver->put($key, $path);
        if ($this->control->failure === 'after') throw StorageProviderException::unavailable();
    }
    public function downloadTo(string $key, string $path, ?string $versionId = null): void { $this->driver->downloadTo($key, $path, $versionId); }
    public function localPath(string $key): string { return $this->driver->localPath($key); }
    public function delete(string $key, ?string $versionId = null): StorageOperationReceipt { return $this->driver->delete($key, $versionId); }
    public function restrictToPrivate(string $key, ?string $versionId = null): StorageOperationReceipt { return $this->driver->restrictToPrivate($key, $versionId); }
    public function listVersions(string $key, ?StorageVersionCursor $cursor = null, int $limit = 100): StorageVersionPage { return $this->driver->listVersions($key, $cursor, $limit); }
}

/** Audit sink can fail the publication transaction, without supplying any ledger state. */
final class Audit
{
    public bool $failPublication = false;
    public function appendTenantMember(mixed ...$arguments): void
    {
        if ($this->failPublication && $arguments[1] === 'file.evidence.published') {
            throw new \RuntimeException('Synthetic audit persistence failure');
        }
    }
}
