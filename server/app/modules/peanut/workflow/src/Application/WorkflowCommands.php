<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAttachmentResolver;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowCondition;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionRecord as Definition;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord as Version;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowInstanceRecord as Instance;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowNodeExecutionRecord as Node;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowWorkItemRecord as Item;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowTaskIdentityLinkRecord as Link;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowEventRecord as Event;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;
use PeanutAdmin\Modules\Task\Contract\JobExecution;
use think\facade\Db;

/** All entrypoints own one native connection transaction. No arbitrary transition command. */
final readonly class WorkflowCommands
{
    public function __construct(
        private WorkflowCommandTransaction $transactions,
        private WorkflowAuthorizationResolver $authorization,
        private WorkflowAssignmentResolver $assignments,
        private WorkflowScenarioProvider $scenarios,
        private WorkflowQueries $queries,
        private AuditService $audit,
        private WorkflowLifecycleQueries $lifecycle,
        private ?WorkflowAttachmentResolver $attachments = null,
        private ?TrustedJobPublisher $tasks = null,
        private ?\PeanutAdmin\Modules\Task\Contract\TaskJobService $taskJobs = null,
    ) {}

    public function start(AuthorizedOperationContext $c, array $r, string $key): array
    {
        $this->lifecycle->assertNewOperations($c->tenantContext->tenantId);
        WorkflowGraph::keys($r, ['module_key', 'workflow_key', 'subject_type', 'subject_key', 'subject_revision_key', 'expected_revision', 'business_department_key', 'attachment_file_keys']);
        foreach (['module_key', 'workflow_key', 'subject_type', 'subject_key', 'subject_revision_key'] as $field) {
            $this->text($r[$field], 160);
        }
        if ($r['business_department_key'] !== null) {
            $this->text($r['business_department_key'], 160);
        }
        $definitionQuery = fn() => Definition::scope('tenant', $this->scope($c))->where('module_key', $r['module_key'])->where('workflow_key', $r['workflow_key']);
        $definition = $definitionQuery()->find() ?? throw WorkflowException::subjectNotFound();
        if ($definition['status'] !== 'active' || !$definition['active_version']) {
            throw WorkflowException::subjectNotFound();
        }
        $graph = $this->version($c, (int) $definition['id'], (int) $definition['active_version']);
        if ($r['subject_type'] !== $graph->data['subject_resource_key']) {
            throw WorkflowException::subjectNotFound();
        }
        $authorized = $this->authorize($c, $graph, $r['subject_key'], $graph->data['subject_start_operation'], ['peanut.workflow.instance.start', ...$graph->data['start_permission_keys']]);
        $prepared = $this->scenarios->prepareSubjectSnapshot($authorized, $r['subject_type'], $r['subject_key'], $r['subject_revision_key']);
        return $this->transactions->run(
            $c,
            'workflow.start',
            $r['module_key'] . '.' . $r['workflow_key'] . ':' . $r['subject_type'] . ':' . $r['subject_key'],
            $r,
            $key,
            fn() => $this->authorize($c, $graph, $r['subject_key'], $graph->data['subject_start_operation'], ['peanut.workflow.instance.start', ...$graph->data['start_permission_keys']]),
            function (string $now) use ($c, $r, $key, $graph, $authorized, $definitionQuery, $prepared): array {
                $this->lifecycle->assertNewOperations($c->tenantContext->tenantId, true);
                // Documented lock order: idempotency -> business object -> definition -> instance -> node -> items.
                $snapshot = $this->snapshot($authorized, $r['subject_type'], $r['subject_key'], $r['subject_revision_key'], $prepared);
                $department = $this->scenarios->businessDepartment($authorized, $r['business_department_key']);
                $definition = $definitionQuery()->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->expected($r['expected_revision'], (int) $definition['revision']);
                if ($definition['status'] !== 'active' || !$definition['active_version']) {
                    throw WorkflowException::definitionRetired();
                }
                $graph = $this->version($c, (int) $definition['id'], (int) $definition['active_version']);
                $scenario = $this->scenario($authorized, $r['module_key'], $r['workflow_key'], $graph);
                $attachmentSnapshots = $this->attachmentSnapshots($authorized, $r['attachment_file_keys']);
                $active = Instance::scope('tenant', $this->scope($c))->where('module_key', $r['module_key'])->where('workflow_key', $r['workflow_key'])->where('subject_type', $r['subject_type'])->where('subject_key', $r['subject_key'])->whereIn('status', ['running', 'blocked'])->lock(true)->find();
                if ($active !== null) {
                    throw WorkflowException::instanceConflict((string) $active['instance_key']);
                }
                $instance = new Instance();
                $instance->save(['tenant_id' => $c->tenantContext->tenantId, 'instance_key' => 'instance_' . bin2hex(random_bytes(16)), 'definition_id' => $definition['id'], 'definition_version' => $definition['active_version'], 'module_key' => $r['module_key'], 'workflow_key' => $r['workflow_key'], 'subject_type' => $r['subject_type'], 'subject_key' => $r['subject_key'], 'subject_revision_key' => $snapshot['revision_key'], 'subject_revision_sha256' => $snapshot['sha256'], 'snapshot_json' => $snapshot, 'business_department_key' => $department, 'current_node_key' => $graph->startNode()['key'], 'cycle_no' => 1, 'status' => 'running', 'revision' => 1, 'initiated_by_member_id' => $c->tenantContext->memberId, 'last_actor_member_id' => $c->tenantContext->memberId, 'created_at' => $now, 'updated_at' => $now]);
                $this->enter($authorized, $instance, $graph, $graph->edge($graph->startNode()['key'], 'advance')['to'], $now, true);
                $this->event($c, $instance, null, 'started', null, [], $now, attachments: $attachmentSnapshots);
                return $this->queries->instance($c, $instance['instance_key']);
            },
        );
    }

    public function claimTask(AuthorizedOperationContext $c, string $workItemKey, array $r, string $key): array
    {
        return $this->task($c, $workItemKey, $r, $key, 'claim');
    }
    public function releaseTask(AuthorizedOperationContext $c, string $workItemKey, array $r, string $key): array
    {
        return $this->task($c, $workItemKey, $r, $key, 'release');
    }
    public function decide(AuthorizedOperationContext $c, string $workItemKey, array $r, string $key): array
    {
        if (!in_array($r['decision'] ?? null, ['approve', 'reject'], true)) {
            throw WorkflowException::definitionInvalid('Invalid decision.');
        }
        return $this->task($c, $workItemKey, $r, $key, $r['decision']);
    }
    public function completeTask(AuthorizedOperationContext $c, string $workItemKey, array $r, string $key): array
    {
        return $this->task($c, $workItemKey, $r, $key, 'complete');
    }
    public function returnTask(AuthorizedOperationContext $c, string $workItemKey, array $r, string $key): array
    {
        return $this->task($c, $workItemKey, $r, $key, 'return');
    }
    public function resubmit(AuthorizedOperationContext $c, string $workItemKey, array $r, string $key): array
    {
        return $this->task($c, $workItemKey, $r, $key, 'resubmit');
    }

    /** Called only by the official signed Task worker, never by an HTTP actor. */
    public function executeServiceTask(AuthorizedOperationContext $c, JobExecution $execution): void
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        $payload = $execution->payload;
        WorkflowGraph::keys($payload, ['instance_key', 'node_execution_key', 'action_key', 'contract_version', 'input', 'effect_key']);
        if ($execution->tenantId !== $c->tenantContext->tenantId) {
            throw WorkflowException::subjectNotFound();
        }
        $basis = Instance::scope('tenant', $this->scope($c))->where('instance_key', $payload['instance_key'])->find() ?? throw WorkflowException::subjectNotFound();
        $graph = $this->version($c, (int) $basis['definition_id'], (int) $basis['definition_version']);
        $scenario = $this->scenario($c, $basis['module_key'], $basis['workflow_key'], $graph);
        $action = $scenario['actions'][$payload['action_key']] ?? throw WorkflowException::subjectNotFound();
        $authorized = $this->authorize($c, $graph, $basis['subject_key'], $action['operation'], $action['permission_keys']);
        $prepared = $this->scenarios->prepareSubjectSnapshot($authorized, $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key']);
        $execution->checkpoint();
        try {
            // Persist the start intent before effects: failure cannot reopen ordinary withdrawal.
            $started = Db::transaction(function () use ($c, $execution, $payload, $basis, $graph, $prepared): bool {
                $state = $this->serviceTaskState($c, $execution, $basis, $payload, $graph, $prepared);
                if ($state === null) {
                    return false;
                }
                [$instance, $node] = $state;
                if ($node['started_at'] === null) {
                    $now = gmdate('Y-m-d H:i:s') . '.000';
                    $node->save(['started_at' => $now, 'revision' => (int) $node['revision'] + 1]);
                    $instance->save(['substantive_at' => $instance['substantive_at'] ?? $now, 'revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                    $this->event($c, $instance, $node, 'action_started', null, ['job_key' => $execution->jobKey, 'effect_key' => $node['effect_key']], $now, system: true);
                }
                $execution->assertLeaseOwned();
                return true;
            });
            if (!$started) {
                return;
            }
            Db::transaction(function () use ($c, $execution, $payload, $basis, $graph, $prepared): void {
                $state = $this->serviceTaskState($c, $execution, $basis, $payload, $graph, $prepared);
                if ($state === null) {
                    return;
                }
                [$instance, $node, $config, $authorized] = $state;
                $execution->assertLeaseOwned();
                $receipt = $this->scenarios->executeAction($authorized, $config['action_key'], $config['contract_version'], $node['action_input_json'], $node['effect_key']);
                if (strlen(WorkflowGraph::json($receipt)) > 65535) {
                    throw WorkflowException::blocked('Business action receipt exceeds capacity.');
                }
                $now = gmdate('Y-m-d H:i:s') . '.000';
                $edge = $graph->edge($node['node_key'], 'succeeded');
                $node->save(['effect_receipt_json' => $receipt, 'output_json' => $receipt['output'] ?? [], 'status' => 'completed', 'blocked_reason' => null, 'completed_at' => $now, 'selected_transition_key' => $edge['key'], 'revision' => (int) $node['revision'] + 1]);
                $instance->save(['status' => 'running', 'blocked_reason' => null, 'substantive_at' => $instance['substantive_at'] ?? $now, 'revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                $this->enter($authorized, $instance, $graph, $edge['to'], $now, false, $node['previous_actor_member_id'] === null ? null : (int) $node['previous_actor_member_id'], $receipt['output'] ?? []);
                $this->event($c, $instance, $node, 'action_succeeded', null, ['job_key' => $execution->jobKey, 'effect_key' => $node['effect_key']], $now, $edge['key'], system: true);
                // The official Task fencing assertion participates in this same native connection.
                $execution->assertLeaseOwned();
            });
        } catch (WorkflowException $e) {
            // The business write rolled back. Preserve the original job/effect identity for Task recovery.
            $this->blockServiceTask($c, $execution, $basis, $payload, $e->errorCode, $e->getMessage(), $prepared);
            throw $e;
        } catch (\PeanutAdmin\Modules\Task\Contract\LeaseLostException $e) {
            throw $e;
        } catch (\PeanutAdmin\Modules\Task\Contract\RetryableTaskException $e) {
            if ($execution->attemptNumber >= 3) {
                $this->blockServiceTask($c, $execution, $basis, $payload, $e->safeCode, 'Business action retry limit reached.', $prepared);
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->blockServiceTask($c, $execution, $basis, $payload, 'ACTION_FAILED', 'Business action failed; inspect the original Task job.', $prepared);
            throw $e;
        }
    }

    /** Locks and reauthorizes the fixed original job in either native worker transaction. */
    private function serviceTaskState(AuthorizedOperationContext $c, JobExecution $execution, Instance $basis, array $payload, WorkflowGraph $graph, object $prepared): ?array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId, true);
        $this->lifecycle->assertTaskAvailable($c->tenantContext->tenantId, true);
        $snapshot = $this->snapshot($c, $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key'], $prepared);
        if ($snapshot['sha256'] !== $basis['subject_revision_sha256']) {
            throw WorkflowException::subjectRevisionConflict();
        }
        $instance = Instance::scope('tenant', $this->scope($c))->where('id', $basis['id'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
        $node = Node::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('node_execution_key', $payload['node_execution_key'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
        if ($node['task_job_key'] !== $execution->jobKey || $node['effect_key'] !== $payload['effect_key']) {
            throw WorkflowException::subjectNotFound();
        }
        // Match recovery's object -> instance -> node -> Task lock order. Renewal
        // remains a native UPDATE fence held until the outer result transaction commits.
        $execution->checkpoint();
        if ($node['effect_receipt_json'] !== null || in_array($instance['status'], ['cancelled', 'terminated'], true)) {
            $execution->assertLeaseOwned();
            return null;
        }
        $config = $graph->node($node['node_key'])['config'];
        if (!in_array($node['status'], ['pending', 'blocked'], true) || $node['node_type'] !== 'service_task' || $payload['action_key'] !== $config['action_key'] || $payload['contract_version'] !== $config['contract_version'] || WorkflowGraph::json($payload['input']) !== WorkflowGraph::json($node['action_input_json'])) {
            throw WorkflowException::subjectNotFound();
        }
        $scenario = $this->scenario($c, $instance['module_key'], $instance['workflow_key'], $graph);
        $action = $scenario['actions'][$config['action_key']] ?? throw WorkflowException::unsupported('action:' . $config['action_key']);
        if ($action['contract_version'] !== $config['contract_version']) {
            throw WorkflowException::unsupported('action_contract_version');
        }
        $authorized = $this->authorize($c, $graph, $instance['subject_key'], $action['operation'], $action['permission_keys']);
        return [$instance, $node, $config, $authorized];
    }

    private function blockServiceTask(AuthorizedOperationContext $c, JobExecution $execution, Instance $basis, array $payload, string $code, string $message, object $prepared): void
    {
        Db::transaction(function () use ($c, $execution, $payload, $basis, $code, $message, $prepared): void {
            $this->lifecycle->assertAvailable($c->tenantContext->tenantId, true);
            $this->lifecycle->assertTaskAvailable($c->tenantContext->tenantId, true);
            $snapshot = $this->snapshot($c, $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key'], $prepared);
            if ($snapshot['sha256'] !== $basis['subject_revision_sha256']) {
                throw WorkflowException::subjectRevisionConflict();
            }
            $instance = Instance::scope('tenant', $this->scope($c))->where('id', $basis['id'])->lock(true)->find();
            $node = Node::scope('tenant', $this->scope($c))->where('instance_id', $basis['id'])->where('node_execution_key', $payload['node_execution_key'])->lock(true)->find();
            if ($instance === null || $node === null || $node['task_job_key'] !== $execution->jobKey || $node['effect_key'] !== $payload['effect_key']) {
                throw WorkflowException::subjectNotFound();
            }
            $execution->checkpoint();
            if ($instance !== null && $node !== null && $node['task_job_key'] === $execution->jobKey && $node['effect_receipt_json'] === null && in_array($instance['status'], ['running', 'blocked'], true)) {
                $now = gmdate('Y-m-d H:i:s') . '.000';
                $node->save(['status' => 'blocked', 'blocked_reason' => substr($message, 0, 500), 'revision' => (int) $node['revision'] + 1]);
                $instance->save(['status' => 'blocked', 'blocked_reason' => substr($message, 0, 500), 'revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                $this->event($c, $instance, $node, 'action_blocked', null, ['code' => $code, 'job_key' => $execution->jobKey], $now, system: true);
            }
            $execution->assertLeaseOwned();
        });
    }

    private function task(AuthorizedOperationContext $c, string $itemKey, array $r, string $key, string $action): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        $fields = ['node_execution_key', 'expected_revision', 'expected_node_revision', 'expected_task_revision'];
        if (!in_array($action, ['claim', 'release'], true)) {
            $fields = [...$fields, 'comment', 'attachment_file_keys'];
        }
        if (in_array($action, ['approve', 'reject'], true)) {
            $fields[] = 'decision';
        }
        if ($action === 'complete') {
            $fields[] = 'input';
        }
        if ($action === 'resubmit') {
            $fields[] = 'subject_revision_key';
        }
        WorkflowGraph::keys($r, $fields);
        $this->text($r['node_execution_key'], 64);
        if ($action === 'complete' && !is_array($r['input'])) {
            throw WorkflowException::definitionInvalid('Input object required.');
        }
        if ($action === 'resubmit') {
            $this->text($r['subject_revision_key'], 160);
        }
        $item = Item::scope('tenant', $this->scope($c))->where('work_item_key', $itemKey)->find() ?? throw WorkflowException::subjectNotFound();
        $basis = Instance::scope('tenant', $this->scope($c))->where('id', $item['instance_id'])->find() ?? throw WorkflowException::subjectNotFound();
        $graph = $this->version($c, (int) $basis['definition_id'], (int) $basis['definition_version']);
        $nodeConfig = $graph->node($item['node_key']);
        $edge = in_array($action, ['claim', 'release'], true) ? null : $graph->edge($item['node_key'], $action);
        $operation = $edge['operation'] ?? 'read';
        $permission = $action === 'resubmit' ? 'peanut.workflow.instance.resubmit' : 'peanut.workflow.task.' . (in_array($action, ['approve', 'reject'], true) ? 'decide' : $action);
        $permissions = [$permission, ...($edge['permission_keys'] ?? [])];
        $authorize = fn() => $this->authorize($c, $graph, $basis['subject_key'], $operation, $permissions);
        $prepared = $this->scenarios->prepareSubjectSnapshot($authorize(), $basis['subject_type'], $basis['subject_key'], $action === 'resubmit' ? $r['subject_revision_key'] : $basis['subject_revision_key']);
        return $this->transactions->run(
            $c,
            'workflow.task.' . $action,
            $itemKey,
            $r,
            $key,
            $authorize,
            function (string $now) use ($c, $r, $action, $itemKey, $basis, $graph, $nodeConfig, $edge, $authorize, $prepared): array {
                $authorized = $authorize();
                $snapshot = $this->snapshot($authorized, $basis['subject_type'], $basis['subject_key'], $action === 'resubmit' ? $r['subject_revision_key'] : $basis['subject_revision_key'], $prepared);
                if ($action !== 'resubmit' && $snapshot['sha256'] !== $basis['subject_revision_sha256']) {
                    throw WorkflowException::subjectRevisionConflict();
                }
                $instance = Instance::scope('tenant', $this->scope($c))->where('id', $basis['id'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->expected($r['expected_revision'], (int) $instance['revision'], (string) $instance['instance_key']);
                if ($instance['status'] !== 'running') {
                    throw WorkflowException::alreadyCompleted();
                }
                $node = Node::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('node_execution_key', $r['node_execution_key'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->expected($r['expected_node_revision'], (int) $node['revision'], (string) $instance['instance_key']);
                $items = Item::scope('tenant', $this->scope($c))->where('node_execution_id', $node['id'])->order('id')->lock(true)->select();
                $item = null;
                foreach ($items as $candidate) {
                    if ($candidate['work_item_key'] === $itemKey) {
                        $item = $candidate;
                    }
                }
                if ($item === null || $item['status'] !== 'pending' || $node['status'] !== 'active') {
                    throw WorkflowException::alreadyCompleted();
                }
                $this->expected($r['expected_task_revision'], (int) $item['revision'], (string) $instance['instance_key']);
                $memberId = $c->tenantContext->memberId;
                $config = $nodeConfig['config'];
                if ($nodeConfig['type'] === 'approval' && !$config['allow_self_approval'] && $memberId === (int) $instance['initiated_by_member_id']) {
                    throw WorkflowException::assignmentDenied();
                }
                if ($action === 'claim') {
                    $link = Link::scope('tenant', $this->scope($c))->where('work_item_id', $item['id'])->where('member_id', $memberId)->where('relation_type', 'candidate')->find();
                    if ($config['allocation_mode'] !== 'claim' || $item['assignee_member_id'] !== null || $link === null || !$this->assignments->eligible($authorized, $memberId, $link['sources_json'])) {
                        throw WorkflowException::assignmentDenied();
                    }
                    $item->save(['assignee_member_id' => $memberId, 'owner_member_id' => $item['owner_source'] === 'automatic' ? $memberId : $item['owner_member_id'], 'assignment_state' => 'assigned', 'sources_json' => $link['sources_json'], 'revision' => (int) $item['revision'] + 1, 'updated_at' => $now]);
                } else {
                    if ((int) $item['assignee_member_id'] !== $memberId || !$this->assignments->eligible($authorized, $memberId, $item['sources_json'])) {
                        throw WorkflowException::assignmentDenied();
                    }
                    if ($action === 'release') {
                        if ($config['allocation_mode'] !== 'claim' || $item['decision'] !== null) {
                            throw WorkflowException::assignmentDenied();
                        }
                        $item->save(['assignee_member_id' => null, 'owner_member_id' => $item['owner_source'] === 'automatic' ? null : $item['owner_member_id'], 'assignment_state' => 'unassigned', 'revision' => (int) $item['revision'] + 1, 'updated_at' => $now]);
                    } else {
                        $comment = $this->comment($r['comment'], in_array($action, ['reject', 'return'], true) || $config['opinion_required']);
                        $attachmentSnapshots = $this->attachmentSnapshots($authorized, $r['attachment_file_keys']);
                        if (in_array($action, ['approve', 'reject'], true) ? $nodeConfig['type'] !== 'approval' : $nodeConfig['type'] !== 'human_task' && $action !== 'return') {
                            throw WorkflowException::transitionUnavailable();
                        }
                        if ($action === 'complete' || $action === 'resubmit') {
                            // Trusted command purpose; business providers must distinguish a new revision from a completed form.
                            $completionInstance = $instance->toArray() + ['submission_action' => $action];
                            if ($action === 'resubmit') {
                                $completionInstance = array_replace($completionInstance, [
                                    'submission_previous_revision_key' => $instance['subject_revision_key'],
                                    'subject_revision_key' => $snapshot['revision_key'],
                                    'subject_revision_sha256' => $snapshot['sha256'],
                                    'snapshot_json' => $snapshot,
                                ]);
                            }
                            $output = $this->scenarios->validateCompletion($authorized, $completionInstance, $r['input'] ?? []);
                            $node->save(['output_json' => $output]);
                        }
                        if ($action === 'resubmit') {
                            $instance->save(['subject_revision_key' => $snapshot['revision_key'], 'subject_revision_sha256' => $snapshot['sha256'], 'snapshot_json' => $snapshot]);
                        }
                        if ($action === 'return') {
                            $return = $graph->edge($node['node_key'], 'return');
                            $traversals = Event::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('transition_key', $return['key'])->count();
                            if ($traversals >= $return['max_traversals']) {
                                $reason = 'Return traversal limit reached.';
                                $node->save(['status' => 'blocked', 'blocked_reason' => $reason, 'revision' => (int) $node['revision'] + 1]);
                                $instance->save(['status' => 'blocked', 'blocked_reason' => $reason, 'revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                                $this->event($c, $instance, $node, 'return_limit_blocked', $comment, ['code' => 'ACTION_BLOCKED'], $now);
                                return $this->queries->instance($c, $instance['instance_key']);
                            }
                        }
                        $item->save(['status' => 'completed', 'decision' => $action, 'completed_by_member_id' => $memberId, 'completed_at' => $now, 'updated_at' => $now, 'revision' => (int) $item['revision'] + 1]);
                        $instance->save(['substantive_at' => $instance['substantive_at'] ?? $now]);
                        $votes = array_map(static fn($i): array => $i->toArray(), $items->all());
                        $decision = in_array($action, ['approve', 'reject'], true) ? WorkflowAggregation::result($config['allocation_mode'], $config['aggregation_policy'], $votes) : $action;
                        if ($decision !== null) {
                            $chosen = $graph->edge($node['node_key'], $decision);
                            if ($chosen['return_edge']) {
                                $instance->save(['cycle_no' => (int) $instance['cycle_no'] + 1]);
                            }
                            $this->cancelItems($c, (int) $node['id'], $now);
                            $node->save(['status' => 'completed', 'completed_at' => $now, 'selected_transition_key' => $chosen['key']]);
                            $this->enter($authorized, $instance, $graph, $chosen['to'], $now, false, $config['allocation_mode'] === 'multi' ? null : $memberId, $node['output_json'] ?? []);
                            $this->event($c, $instance, $node, $action, $comment, ['decision' => $action], $now, $chosen['key'], $attachmentSnapshots);
                        } else {
                            $this->event($c, $instance, $node, $action, $comment, ['decision' => $action], $now, attachments: $attachmentSnapshots);
                        }
                    }
                }
                $node->save(['revision' => (int) $node['revision'] + 1]);
                $instance->save(['revision' => (int) $instance['revision'] + 1, 'last_actor_member_id' => $memberId, 'updated_at' => $now]);
                if (in_array($action, ['claim', 'release'], true)) {
                    $this->event($c, $instance, $node, $action, null, ['work_item_key' => $itemKey], $now);
                }
                return $this->queries->instance($c, $instance['instance_key']);
            },
        );
    }

    public function withdraw(AuthorizedOperationContext $c, string $instanceKey, array $r, string $key): array
    {
        return $this->instanceCommand($c, $instanceKey, $r, $key, 'withdraw');
    }
    public function terminate(AuthorizedOperationContext $c, string $instanceKey, array $r, string $key, ?AuthorizedOperationContext $taskContext = null): array
    {
        return $this->instanceCommand($c, $instanceKey, $r, $key, 'terminate', $taskContext);
    }
    public function retryBlocked(AuthorizedOperationContext $c, string $instanceKey, array $r, string $key): array
    {
        return $this->instanceCommand($c, $instanceKey, $r, $key, 'retry');
    }
    public function reassignPending(AuthorizedOperationContext $c, string $instanceKey, array $r, string $key): array
    {
        return $this->instanceCommand($c, $instanceKey, $r, $key, 'reassign');
    }

    /** Reconciles an authoritative terminal Task state, never a UI inferred blocker. */
    public function taskDiagnostics(AuthorizedOperationContext $c, AuthorizedOperationContext $taskContext, string $instanceKey, ?AuthorizedOperationContext $taskManage = null): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        if ($this->taskJobs === null || $taskContext->tenantContext !== $c->tenantContext || $taskContext->resourceKey !== 'peanut.task-job' || $taskContext->operation !== 'read') {
            throw WorkflowException::assignmentDenied();
        }
        $instance = Instance::scope('tenant', $this->scope($c))->where('instance_key', $instanceKey)->find() ?? throw WorkflowException::subjectNotFound();
        $graph = $this->version($c, (int) $instance['definition_id'], (int) $instance['definition_version']);
        $this->authorize($c, $graph, $instance['subject_key'], $graph->data['subject_read_operation'], ['peanut.workflow.diagnostics.read']);
        $nodes = Node::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->whereIn('status', ['pending', 'blocked'])->whereNotNull('task_job_key')->limit(100)->select();
        $items = [];
        foreach ($nodes as $node) {
            $job = $this->taskJobs->detail($taskContext, $node['task_job_key']);
            $actions = [];
            if ($taskManage !== null && $taskManage->tenantContext === $c->tenantContext && $taskManage->resourceKey === 'peanut.task-job' && $taskManage->operation === 'manage' && in_array($job->status, ['dead', 'cancelled'], true) && $node['effect_receipt_json'] === null) {
                $actions[] = 'reconcile';
                if ($job->status === 'dead' && $instance['status'] === 'blocked' && $node['status'] === 'blocked') {
                    try {
                        $this->authorize($c, $graph, $instance['subject_key'], 'retry', ['peanut.workflow.diagnostics.retry']);
                        $scenario = $this->scenario($c, $instance['module_key'], $instance['workflow_key'], $graph);
                        $config = $graph->node($node['node_key'])['config'];
                        $action = $scenario['actions'][$config['action_key']] ?? throw WorkflowException::unsupported('action');
                        $this->authorize($c, $graph, $instance['subject_key'], $action['operation'], ['peanut.workflow.diagnostics.retry', ...$action['permission_keys']]);
                        $actions[] = 'retry_original';
                        $actions[] = 'reauthorize';
                    } catch (WorkflowException|\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException|\PeanutAdmin\DataPermission\Exception\DataAuthorizationException) {
                        // The recovery endpoint still performs its own current checks.
                    }
                }
            }
            $items[] = ['node_execution_key' => $node['node_execution_key'], 'node_revision' => (int) $node['revision'], 'status' => $node['status'], 'job' => $job->toPublicArray(), 'available_actions' => $actions];
        }
        return ['instance_key' => $instanceKey, 'revision' => (int) $instance['revision'], 'items' => $items];
    }

    public function reconcileTask(AuthorizedOperationContext $c, AuthorizedOperationContext $taskContext, string $instanceKey, array $r, string $key): array
    {
        return $this->recoverTask($c, $taskContext, $instanceKey, $r, $key, false);
    }

    public function recoverServiceTask(AuthorizedOperationContext $c, AuthorizedOperationContext $taskContext, string $instanceKey, array $r, string $key): array
    {
        return $this->recoverTask($c, $taskContext, $instanceKey, $r, $key, true);
    }

    private function recoverTask(AuthorizedOperationContext $c, AuthorizedOperationContext $taskContext, string $instanceKey, array $r, string $key, bool $recover): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        WorkflowGraph::keys($r, $recover ? ['expected_revision', 'node_execution_key', 'expected_node_revision', 'expected_job_revision', 'strategy', 'comment'] : ['expected_revision', 'node_execution_key', 'expected_node_revision', 'expected_job_revision', 'comment']);
        $this->comment($r['comment'], true);
        if ($this->taskJobs === null || $this->tasks === null || $taskContext->tenantContext !== $c->tenantContext
            || $taskContext->resourceKey !== 'peanut.task-job' || $taskContext->operation !== 'manage'
            || ($recover && !in_array($r['strategy'], ['retry_original', 'reauthorize'], true))) {
            throw WorkflowException::assignmentDenied();
        }
        $basis = Instance::scope('tenant', $this->scope($c))->where('instance_key', $instanceKey)->find() ?? throw WorkflowException::subjectNotFound();
        $graph = $this->version($c, (int) $basis['definition_id'], (int) $basis['definition_version']);
        $authorize = function () use ($c, $graph, $basis, $r, $recover): AuthorizedOperationContext {
            $authorized = $this->authorize($c, $graph, $basis['subject_key'], $recover ? 'retry' : $graph->data['subject_read_operation'], ['peanut.workflow.diagnostics.' . ($recover ? 'retry' : 'read')]);
            if ($recover) {
                $this->lifecycle->assertTaskAvailable($c->tenantContext->tenantId);
                $candidate = Node::scope('tenant', $this->scope($c))->where('instance_id', $basis['id'])->where('node_execution_key', $r['node_execution_key'])->find() ?? throw WorkflowException::subjectNotFound();
                $scenario = $this->scenario($authorized, $basis['module_key'], $basis['workflow_key'], $graph);
                $config = $graph->node($candidate['node_key'])['config'];
                $action = $scenario['actions'][$config['action_key'] ?? ''] ?? throw WorkflowException::subjectNotFound();
                // Current business effect authorization also gates idempotent replays.
                $this->authorize($c, $graph, $basis['subject_key'], $action['operation'], ['peanut.workflow.diagnostics.retry', ...$action['permission_keys']]);
            }
            return $authorized;
        };
        $prepared = $this->scenarios->prepareSubjectSnapshot($authorize(), $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key']);
        return $this->transactions->run(
            $c,
            $recover ? 'workflow.recover-task' : 'workflow.reconcile-task',
            $instanceKey,
            $r,
            $key,
            $authorize,
            function (string $now) use ($c, $taskContext, $basis, $graph, $r, $recover, $authorize, $prepared): array {
                if ($recover) {
                    $this->lifecycle->assertTaskAvailable($c->tenantContext->tenantId, true);
                }
                $authorized = $authorize();
                $this->snapshot($authorized, $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key'], $prepared);
                $instance = Instance::scope('tenant', $this->scope($c))->where('id', $basis['id'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->expected($r['expected_revision'], (int) $instance['revision'], (string) $instance['instance_key']);
                $node = Node::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('node_execution_key', $r['node_execution_key'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->expected($r['expected_node_revision'], (int) $node['revision'], (string) $instance['instance_key']);
                if (!in_array($instance['status'], ['running', 'blocked'], true) || !in_array($node['status'], ['pending', 'blocked'], true)
                    || $node['node_type'] !== 'service_task' || $node['task_job_key'] === null || $node['effect_receipt_json'] !== null) {
                    throw WorkflowException::blocked('Only a confirmed unfinished service task can be recovered.');
                }
                // Instance/node first, then Task. The Task terminal lock blocks concurrent
                // management retry; a live/unknown claim is never eligible for replacement.
                $old = $this->taskJobs->detailForRecovery($taskContext, $node['task_job_key'], $r['expected_job_revision']);
                $metadata = ['job_key' => $old->jobKey, 'job_revision' => $old->revision, 'code' => $old->lastErrorCode];
                if ($recover) {
                    if ($old->status !== 'dead') {
                        throw WorkflowException::blocked('Cancelled tasks are not eligible for automatic restart.');
                    }
                    if ($instance['status'] !== 'blocked' || $node['status'] !== 'blocked') {
                        throw WorkflowException::blocked('Reconcile the original Task failure first.');
                    }
                    $config = $graph->node($node['node_key'])['config'];
                    $scenario = $this->scenario($authorized, $instance['module_key'], $instance['workflow_key'], $graph);
                    $action = $scenario['actions'][$config['action_key']] ?? throw WorkflowException::unsupported('action:' . $config['action_key']);
                    if ($action['contract_version'] !== $config['contract_version'] || $old->taskType !== $action['task_type']) {
                        throw WorkflowException::subjectNotFound();
                    }
                    $actionContext = $this->authorize($c, $graph, $instance['subject_key'], $action['operation'], ['peanut.workflow.diagnostics.retry', ...$action['permission_keys']]);
                    if ($r['strategy'] === 'retry_original') {
                        $job = $this->taskJobs->retry($taskContext, $old->jobKey, $old->revision);
                    } else {
                        // Same effect and frozen inputs; only the official signed delivery
                        // identity changes. Original job/envelope and failure remain intact.
                        $job = $this->tasks->publish($actionContext, $action['task_type'], ['instance_key' => $instance['instance_key'], 'node_execution_key' => $node['node_execution_key'], 'action_key' => $config['action_key'], 'contract_version' => $config['contract_version'], 'input' => $node['action_input_json'], 'effect_key' => $node['effect_key']], 'workflow-recovery:' . hash('sha256', $old->jobKey . ':' . $old->revision . ':' . $node['revision']));
                    }
                    $metadata += ['replacement_job_key' => $job->jobKey, 'strategy' => $r['strategy'], 'effect_key' => $node['effect_key']];
                    $node->save(['task_job_key' => $job->jobKey, 'status' => 'pending', 'blocked_reason' => null, 'revision' => (int) $node['revision'] + 1]);
                    $instance->save(['status' => 'running', 'blocked_reason' => null, 'revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                } else {
                    $reason = $old->status === 'cancelled' ? 'TASK_CANCELLED: the original queued Task was cancelled.' : 'Task failed before completion (' . ($old->lastErrorCode ?? 'TASK_FAILED') . '); authorized recovery is required.';
                    $node->save(['status' => 'blocked', 'blocked_reason' => $reason, 'revision' => (int) $node['revision'] + 1]);
                    $instance->save(['status' => 'blocked', 'blocked_reason' => $reason, 'revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                }
                $this->event($c, $instance, $node, $recover ? 'action_recovered' : 'action_failure_reconciled', $r['comment'], $metadata, $now);
                return $this->queries->instance($c, $instance['instance_key']);
            },
        );
    }

    private function instanceCommand(AuthorizedOperationContext $c, string $instanceKey, array $r, string $key, string $action, ?AuthorizedOperationContext $taskContext = null): array
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        WorkflowGraph::keys($r, $action === 'reassign' ? ['expected_revision', 'comment', 'work_item_key', 'assignee_member_id', 'expected_task_revision'] : ($action === 'terminate' && array_key_exists('expected_job_revision', $r) ? ['expected_revision', 'comment', 'expected_job_revision'] : ['expected_revision', 'comment']));
        $this->comment($r['comment'], true);
        $basis = Instance::scope('tenant', $this->scope($c))->where('instance_key', $instanceKey)->find() ?? throw WorkflowException::subjectNotFound();
        $graph = $this->version($c, (int) $basis['definition_id'], (int) $basis['definition_version']);
        $permission = in_array($action, ['retry', 'reassign'], true) ? 'diagnostics.' . $action : 'instance.' . $action;
        $authorize = fn() => $this->authorize($c, $graph, $basis['subject_key'], $action, ['peanut.workflow.' . $permission]);
        $prepared = $this->scenarios->prepareSubjectSnapshot($authorize(), $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key']);
        return $this->transactions->run(
            $c,
            'workflow.' . $action,
            $instanceKey,
            $r,
            $key,
            $authorize,
            function (string $now) use ($c, $basis, $graph, $r, $action, $authorize, $taskContext, $prepared): array {
                $authorized = $authorize();
                $this->snapshot($authorized, $basis['subject_type'], $basis['subject_key'], $basis['subject_revision_key'], $prepared);
                $instance = Instance::scope('tenant', $this->scope($c))->where('id', $basis['id'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->expected($r['expected_revision'], (int) $instance['revision'], (string) $instance['instance_key']);
                if (!in_array($instance['status'], ['running', 'blocked'], true)) {
                    throw WorkflowException::alreadyCompleted();
                }
                $nodes = Node::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->whereIn('status', ['active', 'blocked', 'pending'])->order('id')->lock(true)->select();
                foreach ($nodes as $node) {
                    if ($node['effect_key'] !== null && $node['effect_receipt_json'] === null && $node['task_job_key'] !== null && $action === 'terminate') {
                        if ($this->taskJobs === null || $taskContext === null || $taskContext->tenantContext !== $c->tenantContext || !isset($r['expected_job_revision'])) {
                            throw WorkflowException::blocked('Original Task terminal evidence is required before termination.');
                        }
                        $job = $this->taskJobs->detailForRecovery($taskContext, $node['task_job_key'], $r['expected_job_revision']);
                        if ($job->status !== 'cancelled') {
                            throw WorkflowException::blocked('Determine the outstanding business action result before termination.');
                        }
                        $this->event($c, $instance, $node, 'cancelled_action_termination_verified', $r['comment'], ['job_key' => $job->jobKey, 'job_revision' => $job->revision], $now);
                    }
                }
                if ($action === 'withdraw') {
                    if ((int) $instance['initiated_by_member_id'] !== $c->tenantContext->memberId || $instance['substantive_at'] !== null) {
                        throw WorkflowException::assignmentDenied();
                    }
                }
                $eventNode = null;
                $eventMetadata = [];
                if ($action === 'retry') {
                    if ($instance['status'] !== 'blocked' || count($nodes) !== 1 || $nodes[0]['task_job_key'] !== null) {
                        throw WorkflowException::blocked('Task recovery requires its original job state.');
                    }
                    $node = $nodes[0];
                    if ($node['people_json'] !== []) {
                        // Frozen responsibility must be repaired explicitly, never re-resolved to a smaller pool.
                        throw WorkflowException::blocked('Reassign the frozen pending responsibility explicitly.');
                    }
                    $instance->save(['status' => 'running', 'blocked_reason' => null]);
                    $this->enter($authorized, $instance, $graph, $node['node_key'], $now, false, $node['previous_actor_member_id'] === null ? null : (int) $node['previous_actor_member_id'], retryNode: $node);
                } elseif ($action === 'reassign') {
                    $item = Item::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->where('work_item_key', $r['work_item_key'])->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                    $this->expected($r['expected_task_revision'], (int) $item['revision'], (string) $instance['instance_key']);
                    $member = self::memberId($r['assignee_member_id']);
                    if (!$member || $item['status'] !== 'pending' || Item::scope('tenant', $this->scope($c))->where('node_execution_id', $item['node_execution_id'])->where('assignee_member_id', $member)->where('id', '<>', $item['id'])->find() !== null) {
                        throw WorkflowException::assignmentDenied();
                    }
                    $node = Node::scope('tenant', $this->scope($c))->where('id', $item['node_execution_id'])->find();
                    $config = $graph->node($node['node_key'])['config'];
                    if (!$config['allow_self_approval'] && $graph->node($node['node_key'])['type'] === 'approval' && $member === (int) $instance['initiated_by_member_id']) {
                        throw WorkflowException::assignmentDenied();
                    }
                    if (!$this->assignments->eligible($authorized, $member, $item['sources_json'])) {
                        throw WorkflowException::assignmentDenied();
                    }
                    $eventNode = $node;
                    $eventMetadata = [
                        'work_item_key' => (string) $item['work_item_key'],
                        'node_key' => (string) $node['node_key'],
                        'cycle_no' => (int) $node['cycle_no'],
                        'seat_no' => (int) $item['seat_no'],
                        'old_assignee_member_id' => $item['assignee_member_id'] === null ? null : (string) $item['assignee_member_id'],
                        'old_owner_member_id' => $item['owner_member_id'] === null ? null : (string) $item['owner_member_id'],
                        'owner_source' => (string) $item['owner_source'],
                        'frozen_sources' => $item['sources_json'],
                    ];
                    $item->save(['assignee_member_id' => $member, 'owner_member_id' => $item['owner_source'] === 'automatic' ? $member : $item['owner_member_id'], 'assignment_state' => 'assigned', 'revision' => (int) $item['revision'] + 1, 'updated_at' => $now]);
                    $eventMetadata += [
                        'new_assignee_member_id' => (string) $item['assignee_member_id'],
                        'new_owner_member_id' => $item['owner_member_id'] === null ? null : (string) $item['owner_member_id'],
                    ];
                    $node->save(['status' => 'active', 'revision' => (int) $node['revision'] + 1, 'blocked_reason' => null]);
                    $instance->save(['status' => 'running', 'blocked_reason' => null]);
                } else {
                    foreach ($nodes as $node) {
                        $this->cancelItems($c, (int) $node['id'], $now);
                        $node->save(['status' => 'cancelled', 'revision' => (int) $node['revision'] + 1]);
                    }
                    $instance->save(['status' => $action === 'withdraw' ? 'cancelled' : 'terminated', 'cancelled_at' => $now]);
                }
                $instance->save(['revision' => (int) $instance['revision'] + 1, 'updated_at' => $now]);
                $this->event($c, $instance, $eventNode, $action, $r['comment'], $eventMetadata, $now);
                return $this->queries->instance($c, $instance['instance_key']);
            },
        );
    }

    private function enter(AuthorizedOperationContext $c, Instance $instance, WorkflowGraph $graph, string $nodeKey, string $now, bool $initial, ?int $previousActor = null, array $output = [], ?Node $retryNode = null): void
    {
        for ($step = 0; $step < 50; ++$step) {
            $definition = $graph->node($nodeKey);
            $config = $definition['config'];
            $node = $retryNode ?? new Node();
            if ($retryNode === null) {
                $node->save(['tenant_id' => $instance['tenant_id'], 'instance_id' => $instance['id'], 'node_execution_key' => 'node_' . bin2hex(random_bytes(16)), 'node_key' => $nodeKey, 'sequence_no' => (int) Node::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->max('sequence_no') + 1, 'cycle_no' => $instance['cycle_no'], 'previous_actor_member_id' => $previousActor, 'node_type' => $definition['type'], 'status' => 'active', 'revision' => 1, 'allocation_mode' => $config['allocation_mode'] ?? null, 'aggregation_policy' => $config['aggregation_policy'] ?? null, 'input_json' => array_replace($instance['snapshot_json']['fields'], $output), 'people_json' => [], 'created_at' => $now]);
            } else {
                $node->save(['status' => 'active', 'blocked_reason' => null, 'revision' => (int) $node['revision'] + 1]);
                $retryNode = null;
            }
            $instance->save(['current_node_key' => $nodeKey]);
            try {
                if ($definition['type'] === 'end') {
                    $node->save(['status' => 'completed', 'completed_at' => $now]);
                    $instance->save(['status' => 'completed', 'result_code' => $config['result_code'], 'completed_at' => $now]);
                    return;
                }
                if (in_array($definition['type'], ['approval', 'human_task'], true)) {
                    $basis = ['initiator_member_id' => (int) $instance['initiated_by_member_id'], 'business_department_key' => $instance['business_department_key'], 'previous_actor_member_id' => $previousActor, 'subject_owner_member_id' => $this->scenarios->subjectOwner($c, $instance['subject_type'], $instance['subject_key']), 'subject_type' => $instance['subject_type'], 'subject_key' => $instance['subject_key']];
                    $people = $this->resolve($c, $config['assignments'], $basis, $definition['type'] === 'approval' && !$config['allow_self_approval']);
                    if (!$people || ($config['allocation_mode'] === 'direct' && count($people) !== 1)) {
                        throw WorkflowException::unresolved('Direct allocation requires exactly one person; required pools cannot be empty.');
                    }
                    $participants = $this->resolve($c, $config['participant_rules'], $basis, false);
                    $watchers = $this->resolve($c, $config['watcher_rules'], $basis, false);
                    $node->save(['people_json' => $people]);
                    $seats = $config['allocation_mode'] === 'claim' ? [null] : $people;
                    foreach ($seats as $seat => $person) {
                        $item = new Item();
                        $item->save(['tenant_id' => $instance['tenant_id'], 'instance_id' => $instance['id'], 'node_execution_id' => $node['id'], 'work_item_key' => 'work_' . bin2hex(random_bytes(16)), 'node_key' => $nodeKey, 'round_no' => $instance['cycle_no'], 'seat_no' => $seat + 1, 'assignment_source_kind' => $person['sources'][0]['source_kind'] ?? 'claim', 'assignment_source_key' => $person['sources'][0]['source_key'] ?? $nodeKey, 'sources_json' => $person['sources'] ?? [], 'assignee_member_id' => $person['member_id'] ?? null, 'owner_member_id' => $person['member_id'] ?? null, 'owner_source' => 'automatic', 'assignment_state' => $person === null ? 'unassigned' : 'assigned', 'status' => 'pending', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);
                        foreach (['candidate' => $config['allocation_mode'] === 'claim' ? $people : [], 'participant' => $participants, 'watcher' => $watchers] as $relation => $members) {
                            foreach ($members as $member) {
                                (new Link())->save(['tenant_id' => $instance['tenant_id'], 'work_item_id' => $item['id'], 'member_id' => $member['member_id'], 'relation_type' => $relation, 'sources_json' => $member['sources']]);
                            }
                        }
                    }
                    return;
                }
                if ($definition['type'] === 'service_task') {
                    $this->lifecycle->assertTaskAvailable($c->tenantContext->tenantId, true);
                    if ($this->tasks === null) {
                        throw WorkflowException::providerUnavailable();
                    }
                    $scenario = $this->scenario($c, $instance['module_key'], $instance['workflow_key'], $graph);
                    $action = $scenario['actions'][$config['action_key']] ?? throw WorkflowException::unsupported('action:' . $config['action_key']);
                    if ($action['contract_version'] !== $config['contract_version']) {
                        throw WorkflowException::unsupported('action_contract_version');
                    }
                    $actionContext = $this->authorize($c, $graph, $instance['subject_key'], $action['operation'], $action['permission_keys']);
                    $input = \PeanutAdmin\Modules\Workflow\Definition\WorkflowActionInput::resolve($config['input'], $instance->toArray(), $node['input_json'], $scenario['fields'], $action['input_schema']);
                    $node->save(['action_input_json' => $input]);
                    $effect = 'workflow-effect:' . hash('sha256', $instance['instance_key'] . ':' . $node['node_execution_key'] . ':' . $config['action_key']);
                    try {
                        $job = $this->tasks->publish($actionContext, $action['task_type'], ['instance_key' => $instance['instance_key'], 'node_execution_key' => $node['node_execution_key'], 'action_key' => $config['action_key'], 'contract_version' => $config['contract_version'], 'input' => $input, 'effect_key' => $effect], $effect);
                    } catch (\PeanutAdmin\Modules\Task\Job\Application\TaskJobException) {
                        throw WorkflowException::providerUnavailable();
                    }
                    $node->save(['status' => 'pending', 'effect_key' => $effect, 'task_job_key' => $job->jobKey]);
                    return;
                }
                if ($definition['type'] !== 'exclusive_gateway') {
                    throw WorkflowException::unsupported($definition['type']);
                }
                $scenario = $this->scenario($c, $instance['module_key'], $instance['workflow_key'], $graph);
                $selected = $config['default_transition_key'];
                foreach ($config['branches'] as $branch) {
                    if (WorkflowCondition::evaluate($branch['condition'], $node['input_json'], $scenario['fields'])) {
                        $selected = $branch['transition_key'];
                        break;
                    }
                }
                $edge = array_values(array_filter($graph->outgoing($nodeKey), static fn(array $e): bool => $e['key'] === $selected))[0];
                $node->save(['status' => 'completed', 'completed_at' => $now, 'selected_transition_key' => $selected]);
                $nodeKey = $edge['to'];
            } catch (WorkflowException $e) {
                if ($initial || !in_array($e->errorCode, ['ASSIGNEE_UNRESOLVED', 'ACTION_BLOCKED', 'WORKFLOW_PROVIDER_UNAVAILABLE', 'CAPABILITY_UNSUPPORTED', 'WORKFLOW_DEFINITION_INVALID'], true)) {
                    throw $e;
                }
                $node->save(['status' => 'blocked', 'blocked_reason' => substr($e->getMessage(), 0, 500)]);
                $instance->save(['status' => 'blocked', 'blocked_reason' => substr($e->getMessage(), 0, 500)]);
                return;
            }
        }
        if ($initial) {
            throw WorkflowException::blocked('Automatic step capacity exceeded.');
        }
        $node->save(['status' => 'blocked', 'blocked_reason' => 'Automatic step capacity exceeded.']);
        $instance->save(['status' => 'blocked', 'blocked_reason' => 'Automatic step capacity exceeded.']);
    }

    private function resolve(AuthorizedOperationContext $c, array $rules, array $basis, bool $excludeInitiator): array
    {
        $members = [];
        foreach ($rules as $rule) {
            $resolved = $this->assignments->resolve($c, $rule, $basis);
            $valid = 0;
            foreach ($resolved as $person) {
                WorkflowGraph::keys($person, ['member_id', 'source_kind', 'source_key', 'binding_revision']);
                if ($person['source_kind'] !== $rule['kind']) {
                    throw WorkflowException::providerUnavailable();
                }
                $this->text($person['source_key'], 160);
                $this->text($person['binding_revision'], 160);
                if (!is_int($person['member_id']) || $person['member_id'] < 1 || ($excludeInitiator && $person['member_id'] === $basis['initiator_member_id'])) {
                    continue;
                }
                $source = ['source_kind' => $person['source_kind'], 'source_key' => $person['source_key'], 'binding_revision' => $person['binding_revision']];
                if (!$this->assignments->eligible($c, $person['member_id'], [$source])) {
                    continue;
                }
                ++$valid;
                $members[$person['member_id']] ??= ['member_id' => $person['member_id'], 'sources' => []];
                $members[$person['member_id']]['sources'][WorkflowGraph::json($source)] = $source;
            }
            if ($rule['required'] && $valid === 0) {
                throw WorkflowException::unresolved('Required source is empty: ' . $rule['kind'] . ':' . ($rule['key'] ?? 'context'));
            }
        }
        if (count($members) > 100) {
            throw WorkflowException::unresolved('Assignment capacity exceeded.');
        }
        ksort($members, SORT_NUMERIC);
        return array_values(array_map(static fn(array $m): array => ['member_id' => $m['member_id'], 'sources' => array_values($m['sources'])], $members));
    }

    private function event(AuthorizedOperationContext $c, Instance $instance, ?Node $node, string $action, ?string $comment, array $metadata, string $now, ?string $edge = null, array $attachments = [], bool $system = false): void
    {
        $sequence = (int) Event::scope('tenant', $this->scope($c))->where('instance_id', $instance['id'])->max('sequence_no') + 1;
        $notification = null;
        $graph = $this->version($c, (int) $instance['definition_id'], (int) $instance['definition_version']);
        if (!$system && $node !== null && in_array($action, ['approve', 'reject'], true) && in_array('workflow.notification', $graph->data['required_capabilities'], true)) {
            $scenario = $this->scenarios->scenario($c, $instance['module_key'], $instance['workflow_key']);
            $source = $graph->edge($node['node_key'], $action);
            $notification = ['producer_key' => $scenario['notification']['producer_key'], 'event_key' => $instance['instance_key'] . ':' . $sequence,
                'business_key' => $instance['subject_key'], 'operation' => $source['operation'], 'permission_keys' => $source['permission_keys'],
                'recipients' => [['member_id' => (int) $instance['initiated_by_member_id'], 'variables' => ['title' => (string) ($instance['snapshot_json']['fields']['title'] ?? $instance['subject_key']), 'decision' => $action]]],
                'file_keys' => array_column($attachments, 'file_key'),
                'source_session' => ['account_id' => $c->tenantContext->accountId, 'member_id' => $c->tenantContext->memberId, 'session_key' => $c->tenantContext->sessionKey,
                    'client_key' => $c->tenantContext->clientKey, 'request_id' => $c->tenantContext->requestId]];
        }
        (new Event())->save(['tenant_id' => $instance['tenant_id'], 'instance_id' => $instance['id'], 'node_execution_id' => $node['id'] ?? null, 'sequence_no' => $sequence, 'event_key' => 'tenant.workflow.' . $action, 'transition_key' => $edge, 'from_node_key' => $node['node_key'] ?? null, 'to_node_key' => $instance['current_node_key'], 'actor_type' => $system ? 'tenant_system' : 'member', 'actor_member_id' => $system ? null : $c->tenantContext->memberId, 'subject_revision_key' => $instance['subject_revision_key'], 'subject_revision_sha256' => $instance['subject_revision_sha256'], 'comment_text' => $comment, 'comment_sha256' => $comment === null ? null : hash('sha256', $comment), 'attachment_snapshots_json' => $attachments, 'metadata_json' => $metadata, 'notification_fact_json' => $notification, 'occurred_at' => $now]);
        if ($system) {
            $this->audit->tenantSystem((int) $instance['tenant_id'], 'tenant.workflow.' . $action, 'peanut.workflow.instance.read', $c->tenantContext->requestId, ['instance_key' => $instance['instance_key'], 'revision' => (int) $instance['revision']]);
        } else {
            $this->audit->tenantMember($c->tenantContext, 'tenant.workflow.' . $action, 'peanut.workflow.instance.read', 'workflow_instance', $instance['instance_key'], targetCount: 1, metadata: ['revision' => (int) $instance['revision']]);
        }
    }

    private function cancelItems(AuthorizedOperationContext $c, int $nodeId, string $now): void
    {
        Item::scope('tenant', $this->scope($c))->where('node_execution_id', $nodeId)->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now, 'revision' => Db::raw('revision + 1')]);
    }
    private function version(AuthorizedOperationContext $c, int $definition, int $version): WorkflowGraph
    {
        $row = Version::scope('tenant', $this->scope($c))->where('definition_id', $definition)->where('version', $version)->find() ?? throw WorkflowException::subjectNotFound();
        $graph = WorkflowGraph::fromArray($row['graph_json']);
        if (!hash_equals($row['graph_sha256'], $graph->sha256)) {
            throw WorkflowException::internal();
        }
        return $graph;
    }
    private function authorize(AuthorizedOperationContext $basis, WorkflowGraph $graph, string $subject, string $operation, array $permissions): AuthorizedOperationContext
    {
        $c = $this->authorization->authorize($basis, $graph->data['subject_resource_key'], $operation, array_values(array_unique($permissions)), $subject);
        if ($c->tenantContext !== $basis->tenantContext || $c->resourceKey !== $graph->data['subject_resource_key'] || $c->operation !== $operation || count($c->targets) !== 1 || $c->targets[0]->targetIds !== [$subject] || $c->targets[0]->targetResourceKey !== $c->resourceKey || $c->targets[0]->targetRole !== 'primary') {
            throw WorkflowException::subjectNotFound();
        }
        return $c;
    }
    private function snapshot(AuthorizedOperationContext $c, string $type, string $subject, string $revision, object $prepared): array
    {
        $snapshot = $this->scenarios->snapshot($c, $type, $subject, $revision, true, $prepared);
        if (($snapshot['revision_key'] ?? null) !== $revision || !is_string($snapshot['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $snapshot['sha256']) !== 1 || !is_array($snapshot['fields'] ?? null) || !is_array($snapshot['reference'] ?? null)) {
            throw WorkflowException::subjectRevisionConflict();
        }
        return $snapshot;
    }
    private function scenario(AuthorizedOperationContext $c, string $module, string $workflow, WorkflowGraph $graph): array
    {
        $scenario = $this->scenarios->scenario($c, $module, $workflow);
        if (($scenario['contract_version'] ?? null) !== $graph->data['scenario_contract_version']) {
            throw WorkflowException::unsupported('scenario_contract_version');
        }
        foreach ($graph->data['required_capabilities'] as $capability) {
            if (!in_array($capability, $scenario['capabilities'], true) || ($capability === 'workflow.service_task' && $this->tasks === null)) {
                throw WorkflowException::unsupported($capability);
            }
        }
        return $scenario;
    }
    private function attachmentSnapshots(AuthorizedOperationContext $c, mixed $keys): array
    {
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 100) {
            throw WorkflowException::attachmentUnavailable();
        }
        $snapshots = [];
        foreach ($keys as $fileKey) {
            $this->text($fileKey, 160);
        }
        foreach (array_unique($keys) as $key) {
            $this->text($key, 160);
            if ($this->attachments === null) {
                throw WorkflowException::providerUnavailable();
            }
            $snapshot = $this->attachments->snapshot($c, $key);
            if ($snapshot->fileKey !== $key) {
                throw WorkflowException::attachmentUnavailable();
            }
            $snapshots[] = $snapshot->toArray();
        }
        return $snapshots;
    }
    private function expected(mixed $revision, int $actual, ?string $instanceKey = null): void
    {
        if (!is_int($revision) || $revision < 1 || $revision !== $actual) {
            throw WorkflowException::instanceConflict($instanceKey);
        }
    }
    public static function memberId(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || strlen($value) > strlen((string) PHP_INT_MAX) || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw WorkflowException::definitionInvalid('Member ID must be a positive bounded decimal string.');
        }
        return (int) $value;
    }
    private function text(mixed $value, int $max): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max) {
            throw WorkflowException::definitionInvalid('Invalid text field.');
        }
    }
    private function comment(mixed $value, bool $required): ?string
    {
        if ($value !== null && (!is_string($value) || mb_strlen($value) > 2000)) {
            throw WorkflowException::definitionInvalid('Invalid opinion.');
        }
        if ($required && ($value === null || trim($value) === '')) {
            throw WorkflowException::definitionInvalid('A reason is required.');
        }
        return $value;
    }
    private function scope(AuthorizedOperationContext $c): TenantScope
    {
        return TenantScope::fromTrustedContext($c->tenantContext->tenantId, 'workflow');
    }
}
