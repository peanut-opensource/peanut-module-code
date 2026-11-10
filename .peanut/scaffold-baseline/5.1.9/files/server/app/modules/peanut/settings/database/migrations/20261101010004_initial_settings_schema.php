<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialSettingsSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_setting_definition' => <<<'SQL'
CREATE TABLE `pa_setting_definition` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `module_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `setting_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` varchar(500) NOT NULL,
  `schema_json` json NOT NULL,
  `required_flag` tinyint unsigned NOT NULL,
  `secret_flag` tinyint unsigned NOT NULL,
  `deployment_scope_flag` tinyint unsigned NOT NULL,
  `tenant_scope_flag` tinyint unsigned NOT NULL,
  `target_scope_flag` tinyint unsigned NOT NULL,
  `target_resource_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `target_operation` varchar(96) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `default_json` json DEFAULT NULL,
  `definition_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_definition` (`module_key`,`setting_key`),
  CONSTRAINT `chk_setting_definition_default` CHECK (((`secret_flag` = 0) or (`default_json` is null))),
  CONSTRAINT `chk_setting_definition_deployment_scope` CHECK ((`deployment_scope_flag` in (0,1))),
  CONSTRAINT `chk_setting_definition_required` CHECK ((`required_flag` in (0,1))),
  CONSTRAINT `chk_setting_definition_secret` CHECK ((`secret_flag` in (0,1))),
  CONSTRAINT `chk_setting_definition_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'retired'))),
  CONSTRAINT `chk_setting_definition_target` CHECK ((((`target_scope_flag` = 1) and (`target_resource_key` is not null) and (`target_operation` is not null)) or ((`target_scope_flag` = 0) and (`target_resource_key` is null) and (`target_operation` is null)))),
  CONSTRAINT `chk_setting_definition_target_scope` CHECK ((`target_scope_flag` in (0,1))),
  CONSTRAINT `chk_setting_definition_tenant_scope` CHECK ((`tenant_scope_flag` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_tenant_setting' => <<<'SQL'
CREATE TABLE `pa_tenant_setting` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `namespace` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_json` json NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_setting_namespace` (`tenant_id`,`namespace`),
  CONSTRAINT `fk_tenant_setting_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_tenant_setting_namespace` CHECK (regexp_like(`namespace`,_utf8mb4'^[a-z][a-z0-9.-]{0,63}$',_utf8mb4'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tenant-owned application capability settings';
SQL,
        'pa_setting_deployment_value' => <<<'SQL'
CREATE TABLE `pa_setting_deployment_value` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `definition_id` bigint unsigned NOT NULL,
  `value_state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` json DEFAULT NULL,
  `ciphertext` varbinary(8192) DEFAULT NULL,
  `nonce` binary(24) DEFAULT NULL,
  `key_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `effective_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `updated_by_operator_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_deployment_value` (`definition_id`),
  KEY `fk_setting_deployment_operator` (`updated_by_operator_id`),
  CONSTRAINT `fk_setting_deployment_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_deployment_operator` FOREIGN KEY (`updated_by_operator_id`) REFERENCES `pa_platform_operator` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_deployment_interval` CHECK (((`expires_at` is null) or (`expires_at` > `effective_at`))),
  CONSTRAINT `chk_setting_deployment_state` CHECK ((`value_state` in (_utf8mb4'set',_utf8mb4'unset'))),
  CONSTRAINT `chk_setting_deployment_storage` CHECK ((((`value_state` = _utf8mb4'unset') and (`value_json` is null) and (`ciphertext` is null) and (`nonce` is null) and (`key_id` is null)) or ((`value_state` = _utf8mb4'set') and (((`value_json` is not null) and (`ciphertext` is null) and (`nonce` is null) and (`key_id` is null)) or ((`value_json` is null) and (`ciphertext` is not null) and (length(`ciphertext`) > 0) and (`nonce` is not null) and (`key_id` is not null))))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_setting_tenant_value' => <<<'SQL'
CREATE TABLE `pa_setting_tenant_value` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `definition_id` bigint unsigned NOT NULL,
  `value_state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` json DEFAULT NULL,
  `ciphertext` varbinary(8192) DEFAULT NULL,
  `nonce` binary(24) DEFAULT NULL,
  `key_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `effective_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_tenant_value` (`tenant_id`,`definition_id`),
  KEY `idx_setting_tenant_definition` (`definition_id`),
  KEY `fk_setting_tenant_member` (`updated_by_member_id`),
  CONSTRAINT `fk_setting_tenant_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_tenant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_tenant_interval` CHECK (((`expires_at` is null) or (`expires_at` > `effective_at`))),
  CONSTRAINT `chk_setting_tenant_state` CHECK ((`value_state` in (_utf8mb4'set',_utf8mb4'unset'))),
  CONSTRAINT `chk_setting_tenant_storage` CHECK ((((`value_state` = _utf8mb4'unset') and (`value_json` is null) and (`ciphertext` is null) and (`nonce` is null) and (`key_id` is null)) or ((`value_state` = _utf8mb4'set') and (((`value_json` is not null) and (`ciphertext` is null) and (`nonce` is null) and (`key_id` is null)) or ((`value_json` is null) and (`ciphertext` is not null) and (length(`ciphertext`) > 0) and (`nonce` is not null) and (`key_id` is not null))))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_setting_target_value' => <<<'SQL'
CREATE TABLE `pa_setting_target_value` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `definition_id` bigint unsigned NOT NULL,
  `target_resource_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_json` json DEFAULT NULL,
  `ciphertext` varbinary(8192) DEFAULT NULL,
  `nonce` binary(24) DEFAULT NULL,
  `key_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `effective_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_target_value` (`tenant_id`,`definition_id`,`target_resource_key`,`target_id`),
  KEY `idx_setting_target_definition` (`definition_id`),
  KEY `fk_setting_target_member` (`updated_by_member_id`),
  CONSTRAINT `fk_setting_target_definition` FOREIGN KEY (`definition_id`) REFERENCES `pa_setting_definition` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_member` FOREIGN KEY (`updated_by_member_id`) REFERENCES `pa_tenant_member` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_setting_target_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_setting_target_interval` CHECK (((`expires_at` is null) or (`expires_at` > `effective_at`))),
  CONSTRAINT `chk_setting_target_state` CHECK ((`value_state` in (_utf8mb4'set',_utf8mb4'unset'))),
  CONSTRAINT `chk_setting_target_storage` CHECK ((((`value_state` = _utf8mb4'unset') and (`value_json` is null) and (`ciphertext` is null) and (`nonce` is null) and (`key_id` is null)) or ((`value_state` = _utf8mb4'set') and (((`value_json` is not null) and (`ciphertext` is null) and (`nonce` is null) and (`key_id` is null)) or ((`value_json` is null) and (`ciphertext` is not null) and (length(`ciphertext`) > 0) and (`nonce` is not null) and (`key_id` is not null))))))
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
            throw new RuntimeException('InitialSettingsSchema requires the native PDO adapter.');
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
