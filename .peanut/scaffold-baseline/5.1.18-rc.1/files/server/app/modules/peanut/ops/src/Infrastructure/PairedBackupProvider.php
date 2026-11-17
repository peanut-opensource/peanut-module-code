<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Infrastructure;

use PeanutAdmin\Modules\Ops\Domain\Task\BackupRestoreProvider;

/**
 * Server-owned descriptor for the paired database/files deployment adapter.
 *
 * The logical restore target is resolved by the deployment adapter. It never
 * carries a client supplied host, database, path, command, or credential.
 */
final class PairedBackupProvider implements BackupRestoreProvider
{
    public const PROVIDER_KEY = 'peanut.paired-db-files';
    public const BACKUP_HANDLER_KEY = 'peanut.backup.create';
    public const RESTORE_HANDLER_KEY = 'peanut.restore.verify';
    public const RESTORE_TARGET_KEY = 'isolated-new-target';

    public function key(): string
    {
        return self::PROVIDER_KEY;
    }

    public function backupHandlerKey(): string
    {
        return self::BACKUP_HANDLER_KEY;
    }

    public function restoreHandlerKey(): string
    {
        return self::RESTORE_HANDLER_KEY;
    }

    public function restoreTargetKeys(): array
    {
        return [self::RESTORE_TARGET_KEY];
    }

    public function maximumAttempts(): int
    {
        // Deployment side effects are not retried until their receipt and
        // idempotency boundary are implemented by PC31/PC32.
        return 1;
    }
}
