<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Tests\Integration\Persistence;

use PDO;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowInstanceRecord;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord;
use PeanutAdmin\Modules\Workflow\Tests\Integration\Application\WorkflowMysqlTestCase;
use PeanutAdmin\Modules\Workflow\Tests\Integration\Application\WorkflowCapabilityCompositionTest;
use PeanutAdmin\Modules\Workflow\Tests\Unit\Definition\WorkflowGraphTest;

require_once dirname(__DIR__) . '/Application/WorkflowRuntimeTest.php';

final class WorkflowStoreTest extends WorkflowMysqlTestCase
{
    public function testNativeInitialMigrationCanReenterWithoutChangingTenantOrWorkflowData(): void
    {
        $this->publish();
        $instance = $this->start();
        $before = $this->state();
        $this->install();
        self::assertSame($before, $this->state());
        self::assertSame('workflow-1', $this->pdo->query('SELECT code FROM pa_tenant WHERE id=1')->fetchColumn());
        self::assertSame($instance['instance_key'], $this->queries->instance($this->context(), $instance['instance_key'])['instance_key']);
    }

    public function testInformationSchemaMatchesExactColumnsGeneratedColumnsAndIndexes(): void
    {
        self::assertSame([
            'pa_workflow_definition:id,tenant_id,module_key,workflow_key,status,draft_graph_json,draft_graph_sha256,latest_version,revision,created_by_member_id,updated_by_member_id,created_at,updated_at,retired_at,managed_template_json,layout_json,active_version',
            'pa_workflow_definition_version:id,tenant_id,definition_id,version,graph_json,graph_sha256,published_by_member_id,published_at,contract_version',
            'pa_workflow_event:id,tenant_id,instance_id,sequence_no,event_key,transition_key,from_node_key,to_node_key,actor_type,actor_member_id,subject_revision_key,subject_revision_sha256,comment_text,comment_sha256,attachment_snapshots_json,metadata_json,occurred_at,notification_fact_json,notification_outbox_json,notification_last_error,node_execution_id',
            'pa_workflow_instance:id,instance_key,tenant_id,definition_id,definition_version,subject_type,subject_key,subject_revision_key,subject_revision_sha256,current_node_key,status,initiated_by_member_id,last_actor_member_id,revision,created_at,updated_at,completed_at,cancelled_at,module_key,workflow_key,business_department_key,snapshot_json,cycle_no,result_code,blocked_reason,substantive_at,active_marker',
            'pa_workflow_node_execution:id,tenant_id,instance_id,node_execution_key,node_key,sequence_no,cycle_no,previous_actor_member_id,node_type,status,revision,allocation_mode,aggregation_policy,input_json,action_input_json,started_at,people_json,output_json,selected_transition_key,blocked_reason,effect_key,effect_receipt_json,task_job_key,created_at,completed_at',
            'pa_workflow_task_identity_link:id,tenant_id,work_item_id,member_id,relation_type,sources_json',
            'pa_workflow_work_item:id,work_item_key,tenant_id,instance_id,node_key,round_no,assignment_source_kind,assignment_source_key,assignee_member_id,status,decision,completed_by_member_id,revision,created_at,updated_at,completed_at,cancelled_at,pending_marker,node_execution_id,owner_member_id,owner_source,assignment_state,seat_no,sources_json',
        ], $this->columnSignatures());
        self::assertSame([
            'pa_workflow_instance:active_marker:STORED GENERATED:active',
            'pa_workflow_work_item:pending_marker:STORED GENERATED:pending',
        ], $this->generatedColumnSignatures());
        self::assertSame([
            'pa_workflow_definition:PRIMARY:unique:id',
            'pa_workflow_definition:idx_workflow_definition_status:non-unique:tenant_id,status,id',
            'pa_workflow_definition:uk_workflow_definition_identity:unique:tenant_id,module_key,workflow_key',
            'pa_workflow_definition:uk_workflow_definition_tenant_id:unique:tenant_id,id',
            'pa_workflow_definition_version:PRIMARY:unique:id',
            'pa_workflow_definition_version:idx_workflow_definition_version_published:non-unique:tenant_id,published_at,id',
            'pa_workflow_definition_version:uk_workflow_definition_version_digest:unique:tenant_id,definition_id,graph_sha256',
            'pa_workflow_definition_version:uk_workflow_definition_version_number:unique:tenant_id,definition_id,version',
            'pa_workflow_definition_version:uk_workflow_definition_version_tenant_id:unique:tenant_id,id',
            'pa_workflow_event:PRIMARY:unique:id',
            'pa_workflow_event:idx_workflow_event_instance_time:non-unique:tenant_id,instance_id,occurred_at,id',
            'pa_workflow_event:idx_workflow_event_key_time:non-unique:tenant_id,event_key,occurred_at,id',
            'pa_workflow_event:uk_workflow_event_sequence:unique:tenant_id,instance_id,sequence_no',
            'pa_workflow_instance:PRIMARY:unique:id',
            'pa_workflow_instance:fk_workflow_instance_version:non-unique:tenant_id,definition_id,definition_version',
            'pa_workflow_instance:idx_workflow_instance_status:non-unique:tenant_id,status,updated_at,id',
            'pa_workflow_instance:idx_workflow_instance_subject:non-unique:tenant_id,subject_type,subject_key,id',
            'pa_workflow_instance:uk_workflow_instance_active_subject:unique:tenant_id,module_key,workflow_key,subject_type,subject_key,active_marker',
            'pa_workflow_instance:uk_workflow_instance_key:unique:instance_key',
            'pa_workflow_instance:uk_workflow_instance_tenant_id:unique:tenant_id,id',
            'pa_workflow_node_execution:PRIMARY:unique:id',
            'pa_workflow_node_execution:uk_workflow_node_key:unique:node_execution_key',
            'pa_workflow_node_execution:uk_workflow_node_sequence:unique:tenant_id,instance_id,sequence_no',
            'pa_workflow_node_execution:uk_workflow_node_tenant_id:unique:tenant_id,id',
            'pa_workflow_task_identity_link:PRIMARY:unique:id',
            'pa_workflow_task_identity_link:idx_workflow_identity_member:non-unique:tenant_id,member_id,relation_type,work_item_id',
            'pa_workflow_task_identity_link:uk_workflow_identity_relation:unique:tenant_id,work_item_id,member_id,relation_type',
            'pa_workflow_work_item:PRIMARY:unique:id',
            'pa_workflow_work_item:idx_workflow_work_item_assignee:non-unique:tenant_id,assignee_member_id,status,created_at,id',
            'pa_workflow_work_item:idx_workflow_work_item_instance:non-unique:tenant_id,instance_id,status,id',
            'pa_workflow_work_item:uk_workflow_work_item_assignee_once:unique:tenant_id,node_execution_id,assignee_member_id',
            'pa_workflow_work_item:uk_workflow_work_item_key:unique:work_item_key',
            'pa_workflow_work_item:uk_workflow_work_item_seat:unique:tenant_id,node_execution_id,seat_no',
            'pa_workflow_work_item:uk_workflow_work_item_tenant_id:unique:tenant_id,id',
        ], $this->indexSignatures());
    }

    public function testInformationSchemaMatchesExactForeignKeysAndChecks(): void
    {
        self::assertSame([
            'pa_workflow_definition:fk_workflow_definition_tenant:tenant_id:pa_tenant:id:RESTRICT',
            'pa_workflow_definition_version:fk_workflow_definition_version_definition:tenant_id,definition_id:pa_workflow_definition:tenant_id,id:RESTRICT',
            'pa_workflow_event:fk_workflow_event_instance:tenant_id,instance_id:pa_workflow_instance:tenant_id,id:RESTRICT',
            'pa_workflow_instance:fk_workflow_instance_tenant:tenant_id:pa_tenant:id:RESTRICT',
            'pa_workflow_instance:fk_workflow_instance_version:tenant_id,definition_id,definition_version:pa_workflow_definition_version:tenant_id,definition_id,version:RESTRICT',
            'pa_workflow_node_execution:fk_workflow_node_instance:tenant_id,instance_id:pa_workflow_instance:tenant_id,id:RESTRICT',
            'pa_workflow_task_identity_link:fk_workflow_identity_item:tenant_id,work_item_id:pa_workflow_work_item:tenant_id,id:RESTRICT',
            'pa_workflow_work_item:fk_workflow_work_item_instance:tenant_id,instance_id:pa_workflow_instance:tenant_id,id:RESTRICT',
            'pa_workflow_work_item:fk_workflow_work_item_node:tenant_id,node_execution_id:pa_workflow_node_execution:tenant_id,id:RESTRICT',
        ], $this->foreignKeySignatures());
        self::assertSame([
            'pa_workflow_definition:chk_workflow_definition_digest',
            'pa_workflow_definition:chk_workflow_definition_module_key',
            'pa_workflow_definition:chk_workflow_definition_retired_shape',
            'pa_workflow_definition:chk_workflow_definition_revision',
            'pa_workflow_definition:chk_workflow_definition_status',
            'pa_workflow_definition:chk_workflow_definition_workflow_key',
            'pa_workflow_definition_version:chk_workflow_definition_version_digest',
            'pa_workflow_definition_version:chk_workflow_definition_version_number',
            'pa_workflow_event:chk_workflow_event_actor_shape',
            'pa_workflow_event:chk_workflow_event_comment_shape',
            'pa_workflow_event:chk_workflow_event_key',
            'pa_workflow_event:chk_workflow_event_sequence',
            'pa_workflow_event:chk_workflow_event_subject_digest',
            'pa_workflow_instance:chk_workflow_instance_digest',
            'pa_workflow_instance:chk_workflow_instance_key',
            'pa_workflow_instance:chk_workflow_instance_revision',
            'pa_workflow_instance:chk_workflow_instance_status',
            'pa_workflow_instance:chk_workflow_instance_version',
            'pa_workflow_node_execution:chk_workflow_node_revision',
            'pa_workflow_node_execution:chk_workflow_node_status',
            'pa_workflow_task_identity_link:chk_workflow_identity_type',
            'pa_workflow_work_item:chk_workflow_work_item_assignment',
            'pa_workflow_work_item:chk_workflow_work_item_key',
            'pa_workflow_work_item:chk_workflow_work_item_revision',
            'pa_workflow_work_item:chk_workflow_work_item_round',
            'pa_workflow_work_item:chk_workflow_work_item_status',
            'pa_workflow_work_item:chk_workflow_work_item_terminal_shape',
        ], $this->checkConstraintSignatures());
    }

    public function testMysqlJsonNormalizationUsesThePersistedCanonicalDigest(): void
    {
        $this->publish();
        $instance = $this->start();
        $scope = TenantScope::fromTrustedContext(1, 'workflow-test');
        $version = WorkflowDefinitionVersionRecord::scope('tenant', $scope)->find();
        self::assertSame(WorkflowGraph::fromArray(WorkflowGraphTest::validGraph(true))->sha256, WorkflowGraph::fromArray($version['graph_json'])->sha256);
        self::assertSame($version['graph_sha256'], WorkflowGraph::fromArray($version['graph_json'])->sha256);
        $this->pdo->exec("UPDATE pa_workflow_definition_version SET graph_sha256=REPEAT('0',64)");
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('INTERNAL_ERROR', fn() => $this->queries->instance($this->context(), $instance['instance_key']));
    }

    public function testCorruptedDraftDigestCannotPublishOrChangePersistedState(): void
    {
        $this->publish();
        $this->pdo->exec("UPDATE pa_workflow_definition SET draft_graph_sha256=REPEAT('0',64)");
        $before = $this->state();
        WorkflowCapabilityCompositionTest::assertWorkflowFailure('INTERNAL_ERROR', fn() => $this->definitions->publish($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => 2], 'workflow-corrupted-publish'));
        self::assertSame($before, $this->state());
    }

    public function testPersistsImmutableVersionPinnedInstanceWorkItemsAndEvents(): void
    {
        $this->publish();
        $instance = $this->start();
        $scope = TenantScope::fromTrustedContext(1, 'workflow-test');
        $stored = WorkflowInstanceRecord::scope('tenant', $scope)->where('instance_key', $instance['instance_key'])->find();
        self::assertSame(1, (int) $stored['definition_version']);
        self::assertNull(WorkflowInstanceRecord::scope('tenant', TenantScope::fromTrustedContext(2, 'workflow-test'))->where('instance_key', $instance['instance_key'])->find());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_workflow_work_item')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_workflow_node_execution')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT sequence_no FROM pa_workflow_event')->fetchColumn());
        $graph = WorkflowGraphTest::validGraph(true);
        $graph['nodes'][3]['label'] = 'Done version two';
        $draft = $this->definitions->saveDraft($this->context('write'), 'module.sample', 'approval', ['expected_revision' => 2, 'graph' => $graph, 'layout' => ['nodes' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]]], 'workflow-draft-two');
        $second = $this->definitions->publish($this->context('publish'), 'module.sample', 'approval', ['expected_revision' => $draft['draft_revision']], 'workflow-publish-two');
        self::assertSame(2, $second['version']);
        self::assertSame(1, (int) WorkflowInstanceRecord::scope('tenant', $scope)->where('instance_key', $instance['instance_key'])->value('definition_version'));
        $first = WorkflowDefinitionVersionRecord::scope('tenant', $scope)->where('version', 1)->find();
        self::assertSame('Done', WorkflowGraph::fromArray($first['graph_json'])->node('done')['label']);
        self::assertSame('Done version two', WorkflowGraph::fromArray(WorkflowDefinitionVersionRecord::scope('tenant', $scope)->where('version', 2)->find()['graph_json'])->node('done')['label']);
    }

    /** @return list<string> */
    private function columnSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name AS table_name, GROUP_CONCAT(column_name ORDER BY ordinal_position SEPARATOR ',') AS columns
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
GROUP BY table_name
ORDER BY table_name
SQL);
        self::assertNotFalse($statement);

        return array_map(
            static fn(array $row): string => (string) $row['table_name'] . ':' . (string) $row['columns'],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return list<string> */
    private function generatedColumnSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name AS table_name, column_name AS column_name, extra AS extra,
       generation_expression AS generation_expression
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
  AND generation_expression <> ''
ORDER BY table_name, ordinal_position
SQL);
        self::assertNotFalse($statement);

        return array_map(static function (array $row): string {
            $expression = strtolower((string) $row['generation_expression']);
            $state = str_contains($expression, 'running') ? 'active' : 'pending';
            if (!str_contains($expression, 'status')
                || !str_contains($expression, $state === 'active' ? 'running' : 'pending')
                || !str_contains($expression, 'then 1')
                || !str_contains($expression, 'else null')) {
                $state = 'invalid';
            }

            return implode(':', [
                (string) $row['table_name'],
                (string) $row['column_name'],
                (string) $row['extra'],
                $state,
            ]);
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> */
    private function indexSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_name AS table_name, index_name AS index_name, non_unique AS non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_workflow_%'
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name
SQL);
        self::assertNotFalse($statement);

        $signatures = array_map(
            static fn(array $row): string => implode(':', [
                (string) $row['table_name'],
                (string) $row['index_name'],
                (int) $row['non_unique'] === 0 ? 'unique' : 'non-unique',
                (string) $row['columns'],
            ]),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
        sort($signatures, SORT_STRING);

        return $signatures;
    }

    /** @return list<string> */
    private function foreignKeySignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT k.table_name, k.constraint_name,
       GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position SEPARATOR ',') AS columns,
       k.referenced_table_name,
       GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position SEPARATOR ',') AS referenced_columns,
       r.delete_rule
FROM information_schema.key_column_usage k
JOIN information_schema.referential_constraints r
  ON r.constraint_schema = k.constraint_schema
 AND r.constraint_name = k.constraint_name
WHERE k.constraint_schema = DATABASE() AND k.table_name LIKE 'pa_workflow_%'
GROUP BY k.table_name, k.constraint_name, k.referenced_table_name, r.delete_rule
ORDER BY k.table_name, k.constraint_name
SQL);
        self::assertNotFalse($statement);

        return array_map(
            static fn(array $row): string => implode(':', array_map('strval', $row)),
            $statement->fetchAll(PDO::FETCH_NUM),
        );
    }

    /** @return list<string> */
    private function checkConstraintSignatures(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT tc.table_name AS table_name, tc.constraint_name AS constraint_name, cc.check_clause AS check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.constraint_schema = DATABASE()
  AND tc.table_name LIKE 'pa_workflow_%'
  AND tc.constraint_type = 'CHECK'
ORDER BY tc.table_name, tc.constraint_name
SQL);
        self::assertNotFalse($statement);

        return array_map(static function (array $row): string {
            $signature = (string) $row['table_name'] . ':' . (string) $row['constraint_name'];

            return trim((string) $row['check_clause']) === '' ? $signature . ':missing-clause' : $signature;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

}
