<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialDocumentSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_document' => <<<'SQL'
CREATE TABLE `pa_document` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `owner_module_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_object_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_object_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `slot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_type` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schema_version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `auth_revision` bigint unsigned NOT NULL DEFAULT '1',
  `state_seq` bigint unsigned NOT NULL DEFAULT '0',
  `collaboration_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `lease_epoch` bigint unsigned NOT NULL DEFAULT '0',
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `create_time` bigint unsigned NOT NULL,
  `update_time` bigint unsigned NOT NULL,
  `archived_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_owner_slot` (`tenant_id`,`owner_module_key`,`owner_object_type`,`owner_object_id`,`slot`),
  UNIQUE KEY `uk_document_tenant_id` (`tenant_id`,`id`),
  KEY `idx_document_state` (`tenant_id`,`status`),
  KEY `fk_document_creator` (`tenant_id`,`created_by_member_id`),
  KEY `fk_document_updater` (`tenant_id`,`updated_by_member_id`),
  CONSTRAINT `fk_document_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
  CONSTRAINT `fk_document_updater` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_document_collaboration` CHECK (((`collaboration_enabled` in (0,1)) and ((`content_kind` = _utf8mb4'rich_text') or (`collaboration_enabled` = 0)))),
  CONSTRAINT `chk_document_kind` CHECK ((`content_kind` in (_utf8mb4'rich_text',_utf8mb4'file_resource'))),
  CONSTRAINT `chk_document_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'archiving',_utf8mb4'archived',_utf8mb4'blocked_recovery')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_state' => <<<'SQL'
CREATE TABLE `pa_document_state` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `yjs_state_binary` mediumblob,
  `state_seq` bigint unsigned NOT NULL,
  `state_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `projected_json` json NOT NULL,
  `projection_schema_version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `persisted_at` datetime(3) NOT NULL,
  `last_store_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_state` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_document_state_owner` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_revision' => <<<'SQL'
CREATE TABLE `pa_document_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `revision_no` bigint unsigned NOT NULL,
  `source_state_seq` bigint unsigned NOT NULL,
  `content_kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `yjs_snapshot` mediumblob,
  `content_snapshot_json` json DEFAULT NULL,
  `immutable_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `immutable_file_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `immutable_file_mime_type` varchar(127) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_revision` (`tenant_id`,`document_id`,`revision_no`),
  KEY `fk_document_revision_creator` (`tenant_id`,`created_by_member_id`),
  KEY `fk_document_revision_file` (`immutable_file_key`),
  CONSTRAINT `fk_document_revision_creator` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_revision_file` FOREIGN KEY (`immutable_file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_revision_owner` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `chk_document_revision_kind` CHECK ((((`content_kind` = _utf8mb4'rich_text') and (`content_snapshot_json` is not null) and (`immutable_file_key` is null)) or ((`content_kind` = _utf8mb4'file_resource') and (`immutable_file_key` is not null) and (`immutable_file_sha256` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_file_ref' => <<<'SQL'
CREATE TABLE `pa_document_file_ref` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `variant_key` varchar(32) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `usage` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `derived_state_seq` bigint unsigned NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_file_ref` (`tenant_id`,`document_id`,`file_key`,`usage`),
  KEY `fk_document_file_ref_object` (`file_key`),
  CONSTRAINT `fk_document_file_ref_object` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_file_ref_owner` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_resource_binding' => <<<'SQL'
CREATE TABLE `pa_document_resource_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `primary_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `verified_mime_type` varchar(127) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `source_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `source_file_revision` bigint unsigned NOT NULL,
  `state_seq` bigint unsigned NOT NULL,
  `bound_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_resource_binding` (`tenant_id`,`document_id`),
  KEY `fk_document_binding_object` (`primary_file_key`),
  CONSTRAINT `fk_document_binding_object` FOREIGN KEY (`primary_file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_document_binding_owner` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_operation' => <<<'SQL'
CREATE TABLE `pa_document_operation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `operation_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `result_json` json NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `phase` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `initiator_json` json DEFAULT NULL,
  `command_input_json` json DEFAULT NULL,
  `barrier_plan_json` json DEFAULT NULL,
  `business_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `released_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_operation` (`tenant_id`,`document_id`,`operation_id`),
  CONSTRAINT `fk_document_operation_owner` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `chk_document_operation_phase` CHECK ((`phase` in (_utf8mb4'prepared',_utf8mb4'attention',_utf8mb4'completed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialDocumentSchema requires the native PDO adapter.');
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
        $definition = (string) preg_replace('/ AUTO_INCREMENT=\d+(?= |$)/', '', rtrim(trim($definition), ';'));
        $declarations = explode("\n", $definition);
        $header = array_shift($declarations);
        $options = array_pop($declarations);
        foreach ($declarations as &$declaration) {
            $declaration = rtrim(trim($declaration), ',');
            // The retained collation already specifies utf8mb4 for these character columns.
            $declaration = (string) preg_replace(
                '/^(`[^`]+` (?:var)?char\(\d+\)) CHARACTER SET utf8mb4 COLLATE (utf8mb4_[a-z0-9_]+)(?= )/',
                '$1 COLLATE $2',
                $declaration
            );
        }
        unset($declaration);
        // Compare named declarations without changing the column order inside any index or constraint.
        sort($declarations, SORT_STRING);
        return $header . "\n" . implode("\n", $declarations) . "\n" . $options;
    }
}
