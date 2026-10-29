<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialImportExportSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_import_export_operation' => <<<'SQL'
CREATE TABLE `pa_import_export_operation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operation_key` varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `provider_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `direction` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `format` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'csv',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `input_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `result_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `error_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `task_job_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `schema_revision` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mapping_json` json NOT NULL,
  `processed_rows` int unsigned NOT NULL DEFAULT '0',
  `accepted_rows` int unsigned NOT NULL DEFAULT '0',
  `rejected_rows` int unsigned NOT NULL DEFAULT '0',
  `total_rows` int unsigned NOT NULL DEFAULT '0',
  `attempt_number` smallint unsigned NOT NULL DEFAULT '0',
  `idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `last_error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `retention_until` datetime(3) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_key` (`operation_key`),
  UNIQUE KEY `uk_import_export_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_import_export_idempotency` (`tenant_id`,`created_by_member_id`,`direction`,`provider_key`,`idempotency_key_hash`),
  KEY `idx_import_export_status` (`tenant_id`,`status`,`id`),
  KEY `idx_import_export_task` (`tenant_id`,`task_job_key`),
  KEY `idx_import_export_retention` (`status`,`retention_until`,`id`),
  CONSTRAINT `fk_import_export_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_import_export_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_attempt` CHECK ((`attempt_number` <= 10)),
  CONSTRAINT `chk_import_export_completion` CHECK (((`status` in (_utf8mb4'succeeded',_utf8mb4'failed',_utf8mb4'cancelled',_utf8mb4'expired')) = (`completed_at` is not null))),
  CONSTRAINT `chk_import_export_direction` CHECK ((`direction` in (_utf8mb4'import',_utf8mb4'export'))),
  CONSTRAINT `chk_import_export_files` CHECK ((((`result_file_key` is null) or regexp_like(`result_file_key`,_utf8mb4'^file_[0-9a-f]{32}$')) and ((`error_file_key` is null) or regexp_like(`error_file_key`,_utf8mb4'^file_[0-9a-f]{32}$')))),
  CONSTRAINT `chk_import_export_format` CHECK ((`format` = _utf8mb4'csv')),
  CONSTRAINT `chk_import_export_hashes` CHECK ((regexp_like(`idempotency_key_hash`,_utf8mb4'^[0-9a-f]{64}$') and regexp_like(`request_hash`,_utf8mb4'^[0-9a-f]{64}$'))),
  CONSTRAINT `chk_import_export_input` CHECK (((`direction` = _utf8mb4'import') = (`input_file_key` is not null))),
  CONSTRAINT `chk_import_export_key` CHECK (regexp_like(`operation_key`,_utf8mb4'^iox_[0-9a-f]{32}$')),
  CONSTRAINT `chk_import_export_progress` CHECK ((((`accepted_rows` + `rejected_rows`) <= `processed_rows`) and (`processed_rows` <= 100000) and (`total_rows` <= 100000))),
  CONSTRAINT `chk_import_export_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_import_export_status` CHECK ((`status` in (_utf8mb4'queued',_utf8mb4'running',_utf8mb4'cancel_requested',_utf8mb4'succeeded',_utf8mb4'failed',_utf8mb4'cancelled',_utf8mb4'expired'))),
  CONSTRAINT `chk_import_export_task` CHECK (((`task_job_key` is null) or regexp_like(`task_job_key`,_utf8mb4'^job_[0-9a-f]{32}$')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_import_export_row_error' => <<<'SQL'
CREATE TABLE `pa_import_export_row_error` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `row_number` int unsigned NOT NULL,
  `column_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `column_key_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (coalesce(`column_key`,_ascii'')) STORED,
  `error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_import_export_row_error` (`tenant_id`,`operation_id`,`row_number`,`column_key_unique`,`error_code`),
  KEY `idx_import_export_row_error` (`tenant_id`,`operation_id`,`row_number`,`id`),
  CONSTRAINT `fk_import_export_row_operation` FOREIGN KEY (`tenant_id`, `operation_id`) REFERENCES `pa_import_export_operation` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_export_column` CHECK (((`column_key` is null) or regexp_like(`column_key`,_utf8mb4'^[a-z][a-z0-9_]{0,63}$'))),
  CONSTRAINT `chk_import_export_error_code` CHECK (regexp_like(`error_code`,_utf8mb4'^[A-Z][A-Z0-9_]{2,63}$')),
  CONSTRAINT `chk_import_export_row_number` CHECK (((`row_number` >= 2) and (`row_number` <= 100001)))
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
            throw new RuntimeException('InitialImportExportSchema requires the native PDO adapter.');
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
