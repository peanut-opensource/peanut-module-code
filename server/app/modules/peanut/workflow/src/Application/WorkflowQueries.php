<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionRecord as Definition;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord as Version;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowInstanceRecord as Instance;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowNodeExecutionRecord as Node;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowEventRecord as Event;
use think\facade\Db;

final readonly class WorkflowQueries
{
    public function __construct(private WorkflowAuthorizationResolver $authorization, private WorkflowAssignmentResolver $assignments, private WorkflowLifecycleQueries $lifecycle, private \PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider $scenarios, private ?\app\common\contract\authorization\AdminAuthorizationQuery $permissions = null) {}

    /** Runtime projection is authorized against the active graph and current business object. */
    public function activeDefinitionSummary(AuthorizedOperationContext $c, string $moduleKey, string $workflowKey, string $subjectType, string $subjectKey): array
    {
        $this->lifecycle->assertNewOperations($c->tenantContext->tenantId);
        if ($c->resourceKey !== 'peanut.workflow.instance' || $c->operation !== 'start' || $c->targets !== []
            || $subjectType === '' || $subjectKey === '' || strlen($subjectType) > 160 || strlen($subjectKey) > 160) {
            throw WorkflowException::subjectNotFound();
        }
        $row = Definition::scope('tenant', $this->scope($c))->where('module_key', $moduleKey)->where('workflow_key', $workflowKey)->find();
        if ($row === null || $row['status'] !== 'active' || $row['active_version'] === null) {
            throw WorkflowException::subjectNotFound();
        }
        $version = Version::scope('tenant', $this->scope($c))->where('definition_id', $row['id'])->where('version', $row['active_version'])->find() ?? throw WorkflowException::subjectNotFound();
        $graph = WorkflowGraph::fromArray($version['graph_json']);
        if (!hash_equals($graph->sha256, $version['graph_sha256']) || $subjectType !== $graph->data['subject_resource_key']
            || !$this->allowed($c, $graph, $subjectKey, $graph->data['subject_read_operation'], ['peanut.workflow.instance.start'])
            || !$this->allowed($c, $graph, $subjectKey, $graph->data['subject_start_operation'], ['peanut.workflow.instance.start', ...$graph->data['start_permission_keys']])) {
            throw WorkflowException::subjectNotFound();
        }
        return ['definition_id' => (string) $row['id'], 'module_key' => $row['module_key'], 'workflow_key' => $row['workflow_key'], 'status' => 'active', 'draft_revision' => (int) $row['revision'], 'active_version' => (int) $row['active_version']];
    }

    public function definitions(AuthorizedOperationContext $c, int $page = 1, int $pageSize = 20): array
    {
        $this->definitionContext($c);
        $this->page($page, $pageSize);
        $query = Definition::scope('tenant', $this->scope($c));
        return ['items' => array_map(fn($row): array => $this->definitionSummary($row->toArray()), $query->order('id', 'desc')->page($page, $pageSize)->select()->all()), 'page' => $page, 'page_size' => $pageSize, 'total' => Definition::scope('tenant', $this->scope($c))->count()];
    }

    public function definition(AuthorizedOperationContext $c, string $moduleKey, string $workflowKey): array
    {
        $this->definitionContext($c);
        $row = Definition::scope('tenant', $this->scope($c))->where('module_key', $moduleKey)->where('workflow_key', $workflowKey)->find() ?? throw WorkflowException::subjectNotFound();
        $result = $this->definitionSummary($row->toArray());
        $result['graph'] = $row['draft_graph_json'];
        $result['layout'] = $row['layout_json'];
        $result['versions'] = array_map(static fn($v): array => ['version' => (int) $v['version'], 'graph_sha256' => $v['graph_sha256'], 'published_at' => self::utc($v['published_at'])], Version::scope('tenant', $this->scope($c))->where('definition_id', $row['id'])->order('version', 'desc')->select()->all());
        return $result;
    }

    public function instance(AuthorizedOperationContext $c, string $instanceKey): array
    {
        [$row, $graph] = $this->visibleInstance($c, $instanceKey);
        $active = Node::scope('tenant', $this->scope($c))->where('instance_id', $row['id'])->whereIn('status', ['pending', 'active', 'blocked'])->order('sequence_no')->select();
        $nodes = array_map(static fn($n): array => ['node_execution_key' => $n['node_execution_key'], 'node_key' => $n['node_key'], 'label' => $graph->node($n['node_key'])['label'], 'type' => $n['node_type'], 'status' => $n['status'], 'revision' => (int) $n['revision'], 'cycle_no' => (int) $n['cycle_no'], 'task_termination_evidence_required' => $n['effect_key'] !== null && $n['effect_receipt_json'] === null && $n['task_job_key'] !== null], $active->all());
        $items = $this->itemQuery($c)->where('i.id', $row['id']);
        $this->own($c, $items, null);
        $own = array_map(fn(array $item): array => $this->itemSummary($c, $item), $items->order('w.id')->select()->toArray());
        $reassignable = [];
        if (in_array($row['status'], ['running', 'blocked'], true)
            && $this->allowed($c, $graph, $row['subject_key'], 'reassign', ['peanut.workflow.diagnostics.reassign'])) {
            // Maintenance is object-authorized, independent of the maintainer's own responsibilities.
            $pending = Db::name('workflow_work_item')->alias('w')
                ->join('workflow_node_execution n', 'n.id=w.node_execution_id AND n.tenant_id=w.tenant_id')
                ->where('w.tenant_id', $c->tenantContext->tenantId)->where('w.instance_id', $row['id'])
                ->where('w.status', 'pending')->whereIn('n.status', ['active', 'blocked'])
                ->field('w.work_item_key,w.revision,n.node_key')->order('w.id')->limit(101)->select()->toArray();
            if (count($pending) > 100) { throw WorkflowException::blocked('Pending assignment capacity exceeded.'); }
            $reassignable = array_map(static fn(array $item): array => [
                'work_item_key' => $item['work_item_key'], 'revision' => (int) $item['revision'],
                'label' => $graph->node($item['node_key'])['label'],
            ], $pending);
        }
        $actions = [];
        if (in_array($row['status'], ['running', 'blocked'], true)) {
            foreach (['withdraw', 'terminate', 'retry', 'reassign'] as $action) {
                if ($action === 'terminate') {
                    $unknownEffect = false;
                    foreach ($active as $execution) {
                        if ($execution['task_job_key'] !== null && $execution['effect_receipt_json'] === null && !str_starts_with((string) $execution['blocked_reason'], 'TASK_CANCELLED:')) {
                            $unknownEffect = true;
                        }
                        if ($execution['task_job_key'] !== null && $execution['effect_receipt_json'] === null && !$this->taskManagementAllowed($c)) {
                            $unknownEffect = true;
                        }
                    }
                    if ($unknownEffect) {
                        continue;
                    }
                }
                if ($action === 'withdraw' && ((int) $row['initiated_by_member_id'] !== $c->tenantContext->memberId || $row['substantive_at'] !== null)) {
                    continue;
                }
                if ($action === 'retry' && $row['status'] !== 'blocked') {
                    continue;
                }
                if ($action === 'retry') {
                    foreach ($active as $execution) {
                        if ($execution['task_job_key'] !== null || $execution['people_json'] !== []) { continue 2; }
                    }
                }
                if ($action === 'reassign' && $reassignable === []) { continue; }
                $part = in_array($action, ['retry', 'reassign'], true) ? 'diagnostics' : 'instance';
                if ($this->allowed($c, $graph, $row['subject_key'], $action, ['peanut.workflow.' . $part . '.' . $action])) {
                    $actions[] = $action;
                }
            }
        }
        $blocker = null;
        if ($this->allowed($c, $graph, $row['subject_key'], $graph->data['subject_read_operation'], ['peanut.workflow.diagnostics.read']) && $this->permissions !== null && $this->permissions->decide($c->tenantContext, $this->permissions->principal($c->tenantContext), 'peanut.task.jobs.read')->allowed) {
            $actions[] = 'diagnostics';
        }
        if ($row['status'] === 'blocked') {
            $blocker = ['code' => 'ACTION_BLOCKED', 'message' => $row['blocked_reason'], 'node_execution_key' => $nodes[0]['node_execution_key'] ?? ''];
        }
        return ['instance_key' => $row['instance_key'], 'status' => $row['status'], 'result_code' => $row['result_code'], 'revision' => (int) $row['revision'], 'subject_type' => $row['subject_type'], 'subject_key' => $row['subject_key'], 'subject_revision_key' => $row['subject_revision_key'], 'active_nodes' => $nodes, 'own_work_items' => $own, 'reassign_work_items' => $reassignable, 'available_actions' => $actions, 'event_cursor' => (int) Event::scope('tenant', $this->scope($c))->where('instance_id', $row['id'])->max('sequence_no'), 'blocker' => $blocker];
    }

    /** Current-authorized frozen materials; preparing owner-native versions never occurs inside command transactions. */
    public function materials(AuthorizedOperationContext $c, string $instanceKey): array
    {
        [$row, $graph] = $this->visibleInstance($c, $instanceKey);
        $authorized = $this->authorization->authorize($c, $graph->data['subject_resource_key'], $graph->data['subject_read_operation'], ['peanut.workflow.instance.read'], $row['subject_key']);
        // The scenario provider is injected directly: this read is a real owner boundary, not the stored instance JSON.
        $prepared = $this->scenarios->prepareSubjectSnapshot($authorized, $row['subject_type'], $row['subject_key'], $row['subject_revision_key']);
        $snapshot = Db::transaction(function () use ($c, $authorized, $row, $prepared, $graph): array {
            $this->lifecycle->assertAvailable($c->tenantContext->tenantId, true);
            $current = $this->authorization->authorize($authorized, $graph->data['subject_resource_key'], $graph->data['subject_read_operation'], ['peanut.workflow.instance.read'], $row['subject_key']);
            $snapshot = $this->scenarios->snapshot($current, $row['subject_type'], $row['subject_key'], $row['subject_revision_key'], true, $prepared);
            $instance = Instance::scope('tenant', $this->scope($c))->where('id', $row['id'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
            if ($instance['subject_revision_key'] !== $row['subject_revision_key'] || $instance['subject_revision_sha256'] !== $row['subject_revision_sha256']) { throw WorkflowException::subjectRevisionConflict(); }
            return $snapshot;
        });
        if (($snapshot['revision_key'] ?? null) !== $row['subject_revision_key'] || ($snapshot['sha256'] ?? null) !== $row['subject_revision_sha256']
            || !is_array($snapshot['fields'] ?? null) || !is_array($snapshot['reference'] ?? null)
            || strlen(WorkflowGraph::json($snapshot)) > 65535) { throw WorkflowException::subjectRevisionConflict(); }
        $scenario = $this->scenarios->scenario($authorized, $row['module_key'], $row['workflow_key']);
        return ['revision_key' => $snapshot['revision_key'], 'sha256' => $snapshot['sha256'], 'fields' => (object) array_intersect_key($snapshot['fields'], $scenario['fields']), 'reference' => (object) $snapshot['reference']];
    }

    public function inbox(AuthorizedOperationContext $c, string $filter = 'assigned', int $page = 1, int $pageSize = 20): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        $this->page($page, $pageSize);
        if (!in_array($filter, ['assigned', 'claimable', 'completed', 'initiated', 'participant', 'watcher'], true)) {
            throw WorkflowException::definitionInvalid('Invalid inbox filter.');
        }
        if ($filter === 'initiated') {
            $query = Db::name('workflow_instance')->alias('i')->where('i.tenant_id', $c->tenantContext->tenantId)->where('i.initiated_by_member_id', $c->tenantContext->memberId);
            $this->visibility($c, $query);
            $total = (clone $query)->count();
            // No per-row secondary queries: initiated inbox contains the minimal instance projection.
            $items = $query->field('i.instance_key,i.status,i.result_code,i.revision,i.subject_type,i.subject_key,i.subject_revision_key')->order('i.id', 'desc')->page($page, $pageSize)->select()->toArray();
            foreach ($items as &$item) {
                $item['revision'] = (int) $item['revision'];
                $item += ['active_nodes' => [], 'own_work_items' => [], 'reassign_work_items' => [], 'available_actions' => [], 'event_cursor' => 0, 'blocker' => null];
            }
        } else {
            $query = $this->itemQuery($c);
            $this->visibility($c, $query);
            $this->own($c, $query, $filter);
            $total = (clone $query)->count('w.id');
            $items = array_map(fn(array $row): array => $this->itemProjection($row, WorkflowGraph::fromArray(self::decode($row['graph_json']))), $query->order('w.id', 'desc')->page($page, $pageSize)->select()->toArray());
        }
        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public function events(AuthorizedOperationContext $c, string $instanceKey, int $afterSequence = 0, int $pageSize = 20): array
    {
        $this->page(1, $pageSize);
        if ($afterSequence < 0) {
            throw WorkflowException::definitionInvalid();
        }
        [$row] = $this->visibleInstance($c, $instanceKey);
        $rows = Db::name('workflow_event')->alias('e')->leftJoin('workflow_node_execution n', 'n.id=e.node_execution_id AND n.tenant_id=e.tenant_id')->where('e.tenant_id', $c->tenantContext->tenantId)->where('e.instance_id', $row['id'])->where('e.sequence_no', '>', $afterSequence)->field('e.sequence_no,e.event_key,n.node_execution_key,e.actor_member_id,e.comment_text,e.metadata_json,e.attachment_snapshots_json,e.occurred_at')->order('e.sequence_no')->limit($pageSize)->select()->toArray();
        return array_map(static fn(array $e): array => ['sequence_no' => (int) $e['sequence_no'], 'event_key' => $e['event_key'], 'node_execution_key' => $e['node_execution_key'], 'actor_member_id' => $e['actor_member_id'] === null ? null : (string) $e['actor_member_id'], 'comment' => $e['comment_text'], 'metadata' => self::decode($e['metadata_json']), 'attachments' => self::eventAttachments($e['attachment_snapshots_json']), 'occurred_at' => self::utc($e['occurred_at'])], $rows);
    }

    /** Historical labels are not current File grants or delivery addresses. */
    private static function eventAttachments(mixed $value): array
    {
        $snapshots = self::decode($value);
        if (!array_is_list($snapshots) || count($snapshots) > 100) {
            throw WorkflowException::attachmentUnavailable();
        }
        return array_map(static function (mixed $snapshot): array {
            if (!is_array($snapshot) || !is_string($snapshot['file_key'] ?? null) || !is_string($snapshot['name'] ?? null)
                || !is_string($snapshot['media_type'] ?? null) || !is_int($snapshot['size_bytes'] ?? null) || !is_string($snapshot['sha256'] ?? null)) {
                throw WorkflowException::attachmentUnavailable();
            }
            return (new \PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachment($snapshot['file_key'], $snapshot['name'], $snapshot['media_type'], $snapshot['size_bytes'], $snapshot['sha256']))->toArray();
        }, $snapshots);
    }

    /** Registered notification producers consume a durable fact; private source-session references never leave this boundary. */
    public function notificationFact(AuthorizedOperationContext $c, string $producerKey, string $eventKey, string $businessKey): array
    {
        if (preg_match('/^(instance_[0-9a-f]{32}):([1-9][0-9]{0,9})$/D', $eventKey, $match) !== 1) { throw WorkflowException::subjectNotFound(); }
        [$instance] = $this->visibleInstance($c, $match[1]);
        $event = Event::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('sequence_no', (int) $match[2])->find() ?? throw WorkflowException::subjectNotFound();
        $fact = $event['notification_fact_json'];
        if (!is_array($fact) || $fact['producer_key'] !== $producerKey || $fact['event_key'] !== $eventKey || $fact['business_key'] !== $businessKey || $instance['subject_key'] !== $businessKey) { throw WorkflowException::subjectNotFound(); }
        unset($fact['source_session']);
        return $fact + ['subject_type' => $instance['subject_type']];
    }

    /** Business owners still authorize their own supplement/comment operation. */
    public function collaborationAccess(AuthorizedOperationContext $c, string $instanceKey, string $workItemKey, bool $lock = false): array
    {
        [$basis] = $this->visibleInstance($c, $instanceKey);
        $scope = $this->scope($c);
        $instance = Instance::scope('tenant', $scope)->where('id', $basis['id'])->lock($lock)->find() ?? throw WorkflowException::subjectNotFound();
        $item = \PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowWorkItemRecord::scope('tenant', $scope)->where('instance_id', $instance['id'])->where('work_item_key', $workItemKey)->find() ?? throw WorkflowException::subjectNotFound();
        $node = Node::scope('tenant', $scope)->where('id', $item['node_execution_id'])->where('instance_id', $instance['id'])->lock($lock)->find() ?? throw WorkflowException::subjectNotFound();
        if ($lock) {
            $item = \PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowWorkItemRecord::scope('tenant', $scope)->where('id', $item['id'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
        }
        $member = $c->tenantContext->memberId;
        $roles = [];
        if ((int) $item['assignee_member_id'] === $member && $this->assignments->eligible($c, $member, $item['sources_json'])) { $roles[] = 'assignee'; }
        $links = \PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowTaskIdentityLinkRecord::scope('tenant', $scope)->where('work_item_id', $item['id'])->where('member_id', $member)->whereIn('relation_type', ['participant', 'watcher'])->select();
        foreach ($links as $link) {
            if ($this->assignments->eligible($c, $member, $link['sources_json'])) { $roles[] = $link['relation_type']; }
        }
        if ($roles === []) { throw WorkflowException::subjectNotFound(); }
        return ['subject_type' => $instance['subject_type'], 'subject_key' => $instance['subject_key'], 'subject_revision_key' => $instance['subject_revision_key'],
            'instance_key' => $instanceKey, 'work_item_key' => $workItemKey, 'node_execution_key' => $node['node_execution_key'],
            'instance_revision' => (int) $instance['revision'], 'node_revision' => (int) $node['revision'], 'task_revision' => (int) $item['revision'], 'roles' => array_values(array_unique($roles)),
            'can_collaborate' => in_array($instance['status'], ['running', 'blocked'], true) && in_array($node['status'], ['active', 'blocked'], true) && $item['status'] === 'pending' && count(array_intersect($roles, ['assignee', 'participant'])) > 0];
    }

    private function itemQuery(AuthorizedOperationContext $c): mixed
    {
        $links = Db::name('workflow_task_identity_link')->getTable();
        $member = (int) $c->tenantContext->memberId;
        $projection = 'w.*,i.instance_key,i.revision AS instance_revision,i.subject_key,i.initiated_by_member_id,i.status AS instance_status,n.node_execution_key,n.revision AS node_revision,n.status AS node_status,v.graph_json';
        $projection .= ',COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT(\'relation_type\',l.relation_type,\'sources_json\',l.sources_json)) FROM ' . $links . ' l WHERE l.tenant_id=w.tenant_id AND l.work_item_id=w.id AND l.member_id=' . $member . '),JSON_ARRAY()) AS own_links_json';
        return Db::name('workflow_work_item')->alias('w')->join('workflow_instance i', 'i.id=w.instance_id AND i.tenant_id=w.tenant_id')->join('workflow_node_execution n', 'n.id=w.node_execution_id AND n.tenant_id=w.tenant_id')->join('workflow_definition_version v', 'v.definition_id=i.definition_id AND v.version=i.definition_version AND v.tenant_id=i.tenant_id')->where('w.tenant_id', $c->tenantContext->tenantId)->fieldRaw($projection);
    }

    private function own(AuthorizedOperationContext $c, mixed $query, ?string $filter): void
    {
        $member = $c->tenantContext->memberId;
        if ($filter === 'assigned' || $filter === 'completed') {
            $query->where('w.assignee_member_id', $member)->where('w.status', $filter === 'assigned' ? 'pending' : 'completed');
            return;
        }
        $relation = match ($filter) {
            'claimable' => 'candidate', 'participant' => 'participant', 'watcher' => 'watcher', default => null
        };
        if ($filter === 'claimable') {
            $query->whereNull('w.assignee_member_id')->where('w.status', 'pending');
        }
        $table = Db::name('workflow_task_identity_link')->getTable();
        $links = static function ($subquery) use ($table, $member, $relation): void {
            $subquery->table($table)->alias('l')->whereColumn('l.tenant_id', '=', 'w.tenant_id')->whereColumn('l.work_item_id', '=', 'w.id')->where('l.member_id', $member)->fieldRaw('1');
            if ($relation !== null) {
                $subquery->where('l.relation_type', $relation);
            }
        };
        if ($filter === null) {
            $query->where(function ($q) use ($member, $links): void {
                $q->where('w.assignee_member_id', $member)->whereExists($links, 'OR');
            });
        } else {
            $query->whereExists($links);
        }
    }

    private function visibility(AuthorizedOperationContext $c, \think\db\BaseQuery $query): void
    {
        $this->authorization->applyVisibleScopes($c, $query);
    }

    private function itemSummary(AuthorizedOperationContext $c, array $row): array
    {
        $graph = WorkflowGraph::fromArray(self::decode($row['graph_json']));
        $node = $graph->node($row['node_key']);
        $member = $c->tenantContext->memberId;
        $sources = self::decode($row['sources_json']);
        $links = self::decode($row['own_links_json']);
        $relations = array_column($links, 'relation_type');
        $actions = [];
        if ($row['status'] === 'pending' && $row['node_status'] === 'active' && $row['instance_status'] === 'running') {
            $selfApproval = $node['type'] === 'approval' && !$node['config']['allow_self_approval'] && $member === (int) $row['initiated_by_member_id'];
            if (!$selfApproval) {
                if ($row['assignee_member_id'] === null && in_array('candidate', $relations, true)) {
                    $candidate = array_values(array_filter($links, static fn(array $l): bool => $l['relation_type'] === 'candidate'))[0];
                    if ($this->assignments->eligible($c, $member, self::decode($candidate['sources_json'])) && $this->allowed($c, $graph, $row['subject_key'], 'read', ['peanut.workflow.task.claim'])) {
                        $actions[] = 'claim';
                    }
                } elseif ((int) $row['assignee_member_id'] === $member && $this->assignments->eligible($c, $member, $sources)) {
                    if ($node['config']['allocation_mode'] === 'claim' && $this->allowed($c, $graph, $row['subject_key'], 'read', ['peanut.workflow.task.release'])) {
                        $actions[] = 'release';
                    }
                    foreach ($graph->outgoing($node['key']) as $edge) {
                        $action = $edge['action'];
                        $permission = $action === 'resubmit' ? 'peanut.workflow.instance.resubmit' : 'peanut.workflow.task.' . (in_array($action, ['approve', 'reject'], true) ? 'decide' : $action);
                        if ($this->allowed($c, $graph, $row['subject_key'], $edge['operation'], [$permission, ...$edge['permission_keys']])) {
                            $actions[] = $action;
                        }
                    }
                }
            }
        }
        return $this->itemProjection($row, $graph) + ['available_actions' => array_values(array_unique($actions))];
    }

    /** Inbox rows are read-visible projections; current action qualification belongs to instance detail. */
    private function itemProjection(array $row, WorkflowGraph $graph): array
    {
        $node = $graph->node($row['node_key']);
        $relations = array_column(self::decode($row['own_links_json']), 'relation_type');
        return ['work_item_key' => $row['work_item_key'], 'instance_key' => $row['instance_key'], 'node_execution_key' => $row['node_execution_key'], 'node_key' => $row['node_key'], 'label' => $node['label'], 'type' => $node['type'], 'revision' => (int) $row['revision'], 'instance_revision' => (int) $row['instance_revision'], 'node_revision' => (int) $row['node_revision'], 'owner_member_id' => $row['owner_member_id'] === null ? null : (string) $row['owner_member_id'], 'assignee_member_id' => $row['assignee_member_id'] === null ? null : (string) $row['assignee_member_id'], 'assignment_state' => $row['assignment_state'], 'status' => $row['status'], 'decision' => $row['decision'], 'relations' => $relations];
    }

    private function visibleInstance(AuthorizedOperationContext $c, string $key): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        $row = Instance::scope('tenant', $this->scope($c))->where('instance_key', $key)->find() ?? throw WorkflowException::subjectNotFound();
        $version = Version::scope('tenant', $this->scope($c))->where('definition_id', $row['definition_id'])->where('version', $row['definition_version'])->find() ?? throw WorkflowException::internal();
        $graph = WorkflowGraph::fromArray($version['graph_json']);
        if (!hash_equals($graph->sha256, $version['graph_sha256'])) {
            throw WorkflowException::internal();
        }
        if (!$this->allowed($c, $graph, $row['subject_key'], $graph->data['subject_read_operation'], ['peanut.workflow.instance.read'])) {
            throw WorkflowException::subjectNotFound();
        }
        return [$row, $graph];
    }

    private function allowed(AuthorizedOperationContext $basis, WorkflowGraph $graph, string $subject, string $operation, array $permissions): bool
    {
        try {
            $c = $this->authorization->authorize($basis, $graph->data['subject_resource_key'], $operation, array_values(array_unique($permissions)), $subject);
            return $c->tenantContext === $basis->tenantContext && $c->resourceKey === $graph->data['subject_resource_key'] && $c->operation === $operation && count($c->targets) === 1 && $c->targets[0]->targetIds === [$subject] && $c->targets[0]->targetResourceKey === $c->resourceKey && $c->targets[0]->targetRole === 'primary';
        } catch (\PeanutAdmin\DataPermission\Exception\DataAuthorizationException $e) {
            if ($e->errorCode === 'AUTHZ_PERMISSION_DENIED') {
                return false;
            }
            throw $e;
        } catch (\app\common\exception\BusinessException $e) {
            if (in_array($e->errorCode, ['ARTICLE_MATERIAL_PERMISSION_DENIED', 'ARTICLE_MATERIAL_READ_DENIED', 'ARTICLE_NOT_FOUND', 'ARTICLE_MATERIAL_AUTHORIZATION_STALE'], true)) {
                return false;
            }
            throw $e;
        } catch (\PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException $e) {
            if (in_array($e->problemCode, ['TENANT_SESSION_ACCESS_DENIED', 'TENANT_SESSION_NOT_FOUND'], true)) {
                return false;
            }
            throw $e;
        } catch (WorkflowException $e) {
            if (in_array($e->errorCode, ['WORKFLOW_ASSIGNMENT_DENIED', 'WORKFLOW_SUBJECT_NOT_FOUND'], true)) {
                return false;
            }
            throw $e;
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException $e) {
            if (in_array($e->errorCode, ['RESOURCE_NOT_FOUND', 'WORKFLOW_BUSINESS_ACCESS_DENIED'], true)) {
                return false;
            }
            throw $e;
        }
    }

    private function definitionSummary(array $r): array
    {
        return ['definition_id' => (string) $r['id'], 'module_key' => $r['module_key'], 'workflow_key' => $r['workflow_key'], 'status' => $r['status'], 'draft_revision' => (int) $r['revision'], 'latest_version' => (int) $r['latest_version'], 'active_version' => $r['active_version'] === null ? null : (int) $r['active_version']];
    }
    private function taskManagementAllowed(AuthorizedOperationContext $c): bool
    {
        return $this->permissions !== null && $this->permissions->decide($c->tenantContext, $this->permissions->principal($c->tenantContext), 'peanut.task.jobs.manage')->allowed;
    }
    private function definitionContext(AuthorizedOperationContext $c): void
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        if ($c->resourceKey !== 'peanut.workflow.definition' || !in_array($c->operation, ['read', 'write'], true) || $c->targets !== []) {
            throw WorkflowException::subjectNotFound();
        }
    }
    private function page(int $p, int $size): void
    {
        if ($p < 1 || $p > 1000000 || $size < 1 || $size > 100) {
            throw WorkflowException::definitionInvalid('Invalid pagination.');
        }
    }
    private function scope(AuthorizedOperationContext $c): TenantScope
    {
        return TenantScope::fromTrustedContext($c->tenantContext->tenantId, 'workflow');
    }
    private static function decode(mixed $v): array
    {
        return is_array($v) ? $v : json_decode($v, true, 128, JSON_THROW_ON_ERROR);
    }
    private static function utc(string $v): string
    {
        return str_replace(' ', 'T', $v) . 'Z';
    }
}
