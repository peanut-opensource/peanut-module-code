<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialRichTextSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_rich_text_document' => <<<'SQL'
CREATE TABLE `pa_rich_text_document` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `document_json` json NOT NULL,
  `collaboration_state` longblob,
  `revision` int unsigned NOT NULL DEFAULT '1',
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rich_text_document_tenant_id` (`tenant_id`,`id`),
  KEY `idx_rich_text_document_tenant_updated` (`tenant_id`,`delete_time`,`update_time`,`id`),
  KEY `fk_rich_text_document_created_by` (`tenant_id`,`created_by_member_id`),
  KEY `fk_rich_text_document_updated_by` (`tenant_id`,`updated_by_member_id`),
  CONSTRAINT `fk_rich_text_document_created_by` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rich_text_document_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rich_text_document_updated_by` FOREIGN KEY (`tenant_id`, `updated_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rich_text_document_revision` CHECK ((`revision` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tenant-owned structured rich-text documents';
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialRichTextSchema requires the native PDO adapter.');
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
