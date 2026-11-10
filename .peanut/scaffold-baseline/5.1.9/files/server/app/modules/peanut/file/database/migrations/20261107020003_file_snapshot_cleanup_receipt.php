<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Archive intent alone cannot prove provider deletion, especially without a capture row. */
final class FileSnapshotCleanupReceipt extends Migrator
{
    public function up(): void
    {
        $this->execute('ALTER TABLE pa_file_object ADD COLUMN snapshot_cleanup_acknowledged_at datetime(3) DEFAULT NULL');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Removing provider cleanup receipts would requeue completed orphan snapshots.');
    }
}
