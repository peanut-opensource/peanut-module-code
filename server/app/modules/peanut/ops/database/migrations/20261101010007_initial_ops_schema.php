<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialOpsSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_ops_task' => <<<'SQL'
CREATE TABLE `pa_ops_task` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` json NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `attempt_count` tinyint unsigned NOT NULL DEFAULT '0',
  `max_attempts` tinyint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `last_error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `idempotency_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `concurrency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `submitted_by_operator_id` bigint unsigned NOT NULL,
  `available_at` datetime(3) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_task_key` (`task_key`),
  UNIQUE KEY `uk_ops_task_idempotency` (`submitted_by_operator_id`,`idempotency_digest`),
  KEY `idx_ops_task_status` (`status`,`available_at`,`id`),
  KEY `idx_ops_task_concurrency` (`concurrency_key`,`status`,`id`),
  CONSTRAINT `fk_ops_task_operator` FOREIGN KEY (`submitted_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_task_attempts` CHECK (((`max_attempts` between 1 and 10) and (`attempt_count` <= `max_attempts`))),
  CONSTRAINT `chk_ops_task_completion` CHECK (((`status` in (_utf8mb4'succeeded',_utf8mb4'dead',_utf8mb4'cancelled')) = (`completed_at` is not null))),
  CONSTRAINT `chk_ops_task_status` CHECK ((`status` in (_utf8mb4'queued',_utf8mb4'running',_utf8mb4'succeeded',_utf8mb4'dead',_utf8mb4'cancelled'))),
  CONSTRAINT `chk_ops_task_type` CHECK ((`task_type` in (_utf8mb4'ops.backup.create',_utf8mb4'ops.restore.verify',_utf8mb4'ops.upgrade.execute',_utf8mb4'ops.module.execute')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_maintenance_window' => <<<'SQL'
CREATE TABLE `pa_ops_maintenance_window` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `maintenance_key` char(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(16) NOT NULL,
  `reason_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `starts_at` datetime(3) NOT NULL,
  `ends_at` datetime(3) NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `idempotency_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_operator_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `closed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_maintenance_key` (`maintenance_key`),
  UNIQUE KEY `uk_ops_maintenance_idempotency` (`created_by_operator_id`,`idempotency_digest`),
  KEY `idx_ops_maintenance_state` (`state`,`starts_at`,`id`),
  CONSTRAINT `fk_ops_maintenance_operator` FOREIGN KEY (`created_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_maintenance_range` CHECK ((`ends_at` > `starts_at`)),
  CONSTRAINT `chk_ops_maintenance_state` CHECK ((`state` in (_utf8mb4'scheduled',_utf8mb4'active',_utf8mb4'closed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_module_request' => <<<'SQL'
CREATE TABLE `pa_ops_module_request` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_key` char(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `environment` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_resource_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `delivery_resource_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `package_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `archive_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `signature_key_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `confirm_plan_json` json DEFAULT NULL,
  `confirm_plan_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `request_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `claimed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_module_request_key` (`request_key`),
  UNIQUE KEY `uk_ops_module_request_sha` (`request_sha256`),
  CONSTRAINT `chk_ops_module_request_archive` CHECK (((`operation` in (_utf8mb4'install',_utf8mb4'update')) = (`archive_sha256` is not null))),
  CONSTRAINT `chk_ops_module_request_operation` CHECK ((`operation` in (_utf8mb4'install',_utf8mb4'update',_utf8mb4'retire',_utf8mb4'purge'))),
  CONSTRAINT `chk_ops_module_request_plan` CHECK (((`operation` in (_ascii'retire',_ascii'purge')) = ((`confirm_plan_json` is not null) and (`confirm_plan_sha256` is not null)))),
  CONSTRAINT `chk_ops_module_request_state` CHECK ((`state` in (_ascii'prepared',_ascii'claimed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_backup_evidence' => <<<'SQL'
CREATE TABLE `pa_ops_backup_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `backup_reference_key` char(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_commit` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_tree` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_release_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `consistency_started_at` datetime(3) NOT NULL,
  `consistency_completed_at` datetime(3) NOT NULL,
  `verified_at` datetime(3) NOT NULL,
  `manifest_json` json NOT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_backup_reference` (`backup_reference_key`),
  UNIQUE KEY `uk_ops_backup_task` (`task_key`),
  KEY `idx_ops_backup_verified` (`verified_at`,`id`),
  CONSTRAINT `fk_ops_backup_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_backup_provider` CHECK ((`provider_key` = _utf8mb4'peanut.paired-db-files')),
  CONSTRAINT `chk_ops_backup_window` CHECK ((`consistency_completed_at` >= `consistency_started_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_upgrade_step' => <<<'SQL'
CREATE TABLE `pa_ops_upgrade_step` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `step_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `step_order` tinyint unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `output_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `started_at` datetime(3) DEFAULT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_upgrade_step` (`task_key`,`step_key`),
  UNIQUE KEY `uk_ops_upgrade_step_order` (`task_key`,`step_order`),
  CONSTRAINT `fk_ops_upgrade_step_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_upgrade_step_error` CHECK (((`status` = _utf8mb4'failed') = (`last_error_code` is not null))),
  CONSTRAINT `chk_ops_upgrade_step_key` CHECK ((`step_key` in (_utf8mb4'preflight',_utf8mb4'backup',_utf8mb4'restore_verification',_utf8mb4'maintenance',_utf8mb4'deployment',_utf8mb4'smoke',_utf8mb4'recovery_pointer'))),
  CONSTRAINT `chk_ops_upgrade_step_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'running',_utf8mb4'succeeded',_utf8mb4'failed'))),
  CONSTRAINT `chk_ops_upgrade_step_time` CHECK ((((`status` = _utf8mb4'pending') and (`started_at` is null) and (`completed_at` is null)) or ((`status` = _utf8mb4'running') and (`started_at` is not null) and (`completed_at` is null)) or ((`status` in (_utf8mb4'succeeded',_utf8mb4'failed')) and (`started_at` is not null) and (`completed_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_restore_evidence' => <<<'SQL'
CREATE TABLE `pa_ops_restore_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_reference_key` char(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_commit` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_tree` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_deployment_resource_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_database_resource_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_runtime_resource_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `table_count` int unsigned NOT NULL,
  `schema_migration_count` int unsigned NOT NULL,
  `critical_table_count` int unsigned NOT NULL,
  `account_count` bigint unsigned NOT NULL,
  `tenant_count` bigint unsigned NOT NULL,
  `tenant_member_count` bigint unsigned NOT NULL,
  `storage_file_count` bigint unsigned NOT NULL,
  `storage_bytes` bigint unsigned NOT NULL,
  `protected_runtime_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `verified_at` datetime(3) NOT NULL,
  `evidence_json` json NOT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_restore_task` (`task_key`),
  KEY `idx_ops_restore_verified` (`verified_at`,`id`),
  KEY `idx_ops_restore_backup` (`backup_reference_key`),
  CONSTRAINT `fk_ops_restore_backup` FOREIGN KEY (`backup_reference_key`) REFERENCES `pa_ops_backup_evidence` (`backup_reference_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_restore_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_restore_critical_tables` CHECK ((`critical_table_count` = 6)),
  CONSTRAINT `chk_ops_restore_provider` CHECK ((`provider_key` = _utf8mb4'peanut.paired-db-files')),
  CONSTRAINT `chk_ops_restore_target` CHECK ((`target_key` = _utf8mb4'isolated-new-target'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_module_execution' => <<<'SQL'
CREATE TABLE `pa_ops_module_execution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_key` char(39) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_step` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `backup_reference_key` char(39) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `restore_task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `restore_evidence_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `maintenance_key` char(44) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `maintenance_revision` bigint unsigned DEFAULT NULL,
  `operation_result_json` json DEFAULT NULL,
  `operation_result_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `recovery_pointer_json` json DEFAULT NULL,
  `recovery_pointer_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_module_execution_task` (`task_key`),
  UNIQUE KEY `uk_ops_module_execution_request` (`request_key`),
  KEY `fk_ops_module_execution_backup_task` (`backup_task_key`),
  KEY `fk_ops_module_execution_backup_reference` (`backup_reference_key`),
  KEY `fk_ops_module_execution_restore_task` (`restore_task_key`),
  KEY `fk_ops_module_execution_maintenance` (`maintenance_key`),
  CONSTRAINT `fk_ops_module_execution_backup_reference` FOREIGN KEY (`backup_reference_key`) REFERENCES `pa_ops_backup_evidence` (`backup_reference_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_backup_task` FOREIGN KEY (`backup_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_maintenance` FOREIGN KEY (`maintenance_key`) REFERENCES `pa_ops_maintenance_window` (`maintenance_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_request` FOREIGN KEY (`request_key`) REFERENCES `pa_ops_module_request` (`request_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_restore_task` FOREIGN KEY (`restore_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_module_execution_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_module_execution_completion` CHECK (((`current_step` = _utf8mb4'completed') = (`completed_at` is not null))),
  CONSTRAINT `chk_ops_module_execution_recovery` CHECK (((`recovery_pointer_json` is null) = (`recovery_pointer_sha256` is null))),
  CONSTRAINT `chk_ops_module_execution_result` CHECK (((`operation_result_json` is null) = (`operation_result_sha256` is null))),
  CONSTRAINT `chk_ops_module_execution_step` CHECK ((`current_step` in (_utf8mb4'preflight',_utf8mb4'backup',_utf8mb4'restore_verification',_utf8mb4'maintenance',_utf8mb4'execution',_utf8mb4'smoke',_utf8mb4'recovery_pointer',_utf8mb4'completed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_ops_upgrade_execution' => <<<'SQL'
CREATE TABLE `pa_ops_upgrade_execution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_commit` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_tree` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_release_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `source_application_manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_release_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_commit` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_tree` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_descriptor_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_step` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `backup_reference_key` char(39) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `restore_task_key` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `restore_evidence_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `maintenance_key` char(44) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `maintenance_revision` bigint unsigned DEFAULT NULL,
  `recovery_pointer_json` json DEFAULT NULL,
  `recovery_pointer_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ops_upgrade_task` (`task_key`),
  KEY `idx_ops_upgrade_target` (`target_commit`,`created_at`,`id`),
  KEY `fk_ops_upgrade_backup_task` (`backup_task_key`),
  KEY `fk_ops_upgrade_backup_reference` (`backup_reference_key`),
  KEY `fk_ops_upgrade_restore_task` (`restore_task_key`),
  KEY `fk_ops_upgrade_maintenance` (`maintenance_key`),
  CONSTRAINT `fk_ops_upgrade_backup_reference` FOREIGN KEY (`backup_reference_key`) REFERENCES `pa_ops_backup_evidence` (`backup_reference_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_backup_task` FOREIGN KEY (`backup_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_maintenance` FOREIGN KEY (`maintenance_key`) REFERENCES `pa_ops_maintenance_window` (`maintenance_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_restore_task` FOREIGN KEY (`restore_task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ops_upgrade_task` FOREIGN KEY (`task_key`) REFERENCES `pa_ops_task` (`task_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ops_upgrade_completion` CHECK (((`current_step` = _utf8mb4'completed') = (`completed_at` is not null))),
  CONSTRAINT `chk_ops_upgrade_recovery_pointer` CHECK (((`recovery_pointer_json` is null) = (`recovery_pointer_sha256` is null))),
  CONSTRAINT `chk_ops_upgrade_step` CHECK ((`current_step` in (_utf8mb4'preflight',_utf8mb4'backup',_utf8mb4'restore_verification',_utf8mb4'maintenance',_utf8mb4'deployment',_utf8mb4'smoke',_utf8mb4'recovery_pointer',_utf8mb4'completed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialOpsSchema requires the native PDO adapter.');
        }
        $pdo = $adapter->getConnection();
        $pending = [];
        foreach (self::CREATE_SQL as $table => $definition) {
            if (!$adapter->hasTable($table)) {
                $pending[] = $definition;
                continue;
            }
            $statement = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $existing = $statement->fetch(PDO::FETCH_NUM);
            $statement->closeCursor();
            if (!is_array($existing) || !is_string($existing[1] ?? null)
                || $this->schemaDefinition($existing[1]) !== $this->schemaDefinition($definition)) {
                throw new RuntimeException('INITIAL_SCHEMA_MISMATCH: ' . $table);
            }
        }
        // Check existing definitions before creating missing tables in foreign-key order.
        foreach ($pending as $definition) {
            $this->execute($definition);
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Module data is retained. This initial schema migration cannot be rolled back.');
    }

    private function schemaDefinition(string $definition): string
    {
        // AUTO_INCREMENT is live row state rather than a schema difference.
        return (string) preg_replace('/ AUTO_INCREMENT=\d+(?= |$)/', '', rtrim(trim($definition), ';'));
    }
}
