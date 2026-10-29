<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialOauthSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_oauth_attempt' => <<<'SQL'
CREATE TABLE `pa_oauth_attempt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `state_hash` char(64) NOT NULL COMMENT 'state SHA-256',
  `scene` varchar(32) NOT NULL COMMENT 'oa/open_pc',
  `return_path` varchar(500) NOT NULL DEFAULT '/' COMMENT '站内返回路径',
  `expires_at` int unsigned NOT NULL,
  `used_at` int unsigned DEFAULT NULL,
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_attempt_tenant_state` (`tenant_id`,`state_hash`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_oauth_attempt_tenant_expires` (`tenant_id`,`expires_at`),
  CONSTRAINT `fk_oauth_attempt_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='OAuth一次性state';
SQL,
        'pa_oauth_completion_ticket' => <<<'SQL'
CREATE TABLE `pa_oauth_completion_ticket` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL COMMENT '票据 SHA-256',
  `member_id` int unsigned NOT NULL,
  `need_profile` tinyint unsigned NOT NULL DEFAULT '0',
  `need_mobile` tinyint unsigned NOT NULL DEFAULT '0',
  `expires_at` int unsigned NOT NULL,
  `used_at` int unsigned DEFAULT NULL,
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `binding_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_ticket_tenant_token` (`tenant_id`,`token_hash`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_oauth_ticket_tenant_member` (`tenant_id`,`member_id`),
  KEY `idx_oauth_ticket_tenant_expires` (`tenant_id`,`expires_at`),
  KEY `idx_oauth_ticket_binding` (`binding_id`,`expires_at`),
  CONSTRAINT `fk_oauth_ticket_external_binding` FOREIGN KEY (`binding_id`) REFERENCES `pa_external_channel_binding` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_ticket_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_ticket_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='OAuth首登补全票据';
SQL,
        'pa_oauth_principal' => <<<'SQL'
CREATE TABLE `pa_oauth_principal` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL COMMENT '提供商',
  `union_scope` varchar(64) NOT NULL COMMENT '联合身份作用域',
  `union_id` varchar(191) NOT NULL COMMENT '联合身份',
  `member_id` int unsigned NOT NULL COMMENT '会员ID',
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_principal_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_oauth_principal_tenant_union` (`tenant_id`,`provider`,`union_scope`,`union_id`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_oauth_principal_tenant_member` (`tenant_id`,`member_id`),
  CONSTRAINT `fk_oauth_principal_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_principal_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='OAuth联合身份归属';
SQL,
        'pa_official_account_reply' => <<<'SQL'
CREATE TABLE `pa_official_account_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '关键词',
  `reply_type` tinyint unsigned NOT NULL COMMENT '1关注 2关键词 3默认',
  `matching_type` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1全匹配 2模糊匹配',
  `content_type` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '1文本',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '回复内容',
  `status` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '0停用 1启用',
  `sort` int unsigned NOT NULL DEFAULT '0' COMMENT '匹配优先级，升序',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned NOT NULL DEFAULT '0',
  `singleton_active_key` tinyint unsigned GENERATED ALWAYS AS ((case when ((`delete_time` = 0) and (`status` = 1) and (`reply_type` in (1,3))) then `reply_type` else NULL end)) STORED,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oa_reply_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_oa_reply_tenant_singleton_active` (`tenant_id`,`singleton_active_key`),
  KEY `idx_oa_reply_state` (`reply_type`,`status`,`delete_time`,`sort`,`id`),
  KEY `idx_oa_reply_tenant_state` (`tenant_id`,`reply_type`,`status`,`delete_time`,`sort`,`id`),
  CONSTRAINT `fk_oa_reply_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='微信公众号自动回复';
SQL,
        'pa_oauth_identity' => <<<'SQL'
CREATE TABLE `pa_oauth_identity` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL COMMENT '提供商',
  `client_key` varchar(64) NOT NULL COMMENT '客户端配置标识',
  `subject` varchar(191) NOT NULL COMMENT '客户端内外部身份',
  `principal_id` int unsigned DEFAULT NULL COMMENT '联合身份ID',
  `member_id` int unsigned NOT NULL COMMENT '会员ID',
  `terminal` tinyint unsigned NOT NULL COMMENT '业务终端',
  `create_time` int unsigned DEFAULT NULL,
  `update_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_identity_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_oauth_identity_tenant_subject` (`tenant_id`,`provider`,`client_key`,`subject`),
  UNIQUE KEY `uk_oauth_identity_tenant_member_client` (`tenant_id`,`member_id`,`provider`,`client_key`),
  KEY `idx_member_terminal` (`member_id`,`terminal`),
  KEY `idx_principal_id` (`principal_id`),
  KEY `idx_oauth_identity_tenant_member_terminal` (`tenant_id`,`member_id`,`terminal`),
  KEY `idx_oauth_identity_tenant_principal` (`tenant_id`,`principal_id`),
  CONSTRAINT `fk_oauth_identity_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_identity_principal` FOREIGN KEY (`tenant_id`, `principal_id`) REFERENCES `pa_oauth_principal` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oauth_identity_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='OAuth客户端身份';
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialOauthSchema requires the native PDO adapter.');
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
