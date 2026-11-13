<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Active tenant dictionary values must be unique; deleted values remain historical. */
final class ActiveDictionaryValueUnique extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter || !$adapter->hasTable('pa_dict_data')) {
            throw new RuntimeException('REFERENCE_CODES_SCHEMA_UNAVAILABLE');
        }
        $pdo = $adapter->getConnection();
        $duplicate = $pdo->query(
            'SELECT tenant_id, type_id, value FROM pa_dict_data WHERE delete_time IS NULL '
            . 'GROUP BY tenant_id, type_id, value HAVING COUNT(*) > 1 LIMIT 1',
        )->fetch(PDO::FETCH_ASSOC);
        if (is_array($duplicate)) {
            throw new RuntimeException('DICTIONARY_ACTIVE_VALUE_DUPLICATE: tenant='
                . $duplicate['tenant_id'] . ', type=' . $duplicate['type_id']);
        }
        if (!$adapter->hasColumn('pa_dict_data', 'active_value')) {
            $this->execute('ALTER TABLE pa_dict_data ADD COLUMN active_value varchar(255) '
                . 'GENERATED ALWAYS AS (CASE WHEN delete_time IS NULL THEN value ELSE NULL END) STORED');
        }
        $index = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_dict_data' "
            . "AND INDEX_NAME='uk_dict_data_tenant_type_active_value'")->fetchColumn();
        if ((int) $index === 0) {
            $this->execute('ALTER TABLE pa_dict_data ADD UNIQUE KEY uk_dict_data_tenant_type_active_value '
                . '(tenant_id, type_id, active_value)');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Retain the active dictionary identity constraint.');
    }
}
