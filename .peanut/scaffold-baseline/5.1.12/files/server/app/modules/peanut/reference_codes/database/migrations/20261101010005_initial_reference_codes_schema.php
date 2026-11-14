<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialReferenceCodesSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_dict_type' => <<<'SQL'
CREATE TABLE `pa_dict_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '字典名称',
  `type` varchar(100) NOT NULL DEFAULT '' COMMENT '字典类型（英文标识）',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否禁用：0启用 1禁用',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `active_type` varchar(100) GENERATED ALWAYS AS ((case when (`delete_time` is null) then `type` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dict_type_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_dict_type_tenant_active_type` (`tenant_id`,`active_type`),
  KEY `idx_type` (`type`),
  KEY `idx_dict_type_tenant_status_name` (`tenant_id`,`is_disable`,`name`,`id`),
  CONSTRAINT `fk_dict_type_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='字典类型';
SQL,
        'pa_system_dict_type' => <<<'SQL'
CREATE TABLE `pa_system_dict_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_dict_type_code` (`code`),
  KEY `idx_system_dict_type_status` (`is_disable`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='系统只读字典类型';
SQL,
        'pa_reference_code_set' => <<<'SQL'
CREATE TABLE `pa_reference_code_set` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `set_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) NOT NULL,
  `definition_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_set` (`module_key`,`set_key`),
  KEY `idx_reference_code_set_lifecycle` (`lifecycle`,`module_key`,`set_key`),
  CONSTRAINT `chk_reference_code_set_lifecycle` CHECK ((`lifecycle` in (_utf8mb4'active',_utf8mb4'retired'))),
  CONSTRAINT `chk_reference_code_set_revision` CHECK ((`revision` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_dict_data' => <<<'SQL'
CREATE TABLE `pa_dict_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '数据名称',
  `value` varchar(255) NOT NULL DEFAULT '' COMMENT '数据值',
  `type_id` int unsigned NOT NULL DEFAULT '0' COMMENT '字典类型id',
  `type_value` varchar(100) NOT NULL DEFAULT '' COMMENT '冗余：字典类型标识（随类型编辑级联更新）',
  `sort` smallint NOT NULL DEFAULT '0' COMMENT '排序',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否禁用：0启用 1禁用',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dict_data_tenant_id` (`tenant_id`,`id`),
  KEY `idx_type_id` (`type_id`),
  KEY `idx_dict_data_tenant_type_status_sort` (`tenant_id`,`type_id`,`is_disable`,`sort`,`id`),
  CONSTRAINT `fk_dict_data_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dict_data_tenant_type` FOREIGN KEY (`tenant_id`, `type_id`) REFERENCES `pa_dict_type` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='字典数据';
SQL,
        'pa_system_dict_data' => <<<'SQL'
CREATE TABLE `pa_system_dict_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_code` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `value` varchar(255) NOT NULL DEFAULT '',
  `sort` smallint NOT NULL DEFAULT '0',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_system_dict_data_type_value` (`type_code`,`value`),
  KEY `idx_system_dict_data_lookup` (`type_code`,`is_disable`,`sort`,`id`),
  CONSTRAINT `fk_system_dict_data_type` FOREIGN KEY (`type_code`) REFERENCES `pa_system_dict_type` (`code`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='系统只读字典数据';
SQL,
        'pa_reference_code_entry' => <<<'SQL'
CREATE TABLE `pa_reference_code_entry` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `set_id` bigint unsigned NOT NULL,
  `code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `retired_at` datetime(3) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_entry` (`tenant_id`,`set_id`,`code`),
  KEY `idx_reference_code_entry_lookup` (`tenant_id`,`set_id`,`lifecycle`,`code`),
  KEY `fk_reference_code_entry_set` (`set_id`),
  KEY `fk_reference_code_entry_created_member` (`created_by_member_id`),
  KEY `fk_reference_code_entry_updated_member` (`updated_by_member_id`),
  CONSTRAINT `fk_reference_code_entry_created_member` FOREIGN KEY (`created_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_set` FOREIGN KEY (`set_id`) REFERENCES `pa_reference_code_set` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_updated_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reference_code_entry_lifecycle` CHECK ((`lifecycle` in (_utf8mb4'active',_utf8mb4'retired'))),
  CONSTRAINT `chk_reference_code_entry_retired_shape` CHECK ((((`lifecycle` = _utf8mb4'active') and (`retired_at` is null)) or ((`lifecycle` = _utf8mb4'retired') and (`retired_at` is not null)))),
  CONSTRAINT `chk_reference_code_entry_revision` CHECK ((`revision` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_reference_code_entry_version' => <<<'SQL'
CREATE TABLE `pa_reference_code_entry_version` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL,
  `label` varchar(160) NOT NULL,
  `metadata_json` json NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `effective_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `changed_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reference_code_entry_version` (`entry_id`,`revision`),
  KEY `idx_reference_code_entry_version_effective` (`entry_id`,`effective_at`,`expires_at`,`revision`),
  KEY `idx_reference_code_entry_version_status` (`entry_id`,`status`,`effective_at`,`expires_at`,`revision`),
  KEY `fk_reference_code_entry_version_member` (`changed_by_member_id`),
  CONSTRAINT `fk_reference_code_entry_version_entry` FOREIGN KEY (`entry_id`) REFERENCES `pa_reference_code_entry` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reference_code_entry_version_member` FOREIGN KEY (`changed_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reference_code_entry_version_interval` CHECK (((`expires_at` is null) or (`expires_at` > `effective_at`))),
  CONSTRAINT `chk_reference_code_entry_version_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_reference_code_entry_version_sort` CHECK ((`sort_order` between -(1000000) and 1000000)),
  CONSTRAINT `chk_reference_code_entry_version_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'inactive')))
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
            throw new RuntimeException('InitialReferenceCodesSchema requires the native PDO adapter.');
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
