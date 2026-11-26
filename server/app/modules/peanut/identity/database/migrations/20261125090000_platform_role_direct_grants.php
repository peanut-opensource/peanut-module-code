<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/**
 * One Platform authorization source for all roles. Creation stays in legacy
 * mode; a separately audited, complete role migration must switch the source.
 * This migration is authored only, NOT applied to any existing application.
 */
final class PlatformRoleDirectGrants extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE pa_platform_role_authorization_source (
  id tinyint unsigned NOT NULL,
  mode varchar(16) NOT NULL DEFAULT 'legacy',
  changed_at datetime(3) DEFAULT NULL,
  PRIMARY KEY (id),
  CONSTRAINT chk_platform_role_source CHECK (mode IN ('legacy','direct'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute("INSERT INTO pa_platform_role_authorization_source (id,mode) VALUES (1,'legacy')");
        $this->execute(<<<'SQL'
CREATE TABLE pa_platform_role_direct_state (
  platform_role_id bigint unsigned NOT NULL,
  role_revision bigint unsigned NOT NULL,
  explicit_empty tinyint unsigned NOT NULL,
  catalog_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  approved_permission_keys_json json NOT NULL,
  auxiliary_permission_keys_json json NOT NULL,
  updated_at datetime(3) NOT NULL,
  PRIMARY KEY (platform_role_id),
  CONSTRAINT fk_platform_direct_role FOREIGN KEY (platform_role_id) REFERENCES pa_platform_role(id) ON DELETE CASCADE,
  CONSTRAINT chk_platform_direct_empty CHECK (explicit_empty IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE pa_platform_role_node (
  platform_role_id bigint unsigned NOT NULL,
  node_key varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (platform_role_id,node_key),
  CONSTRAINT fk_platform_node_role FOREIGN KEY (platform_role_id) REFERENCES pa_platform_role_direct_state(platform_role_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE pa_platform_role_node_api (
  platform_role_id bigint unsigned NOT NULL,
  node_key varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  api_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (platform_role_id,node_key,api_id),
  KEY idx_platform_direct_api (api_id),
  CONSTRAINT fk_platform_node_api_node FOREIGN KEY (platform_role_id,node_key) REFERENCES pa_platform_role_node(platform_role_id,node_key) ON DELETE CASCADE,
  CONSTRAINT fk_platform_node_api_endpoint FOREIGN KEY (api_id) REFERENCES pa_system_api(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Direct Platform role grants are preserved for an explicit source rollback.');
    }
}
