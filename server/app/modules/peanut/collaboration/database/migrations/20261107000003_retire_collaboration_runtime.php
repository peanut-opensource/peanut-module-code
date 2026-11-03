<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Retires the unused global process ledger; live protocol and Document recovery tables are unchanged. */
final class RetireCollaborationRuntime extends Migrator
{
    public function up(): void
    {
        if ($this->getAdapter()->hasTable('pa_collaboration_runtime')) {
            $this->execute('DROP TABLE pa_collaboration_runtime');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The retired process ledger can only be recovered from the pre-upgrade backup.');
    }
}
