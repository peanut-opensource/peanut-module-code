<?php

declare(strict_types=1);

require_once __DIR__ . '/PlatformRoleAuthorizationPreviewFixture.php';

final class PlatformRoleAuthorizationCatalogAuditFixture
{
    public static function install(PDO $pdo): void
    {
        PlatformRoleAuthorizationPreviewFixture::install($pdo);
        $pdo->exec(<<<'SQL'
            INSERT INTO pa_platform_role (id,"key",status,revision) VALUES
                (501,'platform.fixture.future','active',7),(502,'platform.fixture.empty','active',3);
            INSERT INTO pa_platform_role_permission VALUES (501,11),(501,12);
            SQL);
    }

    public static function enablePlatformWrites(PDO $pdo): void
    {
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn(int $precision): string => '2031-01-02 00:00:00.000', 1);
        $pdo->exec(<<<'SQL'
            ALTER TABLE pa_platform_operator ADD COLUMN display_name TEXT;
            ALTER TABLE pa_platform_operator ADD COLUMN suspended_at TEXT;
            ALTER TABLE pa_platform_operator ADD COLUMN closed_at TEXT;
            ALTER TABLE pa_platform_operator ADD COLUMN created_at TEXT;
            ALTER TABLE pa_platform_operator ADD COLUMN updated_at TEXT;
            ALTER TABLE pa_platform_role ADD COLUMN name TEXT;
            ALTER TABLE pa_platform_role ADD COLUMN description TEXT;
            ALTER TABLE pa_platform_role ADD COLUMN archived_at TEXT;
            ALTER TABLE pa_platform_role ADD COLUMN created_at TEXT;
            ALTER TABLE pa_platform_role ADD COLUMN updated_at TEXT;
            ALTER TABLE pa_platform_operator_role ADD COLUMN assigned_by_operator_id INTEGER;
            ALTER TABLE pa_platform_operator_role ADD COLUMN assigned_at TEXT;
            ALTER TABLE pa_platform_role_permission ADD COLUMN granted_at TEXT;
            CREATE TABLE pa_platform_audit_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT, action TEXT, outcome TEXT,
                reason_code TEXT, operator_id INTEGER, account_id INTEGER, target_type TEXT, target_id TEXT,
                request_id TEXT, operation_id TEXT, ip_address TEXT, user_agent_hash TEXT, before_json TEXT,
                after_json TEXT, metadata_json TEXT, occurred_at TEXT
            );
            SQL);
    }

    public static function installOptionalModule(PDO $pdo, string $digest): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE pa_module_installation (
                module_key TEXT PRIMARY KEY, installed_version TEXT, manifest_schema_version INTEGER,
                manifest_digest TEXT, status TEXT, revision INTEGER
            );
            SQL);
        $pdo->prepare("INSERT INTO pa_module_installation VALUES ('fixture.optional','1.0.0',1,?,'active',1)")->execute([$digest]);
    }

    public static function enableTenantRoles(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE pa_tenant (id INTEGER PRIMARY KEY, status TEXT);
            CREATE TABLE pa_tenant_license (id INTEGER PRIMARY KEY, tenant_id INTEGER, plan_id INTEGER);
            CREATE TABLE pa_plan (id INTEGER PRIMARY KEY, code TEXT, name TEXT, revision INTEGER);
            CREATE TABLE pa_role (
                id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, "key" TEXT, name TEXT, description TEXT,
                is_builtin INTEGER DEFAULT 0, status TEXT, authorization_revision INTEGER, created_at TEXT
            );
            CREATE TABLE pa_role_permission (tenant_id INTEGER, role_id INTEGER, permission_id INTEGER);
            INSERT INTO pa_tenant VALUES (71,'active'),(72,'active'),(73,'active');
            INSERT INTO pa_role (tenant_id,id,"key",name,is_builtin,status,authorization_revision) VALUES
                (71,501,'tenant.fixture.a','Tenant A',0,'active',7),
                (72,701,'core.tenant-owner','Tenant Owner',1,'active',7);
            INSERT INTO pa_role_permission VALUES (71,501,21),(72,701,21);
            SQL);
    }
}
