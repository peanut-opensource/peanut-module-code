<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Retained fixed-revision comments. Actor membership is checked through Identity's public contracts. */
final class DocumentRevisionComments extends Migrator
{
    private const CREATE_SQL = [
        'pa_document_comment_thread' => <<<'SQL'
CREATE TABLE `pa_document_comment_thread` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `revision_id` bigint unsigned NOT NULL,
  `version_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `text_profile` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `text_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `start_offset` int unsigned NOT NULL,
  `end_offset` int unsigned NOT NULL,
  `quote_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `thread_revision` bigint unsigned NOT NULL,
  `created_by_member_id` bigint unsigned NOT NULL,
  `created_by_account_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `updated_by_account_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_comment_thread_tenant` (`tenant_id`,`id`),
  KEY `idx_document_comment_revision` (`tenant_id`,`document_id`,`revision_id`,`id`),
  CONSTRAINT `fk_document_comment_document` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `fk_document_comment_revision` FOREIGN KEY (`revision_id`) REFERENCES `pa_document_revision` (`id`),
  CONSTRAINT `chk_document_comment_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'resolved'))),
  CONSTRAINT `chk_document_comment_range` CHECK ((`end_offset` > `start_offset`)),
  CONSTRAINT `chk_document_comment_revision` CHECK ((`thread_revision` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_comment_message' => <<<'SQL'
CREATE TABLE `pa_document_comment_message` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL,
  `thread_revision` bigint unsigned NOT NULL,
  `action` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `actor_account_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_comment_message_tenant` (`tenant_id`,`id`),
  UNIQUE KEY `uk_document_comment_message_revision` (`tenant_id`,`thread_id`,`thread_revision`),
  CONSTRAINT `fk_document_comment_message_thread` FOREIGN KEY (`tenant_id`, `thread_id`) REFERENCES `pa_document_comment_thread` (`tenant_id`, `id`),
  CONSTRAINT `chk_document_comment_message_action` CHECK (((`action` in (_utf8mb4'create',_utf8mb4'reply')) and (`body` is not null)) or ((`action` in (_utf8mb4'resolve',_utf8mb4'reopen')) and (`body` is null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_document_comment_operation' => <<<'SQL'
CREATE TABLE `pa_document_comment_operation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `request_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `thread_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `actor_account_id` bigint unsigned NOT NULL,
  `result_json` json NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_comment_request` (`tenant_id`,`document_id`,`request_id`),
  CONSTRAINT `fk_document_comment_operation_document` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `fk_document_comment_operation_thread` FOREIGN KEY (`tenant_id`, `thread_id`) REFERENCES `pa_document_comment_thread` (`tenant_id`, `id`),
  CONSTRAINT `fk_document_comment_operation_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_document_comment_message` (`tenant_id`, `id`)
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
            throw new RuntimeException('DocumentRevisionComments requires the native PDO adapter.');
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
        throw new IrreversibleMigrationException('Comment history is retained; this migration cannot be rolled back.');
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
