<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialNotificationSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_notice_log' => <<<'SQL'
CREATE TABLE `pa_notice_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int unsigned NOT NULL DEFAULT '0' COMMENT '模板ID',
  `scene_id` int unsigned NOT NULL DEFAULT '0' COMMENT '业务场景ID',
  `channel` tinyint NOT NULL DEFAULT '1' COMMENT '渠道：1短信 2邮件 3推送',
  `provider` varchar(20) NOT NULL DEFAULT '' COMMENT '发送服务商',
  `receiver` varchar(200) NOT NULL DEFAULT '' COMMENT '接收者（手机号/邮箱/设备token）',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '标题快照',
  `content` text COMMENT '内容快照（已替换变量）',
  `is_verified` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '是否已验证',
  `check_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT '校验次数',
  `verified_time` int unsigned NOT NULL DEFAULT '0' COMMENT '验证时间',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0待发 1成功 2失败',
  `error` varchar(500) NOT NULL DEFAULT '' COMMENT '错误信息',
  `extra` text COMMENT '额外数据（JSON）',
  `send_time` int unsigned NOT NULL DEFAULT '0' COMMENT '发送时间',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `verify_code_hash` varchar(255) NOT NULL DEFAULT '' COMMENT '验证码单向慢哈希',
  `tenant_id` bigint unsigned NOT NULL,
  `reservation_key` char(37) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'opaque verification SMS reservation capability',
  `idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'request-scoped idempotency identity digest',
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'immutable scene and receiver request digest',
  `receiver_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'receiver digest used by the active reservation key',
  `reservation_until` int unsigned DEFAULT NULL COMMENT 'exclusive resend reservation deadline',
  `reservation_active` tinyint unsigned DEFAULT NULL COMMENT '1 while reserved, successful, or unknown inside the resend window',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_log_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_notice_sms_reservation_key` (`reservation_key`),
  UNIQUE KEY `uk_notice_sms_idempotency` (`tenant_id`,`idempotency_key_hash`),
  UNIQUE KEY `uk_notice_sms_active_receiver` (`tenant_id`,`channel`,`receiver_hash`,`reservation_active`),
  KEY `idx_template_id` (`template_id`),
  KEY `idx_channel` (`channel`),
  KEY `idx_status` (`status`),
  KEY `idx_send_time` (`send_time`),
  KEY `idx_notice_log_tenant_scene_receiver` (`tenant_id`,`scene_id`,`channel`,`receiver`,`status`,`send_time`,`id`),
  KEY `idx_notice_log_tenant_list` (`tenant_id`,`status`,`channel`,`send_time`,`id`),
  KEY `idx_notice_sms_reservation_expiry` (`tenant_id`,`reservation_active`,`reservation_until`,`id`),
  CONSTRAINT `fk_notice_log_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notice_sms_reservation_active` CHECK (((`reservation_active` is null) or ((`reservation_active` = 1) and (`receiver_hash` is not null) and (`reservation_until` is not null) and (`status` in (0,1,3)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知发送记录';
SQL,
        'pa_notice_scene' => <<<'SQL'
CREATE TABLE `pa_notice_scene` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '业务场景标识',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '业务场景名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '场景说明',
  `recipient` varchar(50) NOT NULL DEFAULT '用户' COMMENT '接收对象',
  `variables` json DEFAULT NULL COMMENT '可用模板变量',
  `sms_template_id` varchar(100) NOT NULL DEFAULT '' COMMENT '服务商短信模板ID',
  `sms_content` varchar(500) NOT NULL DEFAULT '' COMMENT '短信内容模板',
  `sms_status` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '短信通知 0-关闭 1-开启',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_scene_tenant_code` (`tenant_id`,`code`),
  UNIQUE KEY `uk_notice_scene_tenant_id` (`tenant_id`,`id`),
  KEY `idx_sms_status` (`sms_status`),
  KEY `idx_notice_scene_tenant_sms` (`tenant_id`,`sms_status`,`id`),
  CONSTRAINT `fk_notice_scene_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知业务场景';
SQL,
        'pa_notice_template' => <<<'SQL'
CREATE TABLE `pa_notice_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '模板名称',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '模板标识（英文）',
  `channel` tinyint NOT NULL DEFAULT '1' COMMENT '渠道：1短信 2邮件 3推送',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '标题（邮件/推送用）',
  `content` text COMMENT '内容（支持变量{xxx}）',
  `is_disable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否禁用：0启用 1禁用',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notice_template_tenant_code` (`tenant_id`,`code`),
  UNIQUE KEY `uk_notice_template_tenant_id` (`tenant_id`,`id`),
  KEY `idx_channel` (`channel`),
  KEY `idx_notice_template_tenant_channel` (`tenant_id`,`channel`,`is_disable`,`id`),
  CONSTRAINT `fk_notice_template_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知模板';
SQL,
        'pa_notification_template' => <<<'SQL'
CREATE TABLE `pa_notification_template` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `template_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_template` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_template` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `channels_json` json NOT NULL,
  `variable_keys_json` json NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `created_by_member_id` bigint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_template` (`tenant_id`,`template_key`),
  KEY `idx_notification_template_status` (`tenant_id`,`status`,`id`),
  KEY `fk_notification_template_member` (`tenant_id`,`created_by_member_id`),
  CONSTRAINT `fk_notification_template_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_template_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_template_key` CHECK (regexp_like(`template_key`,_utf8mb4'^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_notification_template_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_notification_template_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'inactive')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_notification_message' => <<<'SQL'
CREATE TABLE `pa_notification_message` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `template_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `template_revision` bigint unsigned NOT NULL,
  `recipient_member_id` bigint unsigned NOT NULL,
  `recipient_account_id` bigint unsigned NOT NULL,
  `recipient_display_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unread',
  `created_by_member_id` bigint unsigned NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `read_at` datetime(3) DEFAULT NULL,
  `archived_at` datetime(3) DEFAULT NULL,
  `producer_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `producer_event_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `producer_business_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `producer_digest` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `producer_payload_sha256` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_message_key` (`message_key`),
  UNIQUE KEY `uk_notification_message_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uq_notification_producer_recipient` (`tenant_id`,`producer_digest`,`recipient_member_id`),
  KEY `idx_notification_inbox` (`tenant_id`,`recipient_member_id`,`status`,`id`),
  KEY `idx_notification_template` (`tenant_id`,`template_key`,`id`),
  KEY `fk_notification_creator_member` (`tenant_id`,`created_by_member_id`),
  CONSTRAINT `fk_notification_creator_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_message_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_recipient_member` FOREIGN KEY (`tenant_id`, `recipient_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_message_archive` CHECK (((`status` = _ascii'archived') = (`archived_at` is not null))),
  CONSTRAINT `chk_notification_message_key` CHECK (regexp_like(`message_key`,_ascii'^notice_[0-9a-f]{32}$')),
  CONSTRAINT `chk_notification_message_read` CHECK (((`status` = _ascii'unread') = (`read_at` is null))),
  CONSTRAINT `chk_notification_message_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_notification_message_status` CHECK ((`status` in (_ascii'unread',_ascii'read',_ascii'archived'))),
  CONSTRAINT `chk_notification_message_template_revision` CHECK ((`template_revision` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_sms_rate_bucket' => <<<'SQL'
CREATE TABLE `pa_sms_rate_bucket` (
  `tenant_id` bigint unsigned NOT NULL,
  `bucket_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_seconds` smallint unsigned NOT NULL,
  `window_started_at` datetime(3) NOT NULL,
  `send_count` int unsigned NOT NULL DEFAULT '0',
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`tenant_id`,`bucket_key`),
  CONSTRAINT `fk_sms_rate_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_sms_rate_bucket` CHECK (regexp_like(`bucket_key`,_utf8mb4'^(tenant|recipient:[0-9a-f]{64})$')),
  CONSTRAINT `chk_sms_rate_window` CHECK ((`window_seconds` between 1 and 3600))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_notification_task_actor' => <<<'SQL'
CREATE TABLE `pa_notification_task_actor` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `reference_key` char(64) COLLATE utf8mb4_bin NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `resource_key` varchar(160) COLLATE utf8mb4_bin NOT NULL,
  `operation` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `targets_sha256` char(64) COLLATE utf8mb4_bin NOT NULL,
  `request_id` varchar(128) COLLATE utf8mb4_bin NOT NULL,
  `session_key` varchar(128) COLLATE utf8mb4_bin NOT NULL,
  `client_key` varchar(128) COLLATE utf8mb4_bin NOT NULL,
  `account_security_revision` bigint unsigned NOT NULL,
  `tenant_security_revision` bigint unsigned NOT NULL,
  `member_security_revision` bigint unsigned NOT NULL,
  `outbox_key` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `created_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_task_actor` (`tenant_id`,`reference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL,
        'pa_notification_attachment' => <<<'SQL'
CREATE TABLE `pa_notification_attachment` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `file_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_type` varchar(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_attachment` (`tenant_id`,`message_id`,`file_key`),
  CONSTRAINT `fk_notification_attachment_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_attachment_file` CHECK (regexp_like(`file_key`,_utf8mb4'^file_[0-9a-f]{32}$')),
  CONSTRAINT `chk_notification_attachment_sha` CHECK (regexp_like(`sha256`,_utf8mb4'^[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_notification_outbox' => <<<'SQL'
CREATE TABLE `pa_notification_outbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `outbox_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `channel` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recipient_phone_masked` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `recipient_phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `dispatch_job_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_message_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `provider_receipt_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `last_error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `delivered_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_outbox_key` (`outbox_key`),
  UNIQUE KEY `uk_notification_outbox_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_notification_outbox_channel` (`tenant_id`,`message_id`,`channel`),
  KEY `idx_notification_outbox_pending` (`tenant_id`,`status`,`id`),
  CONSTRAINT `fk_notification_outbox_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_outbox_channel` CHECK ((`channel` in (_utf8mb4'inbox',_utf8mb4'sms'))),
  CONSTRAINT `chk_notification_outbox_delivery` CHECK (((`status` = _utf8mb4'delivered') = (`delivered_at` is not null))),
  CONSTRAINT `chk_notification_outbox_key` CHECK (regexp_like(`outbox_key`,_utf8mb4'^outbox_[0-9a-f]{32}$')),
  CONSTRAINT `chk_notification_outbox_phone` CHECK (((`channel` = _utf8mb4'sms') = ((`recipient_phone_masked` is not null) and (`recipient_phone_digest` is not null)))),
  CONSTRAINT `chk_notification_outbox_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_notification_outbox_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'queued',_utf8mb4'processing',_utf8mb4'retryable',_utf8mb4'delivered',_utf8mb4'permanent_failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        'pa_notification_event' => <<<'SQL'
CREATE TABLE `pa_notification_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `event_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` bigint unsigned DEFAULT NULL,
  `metadata_json` json NOT NULL,
  `occurred_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_event_message` (`tenant_id`,`message_id`,`id`),
  KEY `idx_notification_event_time` (`tenant_id`,`occurred_at`,`id`),
  KEY `fk_notification_event_member` (`tenant_id`,`actor_member_id`),
  CONSTRAINT `fk_notification_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_notification_event_message` FOREIGN KEY (`tenant_id`, `message_id`) REFERENCES `pa_notification_message` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_notification_event_key` CHECK (regexp_like(`event_key`,_utf8mb4'^tenant\\.notification\\.[a-z_]+$'))
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
            throw new RuntimeException('InitialNotificationSchema requires the native PDO adapter.');
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
