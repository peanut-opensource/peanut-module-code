<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

final class TenantPlanLicenses extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE pa_plan (
 id bigint unsigned NOT NULL AUTO_INCREMENT, code varchar(64) NOT NULL, name varchar(160) NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active', revision bigint unsigned NOT NULL DEFAULT 1,
 created_at datetime(3) NOT NULL, updated_at datetime(3) NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY uk_plan_code (code),
 CONSTRAINT chk_plan_status CHECK (status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE pa_plan_module (
 plan_id bigint unsigned NOT NULL, module_key varchar(96) NOT NULL,
 PRIMARY KEY (plan_id,module_key), KEY idx_plan_module (module_key),
 CONSTRAINT fk_plan_module_plan FOREIGN KEY (plan_id) REFERENCES pa_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE pa_plan_permission (
 plan_id bigint unsigned NOT NULL, permission_key varchar(160) NOT NULL,
 PRIMARY KEY (plan_id,permission_key), KEY idx_plan_permission (permission_key),
 CONSTRAINT fk_plan_permission_plan FOREIGN KEY (plan_id) REFERENCES pa_plan(id) ON DELETE CASCADE,
 CONSTRAINT fk_plan_permission_permission FOREIGN KEY (permission_key) REFERENCES pa_permission(`key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE pa_tenant_license (
 id bigint unsigned NOT NULL AUTO_INCREMENT, tenant_id bigint unsigned NOT NULL, plan_id bigint unsigned NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active', effective_at datetime(3) NOT NULL, expires_at datetime(3) DEFAULT NULL,
 revision bigint unsigned NOT NULL DEFAULT 1, created_at datetime(3) NOT NULL, updated_at datetime(3) NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY uk_tenant_license (tenant_id), KEY idx_tenant_license_plan (plan_id),
 CONSTRAINT fk_tenant_license_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant(id) ON DELETE RESTRICT,
 CONSTRAINT fk_tenant_license_plan FOREIGN KEY (plan_id) REFERENCES pa_plan(id) ON DELETE RESTRICT,
 CONSTRAINT chk_tenant_license_status CHECK (status IN ('active','suspended')),
 CONSTRAINT chk_tenant_license_dates CHECK (expires_at IS NULL OR expires_at > effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL);
        // This application is not in use. The approved final schema retires the old entitlement/config table.
        $this->execute('DROP TABLE pa_tenant_module');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Tenant license state must not be converted back to direct module grants.');
    }
}
