<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Only the Article-owned atomic HTML import receipt; existing migrations remain immutable. */
final class AddArticleHtmlMigration extends Migrator
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE `pa_article_html_migration` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `article_id` int unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `request_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_account_id` bigint unsigned NOT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `result_json` json NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_html_migration_request` (`tenant_id`,`article_id`,`request_id`),
  KEY `fk_article_html_migration_document` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_article_html_migration_article` FOREIGN KEY (`tenant_id`,`article_id`) REFERENCES `pa_article` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_html_migration_document` FOREIGN KEY (`tenant_id`,`document_id`) REFERENCES `pa_document` (`tenant_id`,`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_article_html_migration_input` CHECK (regexp_like(`input_sha256`,_ascii'^[a-f0-9]{64}$')),
  CONSTRAINT `chk_article_html_migration_source` CHECK (regexp_like(`source_sha256`,_ascii'^[a-f0-9]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Article HTML migration receipts and source traceability are retained.');
    }
}
