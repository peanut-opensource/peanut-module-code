<?php

declare(strict_types=1);

use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Initial owned tables from the fixed application baseline; preserves existing data. */
final class InitialWorkflowSchema extends Migrator
{
    /** @var array<string, string> */
    private const CREATE_SQL = [
        'pa_workflow_definition' => <<<'SQL'
CREATE TABLE `pa_workflow_definition` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `module_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `draft_graph_json` json NOT NULL,
  `draft_graph_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `latest_version` int unsigned NOT NULL DEFAULT '0',
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_by_member_id` bigint unsigned NOT NULL,
  `updated_by_member_id` bigint unsigned NOT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `retired_at` datetime(3) DEFAULT NULL,
  `managed_template_json` json DEFAULT NULL,
  `layout_json` json DEFAULT NULL,
  `active_version` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_workflow_definition_identity` (`tenant_id`,`module_key`,`workflow_key`),
  KEY `idx_workflow_definition_status` (`tenant_id`,`status`,`id`),
  CONSTRAINT `fk_workflow_definition_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_digest` CHECK (regexp_like(`draft_graph_sha256`,_ascii'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_workflow_definition_module_key` CHECK (regexp_like(`module_key`,_ascii'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')),
  CONSTRAINT `chk_workflow_definition_retired_shape` CHECK ((((`status` = _ascii'retired') and (`retired_at` is not null)) or ((`status` <> _ascii'retired') and (`retired_at` is null)))),
  CONSTRAINT `chk_workflow_definition_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_workflow_definition_status` CHECK ((`status` in (_ascii'draft',_ascii'active',_ascii'retired'))),
  CONSTRAINT `chk_workflow_definition_workflow_key` CHECK (regexp_like(`workflow_key`,_ascii'^[a-z][a-z0-9]*([.-][a-z0-9]+)*$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_workflow_definition_version' => <<<'SQL'
CREATE TABLE `pa_workflow_definition_version` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `definition_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL,
  `graph_json` json NOT NULL,
  `graph_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `published_by_member_id` bigint unsigned NOT NULL,
  `published_at` datetime(3) NOT NULL,
  `contract_version` int unsigned NOT NULL DEFAULT '2',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_definition_version_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_workflow_definition_version_number` (`tenant_id`,`definition_id`,`version`),
  UNIQUE KEY `uk_workflow_definition_version_digest` (`tenant_id`,`definition_id`,`graph_sha256`),
  KEY `idx_workflow_definition_version_published` (`tenant_id`,`published_at`,`id`),
  CONSTRAINT `fk_workflow_definition_version_definition` FOREIGN KEY (`tenant_id`, `definition_id`) REFERENCES `pa_workflow_definition` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_definition_version_digest` CHECK (regexp_like(`graph_sha256`,_ascii'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_workflow_definition_version_number` CHECK ((`version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_workflow_instance' => <<<'SQL'
CREATE TABLE `pa_workflow_instance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `definition_id` bigint unsigned NOT NULL,
  `definition_version` int unsigned NOT NULL,
  `subject_type` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_node_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'running',
  `initiated_by_member_id` bigint unsigned NOT NULL,
  `last_actor_member_id` bigint unsigned DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  `cancelled_at` datetime(3) DEFAULT NULL,
  `module_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workflow_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_department_key` varchar(160) DEFAULT NULL,
  `snapshot_json` json NOT NULL,
  `cycle_no` int unsigned NOT NULL DEFAULT '1',
  `result_code` varchar(64) DEFAULT NULL,
  `blocked_reason` varchar(500) DEFAULT NULL,
  `substantive_at` datetime(3) DEFAULT NULL,
  `active_marker` tinyint GENERATED ALWAYS AS ((case when (`status` in (_ascii'running',_ascii'blocked')) then 1 else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_instance_key` (`instance_key`),
  UNIQUE KEY `uk_workflow_instance_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_workflow_instance_active_subject` (`tenant_id`,`module_key`,`workflow_key`,`subject_type`,`subject_key`,`active_marker`),
  KEY `idx_workflow_instance_status` (`tenant_id`,`status`,`updated_at`,`id`),
  KEY `idx_workflow_instance_subject` (`tenant_id`,`subject_type`,`subject_key`,`id`),
  KEY `fk_workflow_instance_version` (`tenant_id`,`definition_id`,`definition_version`),
  CONSTRAINT `fk_workflow_instance_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_workflow_instance_version` FOREIGN KEY (`tenant_id`, `definition_id`, `definition_version`) REFERENCES `pa_workflow_definition_version` (`tenant_id`, `definition_id`, `version`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_instance_digest` CHECK (regexp_like(`subject_revision_sha256`,_ascii'^[0-9a-f]{64}$')),
  CONSTRAINT `chk_workflow_instance_key` CHECK (regexp_like(`instance_key`,_ascii'^instance_[0-9a-f]{32}$')),
  CONSTRAINT `chk_workflow_instance_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_workflow_instance_status` CHECK ((`status` in (_utf8mb4'running',_utf8mb4'blocked',_utf8mb4'completed',_utf8mb4'cancelled',_utf8mb4'terminated'))),
  CONSTRAINT `chk_workflow_instance_version` CHECK ((`definition_version` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_workflow_event' => <<<'SQL'
CREATE TABLE `pa_workflow_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `instance_id` bigint unsigned NOT NULL,
  `sequence_no` int unsigned NOT NULL,
  `event_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transition_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `from_node_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `to_node_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_member_id` bigint unsigned DEFAULT NULL,
  `subject_revision_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_revision_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `comment_text` varchar(2000) DEFAULT NULL,
  `comment_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `attachment_snapshots_json` json NOT NULL,
  `metadata_json` json NOT NULL,
  `occurred_at` datetime(3) NOT NULL,
  `notification_fact_json` json DEFAULT NULL,
  `notification_outbox_json` json DEFAULT NULL,
  `notification_last_error` varchar(64) DEFAULT NULL,
  `node_execution_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_event_sequence` (`tenant_id`,`instance_id`,`sequence_no`),
  KEY `idx_workflow_event_instance_time` (`tenant_id`,`instance_id`,`occurred_at`,`id`),
  KEY `idx_workflow_event_key_time` (`tenant_id`,`event_key`,`occurred_at`,`id`),
  CONSTRAINT `fk_workflow_event_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_event_actor_shape` CHECK ((((`actor_type` = _ascii'member') and (`actor_member_id` is not null)) or ((`actor_type` = _ascii'tenant_system') and (`actor_member_id` is null)))),
  CONSTRAINT `chk_workflow_event_comment_shape` CHECK ((((`comment_text` is null) and (`comment_sha256` is null)) or ((`comment_text` is not null) and regexp_like(`comment_sha256`,_ascii'^[0-9a-f]{64}$')))),
  CONSTRAINT `chk_workflow_event_key` CHECK (regexp_like(`event_key`,_ascii'^tenant\\.workflow\\.[a-z_]+$')),
  CONSTRAINT `chk_workflow_event_sequence` CHECK ((`sequence_no` >= 1)),
  CONSTRAINT `chk_workflow_event_subject_digest` CHECK (regexp_like(`subject_revision_sha256`,_ascii'^[0-9a-f]{64}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_workflow_node_execution' => <<<'SQL'
CREATE TABLE `pa_workflow_node_execution` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '节点执行ID',
  `tenant_id` bigint unsigned NOT NULL,
  `instance_id` bigint unsigned NOT NULL,
  `node_execution_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `node_key` varchar(64) NOT NULL,
  `sequence_no` int unsigned NOT NULL,
  `cycle_no` int unsigned NOT NULL,
  `previous_actor_member_id` bigint unsigned DEFAULT NULL COMMENT '明确前序单人办理者',
  `node_type` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `allocation_mode` varchar(16) DEFAULT NULL,
  `aggregation_policy` varchar(32) DEFAULT NULL,
  `input_json` json NOT NULL COMMENT '固定本次求值输入',
  `action_input_json` json DEFAULT NULL COMMENT '固定已解析业务动作输入',
  `started_at` datetime(3) DEFAULT NULL COMMENT '系统动作首次开始，失败后保留',
  `people_json` json NOT NULL COMMENT '冻结人员与来源',
  `output_json` json DEFAULT NULL,
  `selected_transition_key` varchar(64) DEFAULT NULL,
  `blocked_reason` varchar(500) DEFAULT NULL,
  `effect_key` varchar(200) DEFAULT NULL,
  `effect_receipt_json` json DEFAULT NULL,
  `task_job_key` varchar(64) DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_node_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_workflow_node_key` (`node_execution_key`),
  UNIQUE KEY `uk_workflow_node_sequence` (`tenant_id`,`instance_id`,`sequence_no`),
  CONSTRAINT `fk_workflow_node_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_node_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_workflow_node_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'active',_utf8mb4'blocked',_utf8mb4'completed',_utf8mb4'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='工作流节点执行';
SQL,
        'pa_workflow_work_item' => <<<'SQL'
CREATE TABLE `pa_workflow_work_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `work_item_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` bigint unsigned NOT NULL,
  `instance_id` bigint unsigned NOT NULL,
  `node_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `round_no` int unsigned NOT NULL,
  `assignment_source_kind` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignment_source_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `assignee_member_id` bigint unsigned DEFAULT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  `decision` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `completed_by_member_id` bigint unsigned DEFAULT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  `completed_at` datetime(3) DEFAULT NULL,
  `cancelled_at` datetime(3) DEFAULT NULL,
  `pending_marker` tinyint GENERATED ALWAYS AS ((case when (`status` = _ascii'pending') then 1 else NULL end)) STORED,
  `node_execution_id` bigint unsigned NOT NULL,
  `owner_member_id` bigint unsigned DEFAULT NULL,
  `owner_source` varchar(16) NOT NULL DEFAULT 'automatic',
  `assignment_state` varchar(16) NOT NULL DEFAULT 'unassigned',
  `seat_no` int unsigned NOT NULL,
  `sources_json` json NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_work_item_key` (`work_item_key`),
  UNIQUE KEY `uk_workflow_work_item_tenant_id` (`tenant_id`,`id`),
  UNIQUE KEY `uk_workflow_work_item_seat` (`tenant_id`,`node_execution_id`,`seat_no`),
  UNIQUE KEY `uk_workflow_work_item_assignee_once` (`tenant_id`,`node_execution_id`,`assignee_member_id`),
  KEY `idx_workflow_work_item_assignee` (`tenant_id`,`assignee_member_id`,`status`,`created_at`,`id`),
  KEY `idx_workflow_work_item_instance` (`tenant_id`,`instance_id`,`status`,`id`),
  CONSTRAINT `fk_workflow_work_item_instance` FOREIGN KEY (`tenant_id`, `instance_id`) REFERENCES `pa_workflow_instance` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_workflow_work_item_node` FOREIGN KEY (`tenant_id`, `node_execution_id`) REFERENCES `pa_workflow_node_execution` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_work_item_assignment` CHECK ((((`assignment_state` = _utf8mb4'unassigned') and (`assignee_member_id` is null)) or ((`assignment_state` = _utf8mb4'assigned') and (`assignee_member_id` is not null)))),
  CONSTRAINT `chk_workflow_work_item_key` CHECK (regexp_like(`work_item_key`,_ascii'^work_[0-9a-f]{32}$')),
  CONSTRAINT `chk_workflow_work_item_revision` CHECK ((`revision` >= 1)),
  CONSTRAINT `chk_workflow_work_item_round` CHECK ((`round_no` >= 1)),
  CONSTRAINT `chk_workflow_work_item_status` CHECK ((`status` in (_ascii'pending',_ascii'completed',_ascii'cancelled'))),
  CONSTRAINT `chk_workflow_work_item_terminal_shape` CHECK ((((`status` = _ascii'pending') and (`decision` is null) and (`completed_by_member_id` is null) and (`completed_at` is null) and (`cancelled_at` is null)) or ((`status` = _ascii'completed') and (`decision` is not null) and (`completed_by_member_id` is not null) and (`completed_at` is not null) and (`cancelled_at` is null)) or ((`status` = _ascii'cancelled') and (`decision` is null) and (`completed_by_member_id` is null) and (`completed_at` is null) and (`cancelled_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
SQL,
        'pa_workflow_task_identity_link' => <<<'SQL'
CREATE TABLE `pa_workflow_task_identity_link` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `work_item_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `relation_type` varchar(16) NOT NULL COMMENT '候选、参与、抄送',
  `sources_json` json NOT NULL COMMENT '来源集合及绑定修订',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workflow_identity_relation` (`tenant_id`,`work_item_id`,`member_id`,`relation_type`),
  KEY `idx_workflow_identity_member` (`tenant_id`,`member_id`,`relation_type`,`work_item_id`),
  CONSTRAINT `fk_workflow_identity_item` FOREIGN KEY (`tenant_id`, `work_item_id`) REFERENCES `pa_workflow_work_item` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_workflow_identity_type` CHECK ((`relation_type` in (_utf8mb4'candidate',_utf8mb4'participant',_utf8mb4'watcher')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='任务人员关系';
SQL,
    ];

    public function up(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialWorkflowSchema requires the native PDO adapter.');
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
