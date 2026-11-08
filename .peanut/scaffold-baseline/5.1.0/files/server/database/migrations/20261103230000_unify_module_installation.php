<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Consolidates the former one-Module delivery ledger without changing Module IDs or business data. */
final class UnifyModuleInstallation extends Migrator
{
    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('Module installation consolidation requires native PDO.');
        }
        $pdo = $adapter->getConnection();
        $legacy = $adapter->hasTable('pa_plugin_installation');
        if ($legacy) {
            // Check all old identities before any DDL. Multi-Module historical packages need an explicit reviewed conversion.
            $invalid = $pdo->query("SELECT p.plugin_key FROM pa_plugin_installation p
                LEFT JOIN pa_plugin_module o ON o.plugin_key=p.plugin_key
                GROUP BY p.plugin_key HAVING COUNT(o.module_key)>1
                OR (COUNT(o.module_key)=1 AND MIN(o.module_key)<>p.plugin_key)")->fetchColumn();
            if ($invalid !== false) {
                throw new RuntimeException('MODULE_LEGACY_IDENTITY_CONFLICT: ' . $invalid);
            }
        }
        $columns = [
            'source' => 'varchar(255) DEFAULT NULL', 'artifact_sha256' => 'char(64) DEFAULT NULL',
            'lock_digest' => 'char(64) DEFAULT NULL', 'composer_identity_json' => 'json DEFAULT NULL',
            'npm_identity_json' => 'json DEFAULT NULL', 'frontend_identity_json' => 'json DEFAULT NULL',
            'uninstalled_at' => 'datetime(3) DEFAULT NULL',
        ];
        foreach ($columns as $name => $definition) {
            if (!$adapter->hasColumn('pa_module_installation', $name)) {
                $this->execute("ALTER TABLE pa_module_installation ADD COLUMN `{$name}` {$definition}");
            }
        }
        // Failed first installation and purged history can have no successful manifest registration.
        // NULL records that absence; it must never be replaced with a fabricated digest/schema identity.
        $this->execute('ALTER TABLE pa_module_installation MODIFY manifest_schema_version int unsigned DEFAULT NULL,
            MODIFY manifest_digest char(64) DEFAULT NULL');
        $check = $pdo->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='chk_module_installation_status'")->fetchColumn();
        if (is_string($check) && !str_contains($check, 'uninstalled')) {
            $this->execute("ALTER TABLE pa_module_installation DROP CHECK chk_module_installation_status,
                ADD CONSTRAINT chk_module_installation_status CHECK (status IN ('installing','active','upgrading','maintenance','failed','uninstalled'))");
        }
        if (!$legacy) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO pa_module_installation
                (module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,
                 installed_at,activated_at,upgraded_at,last_error_code,created_at,updated_at)
                SELECT p.plugin_key,p.installed_version,NULL,o.manifest_digest,p.status,p.revision,
                    p.installed_at,p.activated_at,p.upgraded_at,p.last_error_code,p.created_at,p.updated_at
                FROM pa_plugin_installation p LEFT JOIN pa_plugin_module o ON o.plugin_key=p.plugin_key
                LEFT JOIN pa_module_installation m ON m.module_key=p.plugin_key WHERE m.id IS NULL");
            // Module timestamps/primary keys and disabled state win; failed/uninstalled delivery state must survive.
            $pdo->exec("UPDATE pa_module_installation m JOIN pa_plugin_installation p ON p.plugin_key=m.module_key
                SET m.source=p.source, m.artifact_sha256=p.artifact_sha256, m.lock_digest=p.lock_digest,
                m.composer_identity_json=p.composer_identity_json, m.npm_identity_json=p.npm_identity_json,
                m.frontend_identity_json=p.frontend_identity_json, m.uninstalled_at=p.uninstalled_at,
                m.installed_version=p.installed_version,
                m.status=CASE WHEN p.status='active' THEN m.status ELSE p.status END,
                m.last_error_code=COALESCE(p.last_error_code,m.last_error_code),
                m.revision=GREATEST(m.revision,p.revision)");
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        $this->execute('DROP TABLE pa_plugin_module, pa_plugin_installation');
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Restore the paired pre-upgrade backup to recover the retired delivery ledger.');
    }
}
