<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialArtifactRevisionSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_artifact' => <<<'SQL'
CREATE TABLE `pa_artifact` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `artifact_type` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `artifact_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `next_revision_number` bigint unsigned NOT NULL DEFAULT '1',
  `latest_finalized_revision_id` bigint unsigned DEFAULT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_artifact_identity` (`tenant_id`,`artifact_type`,`artifact_key`),
  KEY `idx_artifact_tenant_type` (`tenant_id`,`artifact_type`,`id`),
  KEY `idx_artifact_latest_finalized` (`tenant_id`,`latest_finalized_revision_id`),
  KEY `fk_artifact_created_member` (`tenant_id`,`created_by_member_id`),
  KEY `fk_artifact_updated_member` (`tenant_id`,`updated_by_member_id`),
  CONSTRAINT `fk_artifact_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_updated_member` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_artifact_key` CHECK ((`artifact_key` <> _utf8mb4'')),
  CONSTRAINT `chk_artifact_next_revision_number` CHECK ((`next_revision_number` >= 1)),
  CONSTRAINT `chk_artifact_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_artifact_type` CHECK (regexp_like(`artifact_type`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_artifact_revision' => <<<'SQL'
CREATE TABLE `pa_artifact_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `artifact_id` bigint unsigned NOT NULL,
  `revision_key` varchar(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_number` bigint unsigned NOT NULL,
  `parent_revision_id` bigint unsigned DEFAULT NULL,
  `parent_revision_number` bigint unsigned DEFAULT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `payload_schema_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_schema_version` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_ref` varchar(512) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payload_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `attachment_manifest_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `canonical_envelope_json` json DEFAULT NULL,
  `canonical_envelope_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `finalized_by_member_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `finalized_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_artifact_revision_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_artifact_revision_key` (`tenant_id`,`revision_key`),
  UNIQUE KEY `uk_artifact_revision_artifact_id` (`tenant_id`,`artifact_id`,`id`),
  UNIQUE KEY `uk_artifact_revision_parent_identity` (`tenant_id`,`artifact_id`,`id`,`revision_number`),
  UNIQUE KEY `uk_artifact_revision_number` (`tenant_id`,`artifact_id`,`revision_number`),
  KEY `idx_artifact_revision_artifact_state` (`tenant_id`,`artifact_id`,`state`,`revision_number`),
  KEY `idx_artifact_revision_parent` (`tenant_id`,`artifact_id`,`parent_revision_id`,`parent_revision_number`),
  KEY `fk_artifact_revision_created_member` (`tenant_id`,`created_by_member_id`),
  KEY `fk_artifact_revision_finalized_member` (`tenant_id`,`finalized_by_member_id`),
  CONSTRAINT `fk_artifact_revision_artifact` FOREIGN KEY (`tenant_id`, `artifact_id`) REFERENCES `pa_artifact` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_created_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_finalized_member` FOREIGN KEY (`tenant_id`, `finalized_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_artifact_revision_parent` FOREIGN KEY (`tenant_id`, `artifact_id`, `parent_revision_id`, `parent_revision_number`) REFERENCES `pa_artifact_revision` (`tenant_id`, `artifact_id`, `id`, `revision_number`) ON DELETE RESTRICT,
  CONSTRAINT `chk_artifact_revision_attachment_digest` CHECK (((`attachment_manifest_sha256` is null) or regexp_like(`attachment_manifest_sha256`,_utf8mb4'^[0-9a-f]{64}$'))),
  CONSTRAINT `chk_artifact_revision_envelope_digest` CHECK (((`canonical_envelope_sha256` is null) or regexp_like(`canonical_envelope_sha256`,_utf8mb4'^[0-9a-f]{64}$'))),
  CONSTRAINT `chk_artifact_revision_key` CHECK (regexp_like(`revision_key`,_utf8mb4'^revision_[0-9a-f]{32}$')),
  CONSTRAINT `chk_artifact_revision_number` CHECK ((`revision_number` >= 1)),
  CONSTRAINT `chk_artifact_revision_parent` CHECK ((((`parent_revision_id` is null) and (`parent_revision_number` is null)) or ((`parent_revision_id` is not null) and (`parent_revision_number` is not null) and (`parent_revision_number` < `revision_number`)))),
  CONSTRAINT `chk_artifact_revision_payload_digest` CHECK (((`payload_sha256` is null) or regexp_like(`payload_sha256`,_utf8mb4'^[0-9a-f]{64}$'))),
  CONSTRAINT `chk_artifact_revision_payload_ref` CHECK (((`payload_ref` is null) or (`payload_ref` <> _utf8mb4''))),
  CONSTRAINT `chk_artifact_revision_pending_shape` CHECK ((((`state` = _utf8mb4'pending') and (`payload_schema_key` is null) and (`payload_schema_version` is null) and (`payload_ref` is null) and (`payload_sha256` is null) and (`attachment_manifest_sha256` is null) and (`canonical_envelope_json` is null) and (`canonical_envelope_sha256` is null) and (`finalized_by_member_id` is null) and (`finalized_at` is null)) or ((`state` = _utf8mb4'finalized') and (`payload_schema_key` is not null) and (`payload_schema_version` is not null) and (`payload_ref` is not null) and (`payload_sha256` is not null) and (`canonical_envelope_json` is not null) and (`canonical_envelope_sha256` is not null) and (`finalized_by_member_id` is not null) and (`finalized_at` is not null)))),
  CONSTRAINT `chk_artifact_revision_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_artifact_revision_schema_key` CHECK (((`payload_schema_key` is null) or regexp_like(`payload_schema_key`,_utf8mb4'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'))),
  CONSTRAINT `chk_artifact_revision_schema_version` CHECK (((`payload_schema_version` is null) or (`payload_schema_version` <> _utf8mb4''))),
  CONSTRAINT `chk_artifact_revision_state` CHECK ((`state` in (_utf8mb4'pending',_utf8mb4'finalized')))
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
            throw new RuntimeException('InitialArtifactRevisionSchema requires the native PDO adapter.');
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
