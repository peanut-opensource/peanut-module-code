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
        foreach (self::CREATE_SQL as $table => $definition) {
            if (!$adapter->hasTable($table)) {
                $this->execute($definition);
            }
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Module data is retained. This initial schema migration cannot be rolled back.');
    }
}
