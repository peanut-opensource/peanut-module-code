<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialMemberSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_member' => <<<'SQL'
CREATE TABLE `pa_member` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sn` varchar(20) NOT NULL DEFAULT '' COMMENT '会员编号',
  `account` varchar(50) NOT NULL DEFAULT '' COMMENT '用户账号',
  `account_unique` varchar(50) GENERATED ALWAYS AS (nullif(`account`,_utf8mb4'')) STORED,
  `password` varchar(100) NOT NULL DEFAULT '' COMMENT '用户密码',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `real_name` varchar(32) NOT NULL DEFAULT '' COMMENT '真实姓名',
  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `channel` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '注册来源：1小程序 2公众号 3手机H5 4电脑PC 5苹果APP 6安卓APP',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `sex` tinyint(1) NOT NULL DEFAULT '0' COMMENT '性别：0未知 1男 2女',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1正常',
  `session_revision` bigint unsigned NOT NULL DEFAULT '1' COMMENT '会员安全域会话修订；改密、重置或停用时递增',
  `login_time` int unsigned NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `is_new_user` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否新用户：0否 1是',
  `user_money` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '用户可用余额',
  `total_recharge_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '累计充值金额',
  `points` int unsigned NOT NULL DEFAULT '0' COMMENT '积分',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `mobile_unique` varchar(20) GENERATED ALWAYS AS (nullif(`mobile`,_utf8mb4'')) STORED,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_member_tenant_sn` (`tenant_id`,`sn`),
  UNIQUE KEY `uk_member_tenant_account` (`tenant_id`,`account_unique`),
  UNIQUE KEY `uk_member_tenant_mobile` (`tenant_id`,`mobile_unique`),
  KEY `idx_account` (`account`),
  KEY `idx_mobile` (`mobile`),
  KEY `idx_status` (`status`),
  KEY `idx_channel` (`channel`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_member_tenant_status_channel` (`tenant_id`,`status`,`channel`,`id`),
  CONSTRAINT `fk_member_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_member_security_session_revision` CHECK ((`session_revision` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='会员';
SQL,
        'pa_member_tag' => <<<'SQL'
CREATE TABLE `pa_member_tag` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '标签名称',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_tag_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_member_tag_tenant_name` (`tenant_id`,`name`),
  KEY `idx_member_tag_tenant_live` (`tenant_id`,`delete_time`,`id`),
  CONSTRAINT `fk_member_tag_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='会员标签';
SQL,
        'pa_member_balance_log' => <<<'SQL'
CREATE TABLE `pa_member_balance_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sn` varchar(32) NOT NULL DEFAULT '' COMMENT '流水号',
  `member_id` int unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `change_object` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '变动对象：1用户余额',
  `change_type` smallint unsigned NOT NULL DEFAULT '0' COMMENT '变动类型：100/101/200/201',
  `action` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '动作：1增加 2减少',
  `left_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '变动后余额',
  `source_type` tinyint NOT NULL DEFAULT '0' COMMENT 'Peanut旧字段',
  `extra` text COMMENT '扩展数据JSON',
  `admin_id` int unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned DEFAULT NULL,
  `delete_time` int unsigned DEFAULT NULL,
  `change_amount` decimal(10,2) unsigned NOT NULL DEFAULT '0.00' COMMENT '变动金额（无符号）',
  `source_sn` varchar(255) DEFAULT NULL COMMENT '来源单号',
  `source_sn_unique` varchar(255) GENERATED ALWAYS AS (nullif(`source_sn`,_utf8mb4'')) STORED,
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_balance_log_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_member_balance_log_tenant_sn` (`tenant_id`,`sn`),
  UNIQUE KEY `uk_member_balance_log_tenant_source` (`tenant_id`,`source_sn_unique`),
  KEY `idx_member_id` (`member_id`),
  KEY `idx_change_type` (`change_type`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_member_balance_log_tenant_member_time` (`tenant_id`,`member_id`,`create_time`,`id`),
  CONSTRAINT `fk_member_balance_log_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_balance_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='会员余额变动记录';
SQL,
        'pa_member_tag_relation' => <<<'SQL'
CREATE TABLE `pa_member_tag_relation` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int unsigned NOT NULL DEFAULT '0',
  `tag_id` int unsigned NOT NULL DEFAULT '0',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_tag_relation_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_member_tag_relation_tenant_pair` (`tenant_id`,`member_id`,`tag_id`),
  KEY `idx_tag_id` (`tag_id`),
  KEY `idx_member_tag_relation_tenant_tag` (`tenant_id`,`tag_id`,`member_id`),
  CONSTRAINT `fk_member_tag_relation_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_tag_relation_tag` FOREIGN KEY (`tenant_id`, `tag_id`) REFERENCES `pa_member_tag` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_tag_relation_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='会员标签关联';
SQL,
        'pa_member_session' => <<<'SQL'
CREATE TABLE `pa_member_session` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `session_revision` bigint unsigned NOT NULL,
  `issued_at` bigint unsigned NOT NULL,
  `expires_at` bigint unsigned NOT NULL,
  `revoked_at` bigint unsigned DEFAULT NULL,
  `revoke_reason` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_session_hash` (`session_hash`),
  KEY `idx_member_session_subject` (`tenant_id`,`member_id`,`revoked_at`,`expires_at`,`id`),
  CONSTRAINT `fk_member_session_member` FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_session_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_member_session_expiry` CHECK ((`expires_at` > `issued_at`)),
  CONSTRAINT `chk_member_session_hash` CHECK (regexp_like(`session_hash`,_utf8mb4'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_member_session_record_revision` CHECK ((`session_revision` >= 1)),
  CONSTRAINT `chk_member_session_revocation` CHECK ((((`revoked_at` is null) and (`revoke_reason` is null)) or ((`revoked_at` is not null) and (`revoked_at` >= `issued_at`) and (`revoke_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='会员端可撤销会话';
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialMemberSchema requires the native PDO adapter.');
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
