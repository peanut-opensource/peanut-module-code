<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialArticleSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_article_cate' => <<<'SQL'
CREATE TABLE `pa_article_cate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `is_show` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '是否显示 0否1是',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `name` varchar(90) NOT NULL DEFAULT '' COMMENT '分类名称',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序（倒序）',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_cate_tenant_id` (`tenant_id`,`id`),
  KEY `idx_article_cate_tenant_visible` (`tenant_id`,`is_show`,`sort`,`id`),
  CONSTRAINT `fk_article_cate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文章分类';
SQL,
        'pa_article_body_save' => <<<'SQL'
CREATE TABLE `pa_article_body_save` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `article_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `request_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_account_id` bigint unsigned NOT NULL,
  `actor_member_id` bigint unsigned NOT NULL,
  `phase` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result_json` json DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_body_save_request` (`tenant_id`,`article_id`,`request_id`),
  KEY `fk_article_body_save_document` (`tenant_id`,`document_id`),
  CONSTRAINT `fk_article_body_save_document` FOREIGN KEY (`tenant_id`, `document_id`) REFERENCES `pa_document` (`tenant_id`, `id`),
  CONSTRAINT `fk_article_body_save_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`),
  CONSTRAINT `chk_article_body_save_digest` CHECK (regexp_like(`input_sha256`,_ascii'^[a-f0-9]{64}$')),
  CONSTRAINT `chk_article_body_save_phase` CHECK ((`phase` in (_utf8mb4'prepared',_utf8mb4'completed',_utf8mb4'conflicted'))),
  CONSTRAINT `chk_article_body_save_result` CHECK ((((`phase` = _utf8mb4'prepared') and (`result_json` is null) and (`completed_at` is null)) or ((`phase` in (_utf8mb4'completed',_utf8mb4'conflicted')) and (`result_json` is not null) and (`completed_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_article' => <<<'SQL'
CREATE TABLE `pa_article` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `desc` varchar(255) DEFAULT '' COMMENT '简介',
  `abstract` text COMMENT '文章摘要',
  `click_virtual` int DEFAULT '0' COMMENT '虚拟浏览量',
  `click_actual` int DEFAULT '0' COMMENT '实际浏览量',
  `is_show` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '是否显示 0否1是',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '文章标题',
  `author` varchar(255) DEFAULT '' COMMENT '作者',
  `content` text COMMENT '文章内容',
  `sort` int DEFAULT '0' COMMENT '排序（倒序）',
  `image` varchar(2048) DEFAULT NULL COMMENT '文章图片：local 相对 URI 或云/CDN 绝对 URL',
  `tenant_id` bigint unsigned NOT NULL,
  `cid` int unsigned NOT NULL DEFAULT '0' COMMENT '文章分类',
  `body_document_id` bigint unsigned DEFAULT NULL,
  `body_revision_id` bigint unsigned DEFAULT NULL,
  `body_source_state_seq` bigint unsigned DEFAULT NULL,
  `body_snapshot_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_tenant_id` (`tenant_id`,`id`),
  KEY `idx_cid` (`cid`),
  KEY `idx_article_tenant_visible_cate` (`tenant_id`,`is_show`,`cid`,`sort`,`id`),
  KEY `fk_article_tenant_cate` (`tenant_id`,`cid`),
  CONSTRAINT `fk_article_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_tenant_cate` FOREIGN KEY (`tenant_id`, `cid`) REFERENCES `pa_article_cate` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_article_body_binding` CHECK ((((`body_revision_id` is null) and (`body_source_state_seq` is null) and (`body_snapshot_sha256` is null)) or ((`body_document_id` is not null) and (`body_revision_id` is not null) and (`body_source_state_seq` is not null) and (`body_snapshot_sha256` is not null) and regexp_like(`body_snapshot_sha256`,_utf8mb4'^[a-f0-9]{64}$'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文章';
SQL,
        'pa_article_collect' => <<<'SQL'
CREATE TABLE `pa_article_collect` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `article_id` int unsigned NOT NULL DEFAULT '0' COMMENT '文章ID',
  `status` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '收藏状态 0-未收藏 1-已收藏',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_article_collect_tenant_member_article` (`tenant_id`,`member_id`,`article_id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_article_collect_tenant_member_status` (`tenant_id`,`member_id`,`status`,`id`),
  KEY `fk_article_collect_tenant_article` (`tenant_id`,`article_id`),
  CONSTRAINT `fk_article_collect_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_collect_tenant_article` FOREIGN KEY (`tenant_id`, `article_id`) REFERENCES `pa_article` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_article_collect_tenant_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文章收藏';
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialArticleSchema requires the native PDO adapter.');
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
