<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Tenant customizations are separate from the platform's dictionary records. */
final class DictionaryCustomization extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter || !$adapter->hasTable('pa_system_dict_type')) {
            throw new RuntimeException('REFERENCE_CODES_SCHEMA_UNAVAILABLE');
        }
        if (!$adapter->hasColumn('pa_system_dict_type', 'tenant_mode')) {
            $this->execute("ALTER TABLE pa_system_dict_type ADD COLUMN tenant_mode "
                . "varchar(12) NOT NULL DEFAULT 'extend'");
        }
        if (!$adapter->hasTable('pa_dict_type_policy')) {
            $this->execute(<<<'SQL'
CREATE TABLE pa_dict_type_policy (
  tenant_id bigint unsigned NOT NULL,
  type_code varchar(100) NOT NULL,
  mode varchar(12) NOT NULL,
  update_time int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, type_code),
  CONSTRAINT fk_dict_type_policy_tenant FOREIGN KEY (tenant_id) REFERENCES `pa_tenant`(id) ON DELETE RESTRICT,
  CONSTRAINT chk_dict_type_policy_mode CHECK (mode IN ('system','extend','tenant'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        }
        if (!$adapter->hasTable('pa_dict_entry_override')) {
            $this->execute(<<<'SQL'
CREATE TABLE pa_dict_entry_override (
  tenant_id bigint unsigned NOT NULL,
  type_code varchar(100) NOT NULL,
  value varchar(255) NOT NULL,
  action varchar(12) NOT NULL,
  name varchar(100) DEFAULT NULL,
  update_time int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, type_code, value),
  CONSTRAINT fk_dict_entry_override_tenant FOREIGN KEY (tenant_id) REFERENCES `pa_tenant`(id) ON DELETE RESTRICT,
  CONSTRAINT chk_dict_entry_override_action CHECK (action IN ('hide','rename')),
  CONSTRAINT chk_dict_entry_override_shape CHECK (
    (action = 'hide' AND name IS NULL) OR (action = 'rename' AND name IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Tenant customizations are retained on Module downgrade.');
    }
}
