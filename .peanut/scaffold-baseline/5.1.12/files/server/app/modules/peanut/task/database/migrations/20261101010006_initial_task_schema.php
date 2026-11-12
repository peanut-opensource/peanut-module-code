<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialTaskSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_crontab' => <<<'SQL'
CREATE TABLE `pa_crontab` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '任务名称',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '类型：1定时任务',
  `command` varchar(100) NOT NULL DEFAULT '' COMMENT '命令（think console 命令名）',
  `params` varchar(255) NOT NULL DEFAULT '' COMMENT '命令参数（空格分隔）',
  `status` tinyint(1) NOT NULL DEFAULT '2' COMMENT '状态：1运行 2停止 3错误',
  `expression` varchar(100) NOT NULL DEFAULT '' COMMENT 'crontab 运行规则',
  `error` varchar(255) NOT NULL DEFAULT '' COMMENT '最近一次错误信息',
  `last_time` int unsigned NOT NULL DEFAULT '0' COMMENT '最后执行时间',
  `time` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '最近一次耗时（秒）',
  `max_time` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '最大耗时（秒）',
  `sort` int unsigned NOT NULL DEFAULT '0' COMMENT '排序',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int unsigned NOT NULL DEFAULT '0',
  `update_time` int unsigned NOT NULL DEFAULT '0',
  `delete_time` int unsigned DEFAULT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_crontab_tenant_id` (`tenant_id`,`id`),
  KEY `idx_status` (`status`),
  KEY `idx_crontab_tenant_status_last` (`tenant_id`,`status`,`last_time`,`id`),
  CONSTRAINT `fk_crontab_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='定时任务';
SQL,
        'pa_task_job' => <<<'SQL'
CREATE TABLE `pa_task_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `task_type` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `handler_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_json` json NOT NULL,
  `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `trusted_envelope` text NOT NULL,
  `idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  `priority` smallint NOT NULL DEFAULT '0',
  `attempt_count` smallint unsigned NOT NULL DEFAULT '0',
  `max_attempts` smallint unsigned NOT NULL,
  `available_at` datetime(3) NOT NULL,
  `lease_owner_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `lease_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `lease_expires_at` datetime(3) DEFAULT NULL,
  `last_error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_by_member_id` bigint unsigned DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  `authorization_profile` varchar(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT 'member',
  `producer_fact_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_job_key` (`job_key`),
  UNIQUE KEY `uk_task_job_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_task_job_idempotency` (`tenant_id`,`created_by_member_id`,`task_type`,`idempotency_key_hash`),
  UNIQUE KEY `uk_task_negative_fact` (`authorization_profile`,`producer_fact_key`),
  KEY `idx_task_job_claim` (`tenant_id`,`status`,`available_at`,`priority`,`id`),
  KEY `idx_task_job_lease` (`status`,`lease_expires_at`,`id`),
  CONSTRAINT `fk_task_job_member` FOREIGN KEY (`tenant_id`, `created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_job_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_job_attempts` CHECK (((`max_attempts` between 1 and 10) and (`attempt_count` <= `max_attempts`))),
  CONSTRAINT `chk_task_job_completion` CHECK (((`status` in (_ascii'succeeded',_ascii'dead',_ascii'cancelled')) = (`completed_at` is not null))),
  CONSTRAINT `chk_task_job_key` CHECK (regexp_like(`job_key`,_ascii'^job_[0-9a-f]{32}$')),
  CONSTRAINT `chk_task_job_lease` CHECK (((`status` = _ascii'running') = ((`lease_owner_hash` is not null) and (`lease_token_hash` is not null) and (`lease_expires_at` is not null)))),
  CONSTRAINT `chk_task_job_payload_hash` CHECK (regexp_like(`payload_hash`,_ascii'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_task_job_profile` CHECK ((((`authorization_profile` = _utf8mb4'member') and (`created_by_member_id` is not null) and (`producer_fact_key` is null)) or ((`authorization_profile` = _utf8mb4'identity_negative_fact') and (`created_by_member_id` is null) and (`producer_fact_key` is not null) and (`task_type` = _utf8mb4'identity.revocation.propagate') and (`handler_key` = _utf8mb4'identity.revocation.propagate')))),
  CONSTRAINT `chk_task_job_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_task_job_status` CHECK ((`status` in (_ascii'queued',_ascii'running',_ascii'succeeded',_ascii'dead',_ascii'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_task_job_attempt' => <<<'SQL'
CREATE TABLE `pa_task_job_attempt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `job_id` bigint unsigned NOT NULL,
  `attempt_number` smallint unsigned NOT NULL,
  `worker_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lease_token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `started_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_attempt_number` (`tenant_id`,`job_id`,`attempt_number`),
  KEY `idx_task_attempt_status` (`tenant_id`,`status`,`started_at`,`id`),
  CONSTRAINT `fk_task_attempt_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_task_job` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_attempt_completion` CHECK (((`status` = _utf8mb4'running') = (`completed_at` is null))),
  CONSTRAINT `chk_task_attempt_status` CHECK ((`status` in (_utf8mb4'running',_utf8mb4'succeeded',_utf8mb4'retry',_utf8mb4'dead',_utf8mb4'abandoned')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_task_job_event' => <<<'SQL'
CREATE TABLE `pa_task_job_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `job_id` bigint unsigned NOT NULL,
  `event_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` bigint unsigned DEFAULT NULL,
  `metadata_json` json NOT NULL,
  `occurred_at` datetime(3) NOT NULL,
  `event_reference` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_event_reference` (`job_id`,`event_reference`),
  KEY `idx_task_event_job` (`tenant_id`,`job_id`,`id`),
  KEY `idx_task_event_time` (`tenant_id`,`occurred_at`,`id`),
  KEY `fk_task_event_member` (`tenant_id`,`actor_member_id`),
  CONSTRAINT `fk_task_event_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_task_job` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_task_event_member` FOREIGN KEY (`tenant_id`, `actor_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_task_event_key` CHECK (regexp_like(`event_key`,_ascii'^tenant\\.task\\.[a-z_]+$'))
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
            throw new RuntimeException('InitialTaskSchema requires the native PDO adapter.');
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
