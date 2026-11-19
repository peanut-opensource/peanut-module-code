<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class ApiPermissionBundles extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE pa_system_api (
  id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  method varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  path varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  audience varchar(32) NOT NULL,
  module_key varchar(96) NOT NULL,
  access_mode varchar(64) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  default_permission_keys json NOT NULL,
  prerequisite_permission_keys json NOT NULL,
  created_at datetime(3) NOT NULL,
  updated_at datetime(3) NOT NULL,
  retired_at datetime(3) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_api_endpoint (method,path,audience),
  KEY idx_api_owner (module_key,status),
  CONSTRAINT chk_api_endpoint_status CHECK (status IN ('active','retired')),
  CONSTRAINT chk_api_endpoint_audience CHECK (audience IN ('tenant','platform','member','installation'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute('ALTER TABLE pa_permission ADD COLUMN revision bigint unsigned NOT NULL DEFAULT 1, ADD COLUMN api_configured tinyint unsigned NOT NULL DEFAULT 0');
        $this->execute(<<<'SQL'
CREATE TABLE pa_permission_api (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  permission_id bigint unsigned NOT NULL,
  api_id char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at datetime(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_permission_api (permission_id,api_id),
  KEY idx_permission_api_endpoint (api_id),
  CONSTRAINT fk_permission_api_permission FOREIGN KEY (permission_id) REFERENCES pa_permission(id) ON DELETE CASCADE,
  CONSTRAINT fk_permission_api_endpoint FOREIGN KEY (api_id) REFERENCES pa_system_api(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('API bindings are instance authorization configuration and must be preserved.');
    }
}
