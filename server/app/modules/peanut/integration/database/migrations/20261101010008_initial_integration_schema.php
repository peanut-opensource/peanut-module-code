<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialIntegrationSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_external_channel_binding' => <<<'SQL'
CREATE TABLE `pa_external_channel_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `provider` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `callback_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'server generated opaque callback route key',
  `identity_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of canonical public provider identity',
  `identity_hint` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'non-secret audit/display hint',
  `config_json` json NOT NULL COMMENT 'server-controlled provider verifier/transport config',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_external_callback_key` (`provider`,`callback_key`),
  UNIQUE KEY `uk_external_provider_identity` (`provider`,`identity_hash`),
  UNIQUE KEY `uk_external_tenant_provider` (`tenant_id`,`provider`),
  KEY `idx_external_tenant_status` (`tenant_id`,`status`,`id`),
  CONSTRAINT `fk_external_binding_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tenant-owned external provider binding and callback verifier configuration';
SQL,
        'pa_integration_machine_identity' => <<<'SQL'
CREATE TABLE `pa_integration_machine_identity` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `identity_key` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `family_key` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) NOT NULL,
  `scopes_json` json NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `token_prefix` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_last_four` char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime(3) DEFAULT NULL,
  `last_used_at` datetime(3) DEFAULT NULL,
  `rotated_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_machine_key` (`tenant_id`,`identity_key`),
  UNIQUE KEY `uk_integration_machine_digest` (`token_digest`),
  KEY `idx_integration_machine_status` (`tenant_id`,`status`,`expires_at`,`id`),
  KEY `idx_integration_machine_family` (`tenant_id`,`family_key`,`id`),
  KEY `fk_integration_machine_member` (`tenant_id`,`created_by_member_id`),
  CONSTRAINT `fk_integration_machine_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_machine_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_machine_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'rotated',_utf8mb4'revoked'))),
  CONSTRAINT `chk_integration_machine_terminal` CHECK ((((`status` = _utf8mb4'active') and (`rotated_at` is null) and (`revoked_at` is null)) or ((`status` = _utf8mb4'rotated') and (`rotated_at` is not null) and (`revoked_at` is null)) or ((`status` = _utf8mb4'revoked') and (`revoked_at` is not null) and (`rotated_at` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_integration_webhook_endpoint' => <<<'SQL'
CREATE TABLE `pa_integration_webhook_endpoint` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `endpoint_key` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(120) NOT NULL,
  `url` varchar(2048) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `events_json` json NOT NULL,
  `secret_ciphertext` text NOT NULL,
  `secret_key_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `created_by_member_id` bigint unsigned NOT NULL,
  `disabled_at` datetime(3) DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_webhook_key` (`tenant_id`,`endpoint_key`),
  UNIQUE KEY `uk_integration_webhook_id` (`tenant_id`,`id`),
  KEY `idx_integration_webhook_status` (`tenant_id`,`status`,`id`),
  KEY `fk_integration_webhook_member` (`tenant_id`,`created_by_member_id`),
  CONSTRAINT `fk_integration_webhook_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_webhook_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_webhook_disabled` CHECK ((((`status` = _utf8mb4'active') and (`disabled_at` is null)) or ((`status` = _utf8mb4'disabled') and (`disabled_at` is not null)))),
  CONSTRAINT `chk_integration_webhook_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'disabled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_integration_security_event' => <<<'SQL'
CREATE TABLE `pa_integration_security_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `event_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` bigint unsigned DEFAULT NULL,
  `target_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metadata_json` json NOT NULL,
  `request_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_integration_event_time` (`tenant_id`,`occurred_at`,`id`),
  KEY `idx_integration_event_target` (`tenant_id`,`target_type`,`target_key_hash`,`id`),
  KEY `fk_integration_event_member` (`tenant_id`,`actor_member_id`),
  CONSTRAINT `fk_integration_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_integration_event_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_event_key` CHECK (regexp_like(`event_key`,_utf8mb4'^tenant\\.integration\\.[a-z_]+$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_integration_webhook_delivery' => <<<'SQL'
CREATE TABLE `pa_integration_webhook_delivery` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `endpoint_id` bigint unsigned NOT NULL,
  `delivery_key` char(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` json DEFAULT NULL,
  `payload_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `attempt_count` tinyint unsigned NOT NULL DEFAULT '0',
  `available_at` datetime(3) NOT NULL,
  `lease_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `lease_expires_at` datetime(3) DEFAULT NULL,
  `last_status_code` smallint unsigned DEFAULT NULL,
  `last_error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `delivered_at` datetime(3) DEFAULT NULL,
  `payload_expires_at` datetime(3) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_delivery_key` (`tenant_id`,`delivery_key`),
  UNIQUE KEY `uk_integration_delivery_event` (`tenant_id`,`endpoint_id`,`event_key`),
  UNIQUE KEY `uk_integration_delivery_id` (`tenant_id`,`id`),
  KEY `idx_integration_delivery_claim` (`tenant_id`,`status`,`available_at`,`id`),
  KEY `idx_integration_delivery_retention` (`status`,`updated_at`,`id`),
  CONSTRAINT `fk_integration_delivery_endpoint` FOREIGN KEY (`tenant_id`, `endpoint_id`) REFERENCES `pa_integration_webhook_endpoint` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_delivery_attempts` CHECK ((`attempt_count` <= 8)),
  CONSTRAINT `chk_integration_delivery_lease` CHECK ((((`status` = _utf8mb4'delivering') and (`lease_digest` is not null) and (`lease_expires_at` is not null)) or ((`status` <> _utf8mb4'delivering') and (`lease_digest` is null) and (`lease_expires_at` is null)))),
  CONSTRAINT `chk_integration_delivery_result` CHECK ((((`status` = _utf8mb4'delivered') and (`delivered_at` is not null)) or ((`status` <> _utf8mb4'delivered') and (`delivered_at` is null)))),
  CONSTRAINT `chk_integration_delivery_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'delivering',_utf8mb4'retryable',_utf8mb4'delivered',_utf8mb4'permanent_failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_integration_webhook_attempt' => <<<'SQL'
CREATE TABLE `pa_integration_webhook_attempt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `delivery_id` bigint unsigned NOT NULL,
  `attempt_number` tinyint unsigned NOT NULL,
  `outcome` varchar(24) NOT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `duration_ms` int unsigned NOT NULL,
  `attempted_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_integration_attempt_number` (`tenant_id`,`delivery_id`,`attempt_number`),
  KEY `idx_integration_attempt_time` (`tenant_id`,`attempted_at`,`id`),
  CONSTRAINT `fk_integration_attempt_delivery` FOREIGN KEY (`tenant_id`, `delivery_id`) REFERENCES `pa_integration_webhook_delivery` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_attempt_outcome` CHECK ((`outcome` in (_utf8mb4'delivered',_utf8mb4'retryable',_utf8mb4'permanent_failed')))
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
            throw new RuntimeException('InitialIntegrationSchema requires the native PDO adapter.');
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
