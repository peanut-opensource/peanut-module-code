<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Database;

use InvalidArgumentException;

final class Schema
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_workflow_definition' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `draft_graph_json` JSON NOT NULL,
  `draft_graph_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `layout_json` JSON NULL COMMENT '独立画布布局',
  `managed_template_json` JSON NULL COMMENT '受管模板来源与租户绑定基线',
  `active_version` INT UNSIGNED NULL COMMENT '新发起采用版本',
  `latest_version` INT UNSIGNED NOT NULL DEFAULT 0,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_by_member_id` BIGINT UNSIGNED NOT NULL,
  `updated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `retired_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_definition_identity` (`tenant_id`, `module_key`, `workflow_key`),
  KEY `idx_workflow_definition_status` (`tenant_id`, `status`, `id`),
  CONSTRAINT `fk_workflow_definition_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_module_key` CHECK (`module_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_workflow_definition_workflow_key` CHECK (`workflow_key` REGEXP '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'),
  CONSTRAINT `chk_workflow_definition_status` CHECK (`status` IN ('draft', 'active', 'retired')),
  CONSTRAINT `chk_workflow_definition_digest` CHECK (`draft_graph_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_definition_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_definition_retired_shape` CHECK ((`status` = 'retired' AND `retired_at` IS NOT NULL) OR (`status` <> 'retired' AND `retired_at` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_definition_version' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_definition_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `graph_json` JSON NOT NULL,
  `graph_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_version` INT UNSIGNED NOT NULL DEFAULT 2 COMMENT '图协议版本',
  `published_by_member_id` BIGINT UNSIGNED NOT NULL,
  `published_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_version_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_definition_version_number` (`tenant_id`, `definition_id`, `version`),
  UNIQUE KEY `uk_workflow_definition_version_digest` (`tenant_id`, `definition_id`, `graph_sha256`),
  KEY `idx_workflow_definition_version_published` (`tenant_id`, `published_at`, `id`),
  CONSTRAINT `fk_workflow_definition_version_definition` FOREIGN KEY (`tenant_id`, `definition_id`) REFERENCES `pa_workflow_definition` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_version_number` CHECK (`version` >= 1),
  CONSTRAINT `chk_workflow_definition_version_digest` CHECK (`graph_sha256` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_instance' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_instance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `definition_id` BIGINT UNSIGNED NOT NULL,
  `definition_version` INT UNSIGNED NOT NULL,
  `subject_type` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `module_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_department_key` VARCHAR(160) NULL,
  `snapshot_json` JSON NOT NULL COMMENT '送审快照与受控引用',
  `cycle_no` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '补正轮次',
  `result_code` VARCHAR(64) NULL COMMENT '流程结果而非业务状态',
  `blocked_reason` VARCHAR(500) NULL,
  `substantive_at` DATETIME(3) NULL COMMENT '首次实质处理',
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `initiated_by_member_id` BIGINT UNSIGNED NOT NULL,
  `last_actor_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  `cancelled_at` DATETIME(3) NULL,
  `active_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` IN ('running','blocked') THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_instance_key` (`instance_key`),
  UNIQUE KEY `uk_workflow_instance_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_instance_active_subject` (`tenant_id`, `module_key`, `workflow_key`, `subject_type`, `subject_key`, `active_marker`),
  KEY `idx_workflow_instance_status` (`tenant_id`, `status`, `updated_at`, `id`),
  KEY `idx_workflow_instance_subject` (`tenant_id`, `subject_type`, `subject_key`, `id`),
  CONSTRAINT `fk_workflow_instance_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_workflow_instance_version` FOREIGN KEY (`tenant_id`, `definition_id`, `definition_version`) REFERENCES `pa_workflow_definition_version` (`tenant_id`, `definition_id`, `version`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_instance_key` CHECK (`instance_key` REGEXP '^instance_[0-9a-f]{32}$'),
  CONSTRAINT `chk_workflow_instance_version` CHECK (`definition_version` >= 1),
  CONSTRAINT `chk_workflow_instance_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_instance_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_instance_status` CHECK (`status` IN ('running', 'blocked', 'completed', 'cancelled', 'terminated'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_node_execution' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_node_execution` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '节点执行ID',
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `node_execution_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `node_key` VARCHAR(64) NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `cycle_no` INT UNSIGNED NOT NULL,
  `previous_actor_member_id` BIGINT UNSIGNED NULL COMMENT '明确前序单人办理者',
  `node_type` VARCHAR(32) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `allocation_mode` VARCHAR(16) NULL,
  `aggregation_policy` VARCHAR(32) NULL,
  `input_json` JSON NOT NULL COMMENT '固定本次求值输入',
  `action_input_json` JSON NULL COMMENT '固定已解析业务动作输入',
  `started_at` DATETIME(3) NULL COMMENT '系统动作首次开始，失败后保留',
  `people_json` JSON NOT NULL COMMENT '冻结人员与来源',
  `output_json` JSON NULL,
  `selected_transition_key` VARCHAR(64) NULL,
  `blocked_reason` VARCHAR(500) NULL,
  `effect_key` VARCHAR(200) NULL,
  `effect_receipt_json` JSON NULL,
  `task_job_key` VARCHAR(64) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_node_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_node_key` (`node_execution_key`),
  UNIQUE KEY `uk_workflow_node_sequence` (`tenant_id`, `instance_id`, `sequence_no`),
  CONSTRAINT `fk_workflow_node_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_node_status` CHECK (`status` IN ('pending','active','blocked','completed','cancelled')),
  CONSTRAINT `chk_workflow_node_revision` CHECK (`revision` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='工作流节点执行'
SQL,
        'pa_workflow_work_item' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_work_item` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `work_item_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `round_no` INT UNSIGNED NOT NULL,
  `node_execution_id` BIGINT UNSIGNED NOT NULL,
  `owner_member_id` BIGINT UNSIGNED NULL COMMENT '任务归属人',
  `owner_source` VARCHAR(16) NOT NULL DEFAULT 'automatic',
  `assignment_state` VARCHAR(16) NOT NULL DEFAULT 'unassigned',
  `seat_no` INT UNSIGNED NOT NULL COMMENT '冻结责任席位',
  `sources_json` JSON NOT NULL COMMENT '全部命中来源',
  `assignment_source_kind` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignment_source_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignee_member_id` BIGINT UNSIGNED NULL COMMENT '当前办理人，候选未领为空',
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `decision` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `completed_by_member_id` BIGINT UNSIGNED NULL,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `completed_at` DATETIME(3) NULL,
  `cancelled_at` DATETIME(3) NULL,
  `pending_marker` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'pending' THEN 1 ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_work_item_key` (`work_item_key`),
  UNIQUE KEY `uk_workflow_work_item_tenant_id` (`tenant_id`, `id`),
  UNIQUE KEY `uk_workflow_work_item_seat` (`tenant_id`, `node_execution_id`, `seat_no`),
  UNIQUE KEY `uk_workflow_work_item_assignee_once` (`tenant_id`, `node_execution_id`, `assignee_member_id`),
  KEY `idx_workflow_work_item_assignee` (`tenant_id`, `assignee_member_id`, `status`, `created_at`, `id`),
  KEY `idx_workflow_work_item_instance` (`tenant_id`, `instance_id`, `status`, `id`),
  CONSTRAINT `fk_workflow_work_item_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_workflow_work_item_node` FOREIGN KEY (`tenant_id`, `node_execution_id`) REFERENCES `pa_workflow_node_execution` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_work_item_key` CHECK (`work_item_key` REGEXP '^work_[0-9a-f]{32}$'),
  CONSTRAINT `chk_workflow_work_item_round` CHECK (`round_no` >= 1),
  CONSTRAINT `chk_workflow_work_item_revision` CHECK (`revision` >= 1),
  CONSTRAINT `chk_workflow_work_item_assignment` CHECK ((`assignment_state` = 'unassigned' AND `assignee_member_id` IS NULL) OR (`assignment_state` = 'assigned' AND `assignee_member_id` IS NOT NULL)),
  CONSTRAINT `chk_workflow_work_item_status` CHECK (`status` IN ('pending', 'completed', 'cancelled')),
  CONSTRAINT `chk_workflow_work_item_terminal_shape` CHECK ((`status` = 'pending' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NULL) OR (`status` = 'completed' AND `decision` IS NOT NULL AND `completed_by_member_id` IS NOT NULL AND `completed_at` IS NOT NULL AND `cancelled_at` IS NULL) OR (`status` = 'cancelled' AND `decision` IS NULL AND `completed_by_member_id` IS NULL AND `completed_at` IS NULL AND `cancelled_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        'pa_workflow_task_identity_link' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_task_identity_link` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `work_item_id` BIGINT UNSIGNED NOT NULL,
  `member_id` BIGINT UNSIGNED NOT NULL,
  `relation_type` VARCHAR(16) NOT NULL COMMENT '候选、参与、抄送',
  `sources_json` JSON NOT NULL COMMENT '来源集合及绑定修订',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_identity_relation` (`tenant_id`, `work_item_id`, `member_id`, `relation_type`),
  KEY `idx_workflow_identity_member` (`tenant_id`, `member_id`, `relation_type`, `work_item_id`),
  CONSTRAINT `fk_workflow_identity_item` FOREIGN KEY (`tenant_id`, `work_item_id`) REFERENCES `pa_workflow_work_item` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_identity_type` CHECK (`relation_type` IN ('candidate','participant','watcher'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='任务人员关系'
SQL,
        'pa_workflow_event' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pa_workflow_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `node_execution_id` BIGINT UNSIGNED NULL,
  `event_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transition_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `from_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `to_node_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NULL,
  `subject_revision_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `comment_text` VARCHAR(2000) NULL,
  `comment_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `attachment_snapshots_json` JSON NOT NULL,
  `metadata_json` JSON NOT NULL,
  `occurred_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_event_sequence` (`tenant_id`, `instance_id`, `sequence_no`),
  KEY `idx_workflow_event_instance_time` (`tenant_id`, `instance_id`, `occurred_at`, `id`),
  KEY `idx_workflow_event_key_time` (`tenant_id`, `event_key`, `occurred_at`, `id`),
  CONSTRAINT `fk_workflow_event_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_event_sequence` CHECK (`sequence_no` >= 1),
  CONSTRAINT `chk_workflow_event_key` CHECK (`event_key` REGEXP '^tenant\\.workflow\\.[a-z_]+$'),
  CONSTRAINT `chk_workflow_event_subject_digest` CHECK (`subject_revision_sha256` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_workflow_event_comment_shape` CHECK ((`comment_text` IS NULL AND `comment_sha256` IS NULL) OR (`comment_text` IS NOT NULL AND `comment_sha256` REGEXP '^[0-9a-f]{64}$')),
  CONSTRAINT `chk_workflow_event_actor_shape` CHECK ((`actor_type` = 'member' AND `actor_member_id` IS NOT NULL) OR (`actor_type` = 'tenant_system' AND `actor_member_id` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_keys(self::CREATE_SQL);
    }

    /** @return list<string> */
    public static function createSql(): array
    {
        return array_values(self::CREATE_SQL);
    }

    public static function createTableSql(string $table): string
    {
        return self::CREATE_SQL[$table]
            ?? throw new InvalidArgumentException("Unknown workflow table: {$table}");
    }

    public static function dropSql(string $table): string
    {
        if (!isset(self::CREATE_SQL[$table])) {
            throw new InvalidArgumentException("Unknown workflow table: {$table}");
        }

        return "DROP TABLE IF EXISTS `{$table}`";
    }
}
