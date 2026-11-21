<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Keep legacy operation history without letting it block active permission cleanup. */
final class ReleaseRetiredIdentityPermissionForeignKeys extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('Identity foreign key migration requires native PDO.');
        }

        $lookup = $adapter->getConnection()->prepare(<<<'SQL'
SELECT 1
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND COLUMN_NAME = ?
  AND REFERENCED_TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME = 'pa_permission' AND REFERENCED_COLUMN_NAME = 'id'
SQL);
        foreach ([
            ['pa_resource_operation_permission', 'fk_operation_permission_permission', 'permission_id'],
            ['pa_resource_operation_target_type', 'fk_operation_target_selection_permission', 'policy_selection_permission_id'],
        ] as [$table, $foreignKey, $column]) {
            $lookup->execute([$table, $foreignKey, $column]);
            $exists = $lookup->fetchColumn() !== false;
            $lookup->closeCursor();
            if ($exists) {
                $this->execute("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$foreignKey}`");
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Historical rows may reference permissions that have since been purged.');
    }
}
