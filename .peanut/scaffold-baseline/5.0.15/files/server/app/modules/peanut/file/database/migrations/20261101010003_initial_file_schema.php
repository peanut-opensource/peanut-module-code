<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialFileSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_file_cate' => <<<'SQL'
CREATE TABLE `pa_file_cate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` int unsigned NOT NULL DEFAULT '0' COMMENT '父级ID',
  `type` tinyint NOT NULL DEFAULT '10' COMMENT '类型：10图片 20视频 30文件',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '分类名称',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_cate_tenant_id` (`tenant_id`,`id`),
  KEY `idx_type` (`type`),
  KEY `idx_file_cate_tenant_type_parent` (`tenant_id`,`type`,`pid`,`id`),
  CONSTRAINT `fk_file_cate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文件分类';
SQL,
        'pa_storage_account' => <<<'SQL'
CREATE TABLE `pa_storage_account` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `account_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `driver` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(128) NOT NULL,
  `credential_ciphertext` mediumtext,
  `credential_key_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `credential_rotated_at` datetime(3) DEFAULT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_storage_account_key` (`account_key`),
  CONSTRAINT `chk_storage_account_credentials` CHECK ((((`driver` = _utf8mb4'local') and (`credential_ciphertext` is null) and (`credential_key_version` is null) and (`credential_rotated_at` is null)) or ((`driver` <> _utf8mb4'local') and (`credential_ciphertext` is not null) and (`credential_key_version` is not null) and (`credential_rotated_at` is not null)))),
  CONSTRAINT `chk_storage_account_driver` CHECK ((`driver` in (_utf8mb4'local',_utf8mb4'qiniu',_utf8mb4'aliyun',_utf8mb4'qcloud'))),
  CONSTRAINT `chk_storage_account_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_delivery_nonce' => <<<'SQL'
CREATE TABLE `pa_file_delivery_nonce` (
  `token_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  `consumed_at` datetime(3) NOT NULL,
  PRIMARY KEY (`token_id_hash`),
  KEY `idx_file_delivery_nonce_expiry` (`expires_at`),
  CONSTRAINT `chk_file_delivery_nonce_hash` CHECK (regexp_like(`token_id_hash`,_utf8mb4'^[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_delivery_grant' => <<<'SQL'
CREATE TABLE `pa_file_delivery_grant` (
  `token_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_tenant_id` bigint unsigned NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_revision` bigint unsigned NOT NULL,
  `visibility` varchar(8) NOT NULL,
  `operation` varchar(32) NOT NULL,
  `subject_json` json DEFAULT NULL,
  `authorization_revision` char(64) DEFAULT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `reference_digest` varchar(128) DEFAULT NULL,
  `source_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_revision` bigint unsigned NOT NULL,
  `source_visibility` varchar(8) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  PRIMARY KEY (`token_id_hash`),
  KEY `idx_file_grant_expiry` (`expires_at`),
  CONSTRAINT `chk_file_grant_operation` CHECK ((`operation` in (_utf8mb4'public',_utf8mb4'preview',_utf8mb4'download_original'))),
  CONSTRAINT `chk_file_grant_source_visibility` CHECK ((`source_visibility` in (_utf8mb4'public',_utf8mb4'private'))),
  CONSTRAINT `chk_file_grant_visibility` CHECK ((`visibility` in (_utf8mb4'public',_utf8mb4'private')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_visibility_batch' => <<<'SQL'
CREATE TABLE `pa_file_visibility_batch` (
  `batch_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `request_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `confirmation_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `manifest_json` json NOT NULL,
  `status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`batch_key`),
  UNIQUE KEY `uk_file_visibility_owner_key` (`tenant_id`,`batch_key`),
  UNIQUE KEY `uk_file_visibility_request` (`tenant_id`,`request_key`),
  KEY `idx_file_visibility_status` (`tenant_id`,`status`,`created_at`),
  CONSTRAINT `chk_file_visibility_batch_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'running',_utf8mb4'completed',_utf8mb4'attention'))),
  CONSTRAINT `chk_file_visibility_confirmation_hash` CHECK (regexp_like(`confirmation_sha256`,_utf8mb4'^[a-f0-9]{64}$')),
  CONSTRAINT `chk_file_visibility_request_hash` CHECK (regexp_like(`request_sha256`,_utf8mb4'^[a-f0-9]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_storage_space' => <<<'SQL'
CREATE TABLE `pa_storage_space` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `space_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `name` varchar(128) NOT NULL,
  `access_type` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bucket` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `region` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `endpoint` varchar(500) DEFAULT NULL,
  `access_domain` varchar(500) DEFAULT NULL,
  `local_path` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_storage_space_key` (`space_key`),
  UNIQUE KEY `uk_storage_space_location` (`account_id`,`bucket`,`region`),
  CONSTRAINT `fk_storage_space_account` FOREIGN KEY (`account_id`) REFERENCES `pa_storage_account` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_storage_space_access` CHECK ((`access_type` in (_utf8mb4'public',_utf8mb4'private'))),
  CONSTRAINT `chk_storage_space_local_path` CHECK (((`local_path` is null) or (`local_path` in (_utf8mb4'public/storage',_utf8mb4'private/storage')))),
  CONSTRAINT `chk_storage_space_shape` CHECK ((((`local_path` is not null) and (`bucket` is null) and (`region` is null) and (`endpoint` is null)) or ((`local_path` is null) and (`bucket` is not null)))),
  CONSTRAINT `chk_storage_space_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'read_only',_utf8mb4'disabled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_storage_route' => <<<'SQL'
CREATE TABLE `pa_storage_route` (
  `route_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `access_type` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `space_id` bigint unsigned NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`route_key`),
  KEY `idx_storage_route_space` (`space_id`),
  CONSTRAINT `fk_storage_route_space` FOREIGN KEY (`space_id`) REFERENCES `pa_storage_space` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_storage_route_access` CHECK ((`access_type` in (_utf8mb4'public',_utf8mb4'private'))),
  CONSTRAINT `chk_storage_route_key` CHECK (regexp_like(`route_key`,_utf8mb4'^[a-z][a-z0-9._-]{2,95}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_object' => <<<'SQL'
CREATE TABLE `pa_file_object` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `purpose` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'export.csv',
  `access_type` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'private',
  `storage_space_id` bigint unsigned NOT NULL,
  `object_key` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `disposition` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'attachment',
  `original_name` varchar(255) NOT NULL,
  `media_type` varchar(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ready',
  `created_by_member_id` bigint unsigned DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `archived_at` datetime(3) DEFAULT NULL,
  `visibility_batch_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_object_key` (`file_key`),
  UNIQUE KEY `uk_file_object_storage` (`storage_space_id`,`object_key`),
  UNIQUE KEY `uk_file_object_tenant_key` (`tenant_id`,`file_key`),
  KEY `idx_file_object_purpose` (`tenant_id`,`purpose`,`status`,`id`),
  KEY `idx_file_object_status` (`tenant_id`,`status`,`id`),
  KEY `idx_file_object_sha256` (`tenant_id`,`sha256`),
  KEY `fk_file_object_member` (`tenant_id`,`created_by_member_id`),
  CONSTRAINT `fk_file_object_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_space` FOREIGN KEY (`storage_space_id`) REFERENCES `pa_storage_space` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_object_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_object_access` CHECK ((`access_type` in (_ascii'public',_ascii'private'))),
  CONSTRAINT `chk_file_object_archive_shape` CHECK ((((`status` in (_utf8mb4'pending_write',_utf8mb4'write_failed',_utf8mb4'ready',_utf8mb4'restricting',_utf8mb4'verifying',_utf8mb4'restricted_attention')) and (`archived_at` is null)) or ((`status` = _utf8mb4'archived') and (`archived_at` is not null)))),
  CONSTRAINT `chk_file_object_disposition` CHECK ((`disposition` in (_ascii'inline',_ascii'attachment'))),
  CONSTRAINT `chk_file_object_key` CHECK (regexp_like(`file_key`,_ascii'^file_[0-9a-f]{32}$')),
  CONSTRAINT `chk_file_object_purpose` CHECK (regexp_like(`purpose`,_ascii'^[a-z][a-z0-9._-]{2,95}$')),
  CONSTRAINT `chk_file_object_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_file_object_sha256` CHECK (regexp_like(`sha256`,_ascii'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_file_object_status` CHECK ((`status` in (_utf8mb4'pending_write',_utf8mb4'write_failed',_utf8mb4'ready',_utf8mb4'archived',_utf8mb4'restricting',_utf8mb4'verifying',_utf8mb4'restricted_attention'))),
  CONSTRAINT `chk_file_object_visibility_operation` CHECK ((((`status` in (_utf8mb4'restricting',_utf8mb4'verifying',_utf8mb4'restricted_attention')) and (`visibility_batch_key` is not null)) or ((`status` in (_utf8mb4'pending_write',_utf8mb4'write_failed',_utf8mb4'ready',_utf8mb4'archived')) and (`visibility_batch_key` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file' => <<<'SQL'
CREATE TABLE `pa_file` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cid` int unsigned NOT NULL DEFAULT '0' COMMENT '分类ID',
  `source_id` int unsigned NOT NULL DEFAULT '0' COMMENT '上传者ID',
  `source` tinyint(1) NOT NULL DEFAULT '0' COMMENT '来源：0后台 1用户',
  `type` tinyint NOT NULL DEFAULT '10' COMMENT '类型：10图片 20视频 30文件',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '文件名称',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_file_object_reference` (`file_key`),
  KEY `idx_cid` (`cid`),
  KEY `idx_type` (`type`),
  KEY `idx_type_cid_source` (`type`,`cid`,`source`),
  KEY `idx_file_tenant_type_cid_source` (`tenant_id`,`type`,`cid`,`source`,`id`),
  CONSTRAINT `fk_file_object_reference` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文件';
SQL,
        'pa_file_image_asset' => <<<'SQL'
CREATE TABLE `pa_file_image_asset` (
  `tenant_id` bigint unsigned NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `width` int unsigned NOT NULL,
  `height` int unsigned NOT NULL,
  `media_type` varchar(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`tenant_id`,`file_key`),
  CONSTRAINT `fk_file_image_asset_object` FOREIGN KEY (`tenant_id`, `file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_image_asset_dimensions` CHECK (((`width` between 1 and 50000) and (`height` between 1 and 50000) and ((`width` * `height`) <= 100000000))),
  CONSTRAINT `chk_file_image_asset_media_type` CHECK ((`media_type` in (_utf8mb4'image/jpeg',_utf8mb4'image/png')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_derivative' => <<<'SQL'
CREATE TABLE `pa_file_derivative` (
  `tenant_id` bigint unsigned NOT NULL,
  `source_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `variant_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `derivative_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `width` int unsigned NOT NULL,
  `height` int unsigned NOT NULL,
  `media_type` varchar(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`tenant_id`,`source_file_key`,`variant_key`),
  UNIQUE KEY `uk_file_derivative_object` (`tenant_id`,`derivative_file_key`),
  CONSTRAINT `fk_file_derivative_object` FOREIGN KEY (`tenant_id`, `derivative_file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_derivative_source` FOREIGN KEY (`tenant_id`, `source_file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_derivative_dimensions` CHECK (((`width` between 1 and 4096) and (`height` between 1 and 4096))),
  CONSTRAINT `chk_file_derivative_distinct` CHECK ((`source_file_key` <> `derivative_file_key`)),
  CONSTRAINT `chk_file_derivative_key` CHECK (regexp_like(`variant_key`,_utf8mb4'^[a-z][a-z0-9-]{0,31}$')),
  CONSTRAINT `chk_file_derivative_media_type` CHECK ((`media_type` in (_utf8mb4'image/jpeg',_utf8mb4'image/png')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_reference' => <<<'SQL'
CREATE TABLE `pa_file_reference` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_module_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reference_digest` varchar(128) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_file_reference_owner` (`tenant_id`,`file_key`,`owner_module_key`,`owner_type`,`owner_id`),
  KEY `idx_file_reference_owner` (`tenant_id`,`owner_module_key`,`owner_type`,`owner_id`),
  KEY `fk_file_reference_object` (`file_key`),
  CONSTRAINT `fk_file_reference_object` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_file_immutable_snapshot' => <<<'SQL'
CREATE TABLE `pa_file_immutable_snapshot` (
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `capture_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_revision` bigint unsigned NOT NULL,
  `source_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `verified_mime_type` varchar(127) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'captured',
  `claimed_document_id` bigint unsigned DEFAULT NULL,
  `claimed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`file_key`),
  UNIQUE KEY `uk_file_document_capture` (`tenant_id`,`capture_id`),
  CONSTRAINT `fk_file_immutable_target` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_immutable_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
  CONSTRAINT `chk_file_immutable_status` CHECK ((`status` in (_utf8mb4'captured',_utf8mb4'claimed',_utf8mb4'cleanup_pending',_utf8mb4'abandoned')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_file_visibility_item' => <<<'SQL'
CREATE TABLE `pa_file_visibility_item` (
  `batch_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `source_json` json NOT NULL,
  `target_json` json NOT NULL,
  `receipts_json` json NOT NULL,
  `status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `step` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `error_code` varchar(96) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`batch_key`,`file_key`),
  KEY `idx_file_visibility_item_status` (`tenant_id`,`status`,`batch_key`),
  KEY `fk_file_visibility_item_batch` (`tenant_id`,`batch_key`),
  KEY `fk_file_visibility_item_object` (`tenant_id`,`file_key`),
  CONSTRAINT `fk_file_visibility_item_batch` FOREIGN KEY (`tenant_id`, `batch_key`) REFERENCES `pa_file_visibility_batch` (`tenant_id`, `batch_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_visibility_item_object` FOREIGN KEY (`tenant_id`, `file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_visibility_item_status` CHECK ((`status` in (_utf8mb4'restricting',_utf8mb4'verifying',_utf8mb4'ready_private',_utf8mb4'restricted_attention'))),
  CONSTRAINT `chk_file_visibility_item_step` CHECK ((`step` in (_utf8mb4'copy',_utf8mb4'switch',_utf8mb4'close_source',_utf8mb4'verify',_utf8mb4'completed')))
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
            throw new RuntimeException('InitialFileSchema requires the native PDO adapter.');
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
