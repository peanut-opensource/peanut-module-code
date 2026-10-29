<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialEntitlementQuotaSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_entitlement_grant' => <<<'SQL'
CREATE TABLE `pa_entitlement_grant` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `grant_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `current_policy_revision_id` bigint unsigned DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_grant_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_entitlement_grant_key` (`tenant_id`,`grant_key`),
  KEY `idx_entitlement_grant_current_policy` (`tenant_id`,`current_policy_revision_id`),
  KEY `fk_entitlement_grant_created_member` (`tenant_id`,`created_by_member_id`),
  KEY `fk_entitlement_grant_updated_member` (`tenant_id`,`updated_by_member_id`),
  CONSTRAINT `fk_entitlement_grant_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_grant_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_grant_updated_member` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_grant_key` CHECK (regexp_like(`grant_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_entitlement_grant_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_entitlement_grant_state` CHECK ((`state` in (_utf8mb4'active',_utf8mb4'suspended')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_entitlement_policy_revision' => <<<'SQL'
CREATE TABLE `pa_entitlement_policy_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `grant_id` bigint unsigned NOT NULL,
  `policy_revision_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meter_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `limit_amount` bigint NOT NULL,
  `period_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `effective_from` datetime(3) NOT NULL,
  `effective_until` datetime(3) NOT NULL,
  `reservation_ttl_seconds` int unsigned NOT NULL,
  `canonical_snapshot_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `canonical_snapshot_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_policy_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_entitlement_policy_revision_key` (`tenant_id`,`policy_revision_key`),
  UNIQUE KEY `uk_entitlement_policy_grant_revision` (`tenant_id`,`grant_id`,`policy_revision_key`),
  KEY `idx_entitlement_policy_grant` (`tenant_id`,`grant_id`,`created_at`),
  KEY `fk_entitlement_policy_created_member` (`tenant_id`,`created_by_member_id`),
  CONSTRAINT `fk_entitlement_policy_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_policy_grant` FOREIGN KEY (`tenant_id`, `grant_id`) REFERENCES `pa_entitlement_grant` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_policy_digest` CHECK (regexp_like(`canonical_snapshot_sha256`,_utf8mb4'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_entitlement_policy_effective_interval` CHECK ((`effective_until` > `effective_from`)),
  CONSTRAINT `chk_entitlement_policy_limit` CHECK ((`limit_amount` >= 1)),
  CONSTRAINT `chk_entitlement_policy_meter_key` CHECK (regexp_like(`meter_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_entitlement_policy_period` CHECK ((`period_kind` in (_utf8mb4'lifetime',_utf8mb4'utc_day',_utf8mb4'utc_month'))),
  CONSTRAINT `chk_entitlement_policy_revision_key` CHECK (regexp_like(`policy_revision_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_entitlement_policy_snapshot_json` CHECK (json_valid(`canonical_snapshot_json`)),
  CONSTRAINT `chk_entitlement_policy_ttl` CHECK ((`reservation_ttl_seconds` between 30 and 86400)),
  CONSTRAINT `chk_entitlement_policy_unit_key` CHECK (regexp_like(`unit_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_entitlement_usage_window' => <<<'SQL'
CREATE TABLE `pa_entitlement_usage_window` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `policy_revision_id` bigint unsigned NOT NULL,
  `meter_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_start` datetime(3) NOT NULL,
  `window_end` datetime(3) NOT NULL,
  `committed_amount` bigint NOT NULL DEFAULT '0',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_window_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_entitlement_window_identity` (`tenant_id`,`policy_revision_id`,`meter_key`,`target_type`,`target_key`,`window_start`),
  KEY `idx_entitlement_window_target` (`tenant_id`,`meter_key`,`target_type`,`target_key`,`window_start`),
  CONSTRAINT `fk_entitlement_window_policy` FOREIGN KEY (`tenant_id`, `policy_revision_id`) REFERENCES `pa_entitlement_policy_revision` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_window_committed` CHECK ((`committed_amount` >= 0)),
  CONSTRAINT `chk_entitlement_window_interval` CHECK ((`window_end` > `window_start`)),
  CONSTRAINT `chk_entitlement_window_meter_key` CHECK (regexp_like(`meter_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_entitlement_window_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_entitlement_window_target_key` CHECK (regexp_like(`target_key`,_utf8mb4'^[ -~]+$')),
  CONSTRAINT `chk_entitlement_window_target_type` CHECK (regexp_like(`target_type`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_entitlement_reservation' => <<<'SQL'
CREATE TABLE `pa_entitlement_reservation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `usage_window_id` bigint unsigned NOT NULL,
  `reservation_key` varchar(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `meter_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` bigint NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_by_member_id` bigint unsigned NOT NULL,
  `settled_by_member_id` bigint unsigned DEFAULT NULL,
  `reserved_at` datetime(3) NOT NULL,
  `expires_at` datetime(3) NOT NULL,
  `settled_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_reservation_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_entitlement_reservation_key` (`tenant_id`,`reservation_key`),
  UNIQUE KEY `uk_entitlement_reservation_window_id` (`tenant_id`,`usage_window_id`,`id`),
  KEY `idx_entitlement_reservation_window_state` (`tenant_id`,`usage_window_id`,`state`,`expires_at`),
  KEY `fk_entitlement_reservation_created_member` (`tenant_id`,`created_by_member_id`),
  KEY `fk_entitlement_reservation_settled_member` (`tenant_id`,`settled_by_member_id`),
  CONSTRAINT `fk_entitlement_reservation_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_reservation_settled_member` FOREIGN KEY (`tenant_id`, `settled_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_reservation_window` FOREIGN KEY (`tenant_id`, `usage_window_id`) REFERENCES `pa_entitlement_usage_window` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_reservation_amount` CHECK ((`amount` >= 1)),
  CONSTRAINT `chk_entitlement_reservation_expiry` CHECK ((`expires_at` > `reserved_at`)),
  CONSTRAINT `chk_entitlement_reservation_key` CHECK (regexp_like(`reservation_key`,_utf8mb4'^reservation_[0-9a-f]{32}$')),
  CONSTRAINT `chk_entitlement_reservation_meter_key` CHECK (regexp_like(`meter_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_entitlement_reservation_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_entitlement_reservation_settlement` CHECK ((((`state` = _utf8mb4'pending') and (`settled_by_member_id` is null) and (`settled_at` is null)) or ((`state` in (_utf8mb4'committed',_utf8mb4'released',_utf8mb4'expired')) and (`settled_by_member_id` is not null) and (`settled_at` is not null)))),
  CONSTRAINT `chk_entitlement_reservation_state` CHECK ((`state` in (_utf8mb4'pending',_utf8mb4'committed',_utf8mb4'released',_utf8mb4'expired'))),
  CONSTRAINT `chk_entitlement_reservation_target_key` CHECK (regexp_like(`target_key`,_utf8mb4'^[ -~]+$')),
  CONSTRAINT `chk_entitlement_reservation_target_type` CHECK (regexp_like(`target_type`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_entitlement_usage_ledger' => <<<'SQL'
CREATE TABLE `pa_entitlement_usage_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `usage_window_id` bigint unsigned NOT NULL,
  `reservation_id` bigint unsigned NOT NULL,
  `event_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `event_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount` bigint NOT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `occurred_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entitlement_ledger_event_key` (`tenant_id`,`event_key`),
  UNIQUE KEY `uk_entitlement_ledger_reservation_event` (`tenant_id`,`reservation_id`,`event_type`),
  KEY `idx_entitlement_ledger_window` (`tenant_id`,`usage_window_id`,`id`),
  KEY `fk_entitlement_ledger_reservation` (`tenant_id`,`usage_window_id`,`reservation_id`),
  KEY `fk_entitlement_ledger_actor_member` (`tenant_id`,`actor_member_id`),
  CONSTRAINT `fk_entitlement_ledger_actor_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_ledger_reservation` FOREIGN KEY (`tenant_id`, `usage_window_id`, `reservation_id`) REFERENCES `pa_entitlement_reservation` (`tenant_id`, `usage_window_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_entitlement_ledger_window` FOREIGN KEY (`tenant_id`, `usage_window_id`) REFERENCES `pa_entitlement_usage_window` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_entitlement_ledger_amount` CHECK ((`amount` >= 1)),
  CONSTRAINT `chk_entitlement_ledger_event_key` CHECK (regexp_like(`event_key`,_utf8mb4'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_entitlement_ledger_event_type` CHECK ((`event_type` in (_utf8mb4'reserved',_utf8mb4'committed',_utf8mb4'released',_utf8mb4'expired')))
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
            throw new RuntimeException('InitialEntitlementQuotaSchema requires the native PDO adapter.');
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
