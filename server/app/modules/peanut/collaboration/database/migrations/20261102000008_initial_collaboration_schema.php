<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialCollaborationSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_collaboration_service_nonce' => <<<'SQL'
CREATE TABLE `pa_collaboration_service_nonce` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `nonce` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_service_nonce` (`service_instance_id`,`key_id`,`nonce`),
  KEY `idx_collaboration_nonce_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_collaboration_ticket' => <<<'SQL'
CREATE TABLE `pa_collaboration_ticket` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `edition` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `jti` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ticket_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mode` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `issued_identity_json` json NOT NULL,
  `owner_reference_json` json NOT NULL,
  `authorization_revision` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_auth_revision` bigint unsigned NOT NULL,
  `issued_state_seq` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  `consumed_at` datetime(3) DEFAULT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_ticket_jti` (`jti`),
  UNIQUE KEY `uk_collaboration_ticket_digest` (`ticket_sha256`),
  UNIQUE KEY `uk_collaboration_ticket_tenant_id` (`tenant_id`,`id`),
  KEY `idx_collaboration_ticket_expiry` (`expires_at`),
  KEY `fk_collaboration_ticket_document` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_collaboration_ticket_document` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `chk_collaboration_ticket_edition` CHECK ((`edition` in (_utf8mb4'engine',_utf8mb4'standalone',_utf8mb4'multi-tenant'))),
  CONSTRAINT `chk_collaboration_ticket_mode` CHECK ((`mode` in (_utf8mb4'read',_utf8mb4'write')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_collaboration_room' => <<<'SQL'
CREATE TABLE `pa_collaboration_room` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `edition` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `room_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `binding_json` json NOT NULL,
  `service_instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_epoch` bigint unsigned NOT NULL,
  `lease_expires_at` datetime(3) NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `accepted_through` bigint unsigned NOT NULL DEFAULT '0',
  `persisted_through` bigint unsigned NOT NULL DEFAULT '0',
  `persisted_state_seq` bigint unsigned NOT NULL,
  `baseline_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_binary_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `chain_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_store_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_store_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_receipt_json` json DEFAULT NULL,
  `pending_update_count` int unsigned NOT NULL DEFAULT '0',
  `pending_journal_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `protocol_version` int unsigned NOT NULL DEFAULT '1',
  `holder_key` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_target_progress` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_room` (`tenant_id`,`instance_id`,`edition`,`document_id`),
  UNIQUE KEY `uk_collaboration_room_key` (`room_key`),
  UNIQUE KEY `uk_collaboration_room_tenant_id` (`tenant_id`,`id`),
  KEY `fk_collaboration_room_document` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_collaboration_room_document` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `chk_collaboration_room_sequence` CHECK (((`persisted_through` <= `accepted_through`) and (`lease_epoch` > 0))),
  CONSTRAINT `chk_collaboration_room_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'draining',_utf8mb4'recovery',_utf8mb4'closed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_collaboration_runtime' => <<<'SQL'
CREATE TABLE `pa_collaboration_runtime` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `edition` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_revision` bigint unsigned NOT NULL,
  `config_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `phase` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `drain_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `plan_json` json DEFAULT NULL,
  `receipt_json` json DEFAULT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_runtime_instance` (`instance_id`,`edition`),
  CONSTRAINT `chk_collaboration_runtime_phase` CHECK ((`phase` in (_utf8mb4'prepared',_utf8mb4'active',_utf8mb4'draining',_utf8mb4'durably_drained',_utf8mb4'stopped')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_collaboration_connection' => <<<'SQL'
CREATE TABLE `pa_collaboration_connection` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `connection_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ticket_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `lease_epoch` bigint unsigned NOT NULL,
  `mode` varchar(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `authorization_revision` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lease_expires_at` datetime(3) NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `revoked_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_connection` (`connection_key`),
  UNIQUE KEY `uk_collaboration_connection_tenant_id` (`tenant_id`,`id`),
  KEY `fk_collaboration_connection_ticket` (`tenant_id`,`ticket_id`),
  KEY `fk_collaboration_connection_room` (`tenant_id`,`room_id`),
  CONSTRAINT `fk_collaboration_connection_room` FOREIGN KEY (`tenant_id`, `room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`, `id`),
  CONSTRAINT `fk_collaboration_connection_ticket` FOREIGN KEY (`tenant_id`, `ticket_id`) REFERENCES `pa_collaboration_ticket` (`tenant_id`, `id`),
  CONSTRAINT `chk_collaboration_connection_mode` CHECK ((`mode` in (_utf8mb4'read',_utf8mb4'write'))),
  CONSTRAINT `chk_collaboration_connection_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'revoked',_utf8mb4'closed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_collaboration_grant' => <<<'SQL'
CREATE TABLE `pa_collaboration_grant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `connection_id` bigint unsigned NOT NULL,
  `ticket_id` bigint unsigned NOT NULL,
  `grant_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `purpose` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `mode` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `service_instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `holder_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_epoch` bigint unsigned NOT NULL,
  `authorization_revision` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `identity_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issued_at_ms` bigint unsigned NOT NULL,
  `expires_at_ms` bigint unsigned NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `file_keys_json` json DEFAULT NULL,
  `reservation_key` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_grant_key` (`grant_key`),
  KEY `idx_collaboration_grant_window` (`room_id`,`lease_epoch`,`connection_id`,`authorization_revision`,`issued_at_ms`),
  KEY `idx_collaboration_grant_cleanup` (`room_id`,`lease_epoch`,`expires_at_ms`),
  KEY `fk_collaboration_grant_document` (`tenant_id`,`document_id`),
  KEY `fk_collaboration_grant_connection` (`tenant_id`,`connection_id`),
  KEY `fk_collaboration_grant_ticket` (`tenant_id`,`ticket_id`),
  KEY `fk_collaboration_grant_room` (`tenant_id`,`room_id`),
  CONSTRAINT `fk_collaboration_grant_connection` FOREIGN KEY (`tenant_id`, `connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`, `id`),
  CONSTRAINT `fk_collaboration_grant_document` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `fk_collaboration_grant_room` FOREIGN KEY (`tenant_id`, `room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`, `id`),
  CONSTRAINT `fk_collaboration_grant_ticket` FOREIGN KEY (`tenant_id`, `ticket_id`) REFERENCES `pa_collaboration_ticket` (`tenant_id`, `id`),
  CONSTRAINT `chk_collaboration_grant_mode` CHECK ((`mode` in (_utf8mb4'read',_utf8mb4'write'))),
  CONSTRAINT `chk_collaboration_grant_purpose` CHECK ((`purpose` in (_utf8mb4'connection',_utf8mb4'references'))),
  CONSTRAINT `chk_collaboration_grant_window` CHECK ((`expires_at_ms` > `issued_at_ms`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_collaboration_admission' => <<<'SQL'
CREATE TABLE `pa_collaboration_admission` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `connection_id` bigint unsigned NOT NULL,
  `lease_epoch` bigint unsigned NOT NULL,
  `accepted_seq` bigint unsigned NOT NULL,
  `update_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `admission_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `update_binary` mediumblob NOT NULL,
  `update_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `update_bytes` int unsigned NOT NULL,
  `base_candidate_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_binary_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `previous_chain_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `chain_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `candidate_projection_json` json NOT NULL,
  `projection_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `projector_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reference_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `authorization_proof_json` json NOT NULL,
  `accepted_at` datetime(3) NOT NULL,
  `persisted_state_seq` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_admission_sequence` (`tenant_id`,`room_id`,`accepted_seq`),
  UNIQUE KEY `uk_collaboration_admission_update` (`tenant_id`,`room_id`,`update_id`),
  UNIQUE KEY `uk_collaboration_admission_key` (`admission_key`),
  KEY `fk_collaboration_admission_connection` (`tenant_id`,`connection_id`),
  CONSTRAINT `fk_collaboration_admission_connection` FOREIGN KEY (`tenant_id`, `connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`, `id`),
  CONSTRAINT `fk_collaboration_admission_room` FOREIGN KEY (`tenant_id`, `room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`, `id`),
  CONSTRAINT `chk_collaboration_admission_size` CHECK (((`update_bytes` > 0) and (`update_bytes` = length(`update_binary`)) and (`lease_epoch` > 0) and (`accepted_seq` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_collaboration_barrier' => <<<'SQL'
CREATE TABLE `pa_collaboration_barrier` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `connection_id` bigint unsigned DEFAULT NULL,
  `barrier_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_epoch` bigint unsigned NOT NULL,
  `target_accepted_seq` bigint unsigned DEFAULT NULL,
  `purpose` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `committed_state_seq` bigint unsigned DEFAULT NULL,
  `committed_state_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  `business_plan_json` json DEFAULT NULL,
  `business_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `business_result_json` json DEFAULT NULL,
  `released_at` datetime(3) DEFAULT NULL,
  `drained_receipt_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collaboration_barrier` (`tenant_id`,`room_id`,`barrier_key`),
  KEY `fk_collaboration_barrier_connection` (`tenant_id`,`connection_id`),
  CONSTRAINT `fk_collaboration_barrier_connection` FOREIGN KEY (`tenant_id`, `connection_id`) REFERENCES `pa_collaboration_connection` (`tenant_id`, `id`),
  CONSTRAINT `fk_collaboration_barrier_room` FOREIGN KEY (`tenant_id`, `room_id`) REFERENCES `pa_collaboration_room` (`tenant_id`, `id`),
  CONSTRAINT `chk_collaboration_barrier_purpose` CHECK ((`purpose` in (_utf8mb4'version',_utf8mb4'archive',_utf8mb4'restore',_utf8mb4'shutdown'))),
  CONSTRAINT `chk_collaboration_barrier_status` CHECK ((`status` in (_utf8mb4'draining',_utf8mb4'committed',_utf8mb4'recovery')))
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
            throw new RuntimeException('InitialCollaborationSchema requires the native PDO adapter.');
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
