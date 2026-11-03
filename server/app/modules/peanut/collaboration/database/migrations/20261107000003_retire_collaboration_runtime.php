<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Retires the unused global process ledger; live protocol and Document recovery tables are unchanged. */
final class RetireCollaborationRuntime extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('RetireCollaborationRuntime requires the native PDO adapter.');
        }
        if ($adapter->hasTable('pa_collaboration_runtime')) {
            $this->execute('DROP TABLE pa_collaboration_runtime');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The retired process ledger can only be recovered from the pre-upgrade backup.');
    }
}
