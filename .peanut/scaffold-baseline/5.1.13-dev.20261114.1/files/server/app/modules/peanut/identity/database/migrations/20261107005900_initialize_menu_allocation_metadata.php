<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Open the real empty-table allocation metadata before ReserveCustomMenuIds reads it. */
final class InitializeMenuAllocationMetadata extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitializeMenuAllocationMetadata requires the native PDO adapter.');
        }
        // MySQL can expose NULL AUTO_INCREMENT for a newly created empty InnoDB table.
        // Opening its real definition initializes that metadata without inserting a menu.
        $statement = $adapter->getConnection()->query('SHOW CREATE TABLE pa_system_menu');
        $statement->fetch(PDO::FETCH_NUM);
        $statement->closeCursor();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Opening allocation metadata does not have a reverse operation.');
    }
}
