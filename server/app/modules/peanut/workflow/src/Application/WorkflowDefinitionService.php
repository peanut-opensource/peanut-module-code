<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionRecord as Definition;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord as Version;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Task\Contract\TrustedJobPublisher;

final readonly class WorkflowDefinitionService
{
    public function __construct(private WorkflowCommandTransaction $transactions, private WorkflowScenarioProvider $scenarios, private WorkflowAssignmentResolver $assignments, private AuditService $audit, private WorkflowLifecycleQueries $lifecycle, private \PeanutAdmin\Kernel\Module\ModuleAvailability $modules, private WorkflowAuthorizationResolver $authorization, private ?TrustedJobPublisher $tasks = null, private ?\PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry $producers = null) {}

    public function saveDraft(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey, array $request, string $idempotencyKey): array
    {
        WorkflowGraph::keys($request, ['expected_revision', 'graph', 'layout']);
        if (!is_array($request['graph']) || !is_array($request['layout'])) {
            throw WorkflowException::definitionInvalid('Graph and layout objects required.');
        }
        $graph = WorkflowGraph::fromArray($request['graph'], false);
        WorkflowGraph::keys($request['layout'], ['nodes', 'viewport']);
        if (!is_array($request['layout']['nodes']) || !array_is_list($request['layout']['nodes']) || strlen(WorkflowGraph::json($request['layout'])) > 65535) {
            throw WorkflowException::definitionInvalid('Invalid layout.');
        }
        $layoutKeys = [];
        foreach ($request['layout']['nodes'] as $position) {
            WorkflowGraph::keys($position, ['key', 'x', 'y']);
            if (!is_string($position['key']) || isset($layoutKeys[$position['key']]) || !in_array($position['key'], array_column($graph->data['nodes'], 'key'), true)) {
                throw WorkflowException::definitionInvalid('Invalid layout node.');
            }
            $layoutKeys[$position['key']] = true;
            foreach (['x', 'y'] as $coordinate) {
                if ((!is_int($position[$coordinate]) && !is_float($position[$coordinate])) || !is_finite((float) $position[$coordinate]) || abs($position[$coordinate]) > 1000000) {
                    throw WorkflowException::definitionInvalid('Invalid layout coordinate.');
                }
            }
        }
        WorkflowGraph::keys($request['layout']['viewport'], ['x', 'y', 'zoom']);
        foreach ($request['layout']['viewport'] as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw WorkflowException::definitionInvalid('Invalid viewport.');
            }
        }
        if ($request['layout']['viewport']['zoom'] <= 0 || $request['layout']['viewport']['zoom'] > 100) {
            throw WorkflowException::definitionInvalid('Invalid zoom.');
        }
        return $this->transactions->run(
            $context,
            'workflow.save-draft',
            $moduleKey . '.' . $workflowKey,
            $request,
            $idempotencyKey,
            fn() => $this->guard($context, 'write'),
            function (string $now) use ($context, $moduleKey, $workflowKey, $request, $graph): array {
                $row = $this->definition($context, $moduleKey, $workflowKey)->lock(true)->find();
                if ($row === null) {
                    if ($request['expected_revision'] !== null) {
                        throw WorkflowException::definitionConflict();
                    }
                    $row = new Definition();
                    $row->save(['tenant_id' => $context->tenantContext->tenantId, 'module_key' => $moduleKey, 'workflow_key' => $workflowKey, 'status' => 'draft', 'draft_graph_json' => $graph->data, 'draft_graph_sha256' => $graph->sha256, 'layout_json' => $request['layout'], 'latest_version' => 0, 'active_version' => null, 'revision' => 1, 'created_by_member_id' => $context->tenantContext->memberId, 'updated_by_member_id' => $context->tenantContext->memberId, 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    $this->revision($row, $request['expected_revision']);
                    if ($row['status'] === 'retired') {
                        throw WorkflowException::definitionRetired();
                    }
                    $row->save(['draft_graph_json' => $graph->data, 'draft_graph_sha256' => $graph->sha256, 'layout_json' => $request['layout'], 'revision' => (int) $row['revision'] + 1, 'updated_at' => $now, 'updated_by_member_id' => $context->tenantContext->memberId]);
                }
                $this->auditDefinition($context, $row, 'draft_saved', 'write');
                return ['definition_id' => (string) $row['id'], 'draft_revision' => (int) $row['revision'], 'issues' => $this->issues($request['graph'])];
            },
        );
    }

    public function validate(AuthorizedOperationContext $context, array $graph): array
    {
        $this->guard($context, 'write');
        return ['issues' => $this->issues($graph)];
    }

    public function preview(AuthorizedOperationContext $context, array $graph, array $basis): array
    {
        $this->guard($context, 'write');
        $parsed = WorkflowGraph::fromArray($graph);
        WorkflowGraph::keys($basis, ['initiator_member_id', 'business_department_key', 'previous_actor_member_id', 'subject_type', 'subject_key']);
        $basis['initiator_member_id'] = WorkflowCommands::memberId($basis['initiator_member_id']);
        $basis['previous_actor_member_id'] = $basis['previous_actor_member_id'] === null ? null : WorkflowCommands::memberId($basis['previous_actor_member_id']);
        if ($basis['business_department_key'] !== null && (!is_string($basis['business_department_key']) || strlen($basis['business_department_key']) > 160)) {
            throw WorkflowException::definitionInvalid('Invalid business department.');
        }
        if (!is_string($basis['subject_type']) || !is_string($basis['subject_key']) || $basis['subject_type'] === '' || $basis['subject_key'] === ''
            || strlen($basis['subject_type']) > 160 || strlen($basis['subject_key']) > 160
            || $basis['subject_type'] !== $parsed->data['subject_resource_key']) {
            throw WorkflowException::definitionInvalid('Invalid preview subject.');
        }
        $subjectContext = $this->authorization->authorize($context, $parsed->data['subject_resource_key'], $parsed->data['subject_read_operation'], ['peanut.workflow.definition.write'], $basis['subject_key']);
        $basis['business_department_key'] = $this->scenarios->businessDepartment($subjectContext, $basis['business_department_key']);
        $basis['subject_owner_member_id'] = $this->scenarios->subjectOwner($subjectContext, $basis['subject_type'], $basis['subject_key']);
        $nodes = [];
        foreach ($parsed->data['nodes'] as $node) {
            $members = [];
            foreach ($node['config']['assignments'] ?? [] as $rule) {
                foreach ($this->assignments->resolve($subjectContext, $rule, $basis) as $member) {
                    $member['member_id'] = (string) $member['member_id'];
                    $members[] = $member;
                }
            }
            $nodes[] = ['node_key' => $node['key'], 'members' => $members];
        }
        return ['simulated' => true, 'nodes' => $nodes, 'issues' => []];
    }

    /** Public design metadata omits all internal implementation/Task registration names. */
    public function scenarioMetadata(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey): array
    {
        $this->guard($context, 'read');
        $scenario = $this->scenarios->scenario($context, $moduleKey, $workflowKey);
        $actions = [];
        foreach ($scenario['actions'] ?? [] as $key => $action) {
            $actions[] = ['action_key' => $key, 'contract_version' => $action['contract_version'], 'input_schema' => $action['input_schema']];
        }
        return ['scenario_contract_version' => $scenario['contract_version'], 'fields' => $scenario['fields'], 'result_codes' => $scenario['result_codes'], 'capabilities' => $scenario['capabilities'], 'actions' => $actions];
    }

    public function publish(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey, array $request, string $idempotencyKey): array
    {
        WorkflowGraph::keys($request, ['expected_revision']);
        return $this->transactions->run(
            $context,
            'workflow.publish',
            $moduleKey . '.' . $workflowKey,
            $request,
            $idempotencyKey,
            fn() => $this->guard($context, 'publish'),
            function (string $now) use ($context, $moduleKey, $workflowKey, $request): array {
                $row = $this->definition($context, $moduleKey, $workflowKey)->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->revision($row, $request['expected_revision']);
                if ($row['status'] === 'retired') {
                    throw WorkflowException::definitionRetired();
                }
                $graph = WorkflowGraph::fromArray($row['draft_graph_json']);
                $this->checkScenario($context, $moduleKey, $workflowKey, $graph);
                $version = (int) $row['latest_version'] + 1;
                $identical = Version::scope('tenant', $this->scope($context))->where('definition_id', $row['id'])->where('graph_sha256', $graph->sha256)->find();
                if ($identical !== null) {
                    $version = (int) $identical['version'];
                } else {
                    (new Version())->save(['tenant_id' => $context->tenantContext->tenantId, 'definition_id' => $row['id'], 'version' => $version, 'graph_json' => $graph->data, 'graph_sha256' => $graph->sha256, 'contract_version' => 2, 'published_by_member_id' => $context->tenantContext->memberId, 'published_at' => $now]);
                }
                $row->save(['latest_version' => max((int) $row['latest_version'], $version), 'active_version' => $version, 'revision' => (int) $row['revision'] + 1, 'status' => 'active', 'updated_at' => $now]);
                $this->auditDefinition($context, $row, 'published', 'publish');
                return ['definition_id' => (string) $row['id'], 'version' => $version, 'active_version' => $version, 'draft_revision' => (int) $row['revision']];
            },
        );
    }

    private function checkScenario(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey, WorkflowGraph $graph): void
    {
        $this->lifecycle->assertNewOperations($context->tenantContext->tenantId, true);
        (new WorkflowDefinitionValidator($this->scenarios, $this->tasks, $this->modules, $this->producers))->validate($context, $moduleKey, $workflowKey, $graph);
    }

    public function activate(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey, array $request, string $idempotencyKey): array
    {
        return $this->change($context, $moduleKey, $workflowKey, $request, $idempotencyKey, false);
    }

    public function retire(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey, array $request, string $idempotencyKey): array
    {
        return $this->change($context, $moduleKey, $workflowKey, $request, $idempotencyKey, true);
    }

    private function change(AuthorizedOperationContext $c, string $module, string $workflow, array $r, string $key, bool $retire): array
    {
        WorkflowGraph::keys($r, $retire ? ['expected_revision'] : ['expected_revision', 'version']);
        return $this->transactions->run(
            $c,
            $retire ? 'workflow.retire' : 'workflow.activate',
            $module . '.' . $workflow,
            $r,
            $key,
            fn() => $this->guard($c, 'publish'),
            function (string $now) use ($c, $module, $workflow, $r, $retire): array {
                $row = $this->definition($c, $module, $workflow)->lock(true)->find() ?? throw WorkflowException::subjectNotFound();
                $this->revision($row, $r['expected_revision']);
                if (!$retire) {
                    $version = Version::scope('tenant', $this->scope($c))->where('definition_id', $row['id'])->where('version', $r['version'])->find() ?? throw WorkflowException::subjectNotFound();
                    $graph = WorkflowGraph::fromArray($version['graph_json']);
                    if (!hash_equals($version['graph_sha256'], $graph->sha256)) {
                        throw WorkflowException::internal();
                    }
                    $this->checkScenario($c, $module, $workflow, $graph);
                }
                $row->save(['status' => $retire ? 'retired' : 'active', 'active_version' => $retire ? null : $r['version'], 'revision' => (int) $row['revision'] + 1, 'retired_at' => $retire ? $now : null, 'updated_at' => $now]);
                $this->auditDefinition($c, $row, $retire ? 'retired' : 'activated', 'publish');
                return ['definition_id' => (string) $row['id'], 'draft_revision' => (int) $row['revision'], 'active_version' => $row['active_version'], 'status' => $row['status']];
            },
        );
    }

    private function issues(array $graph): array
    {
        try {
            WorkflowGraph::fromArray($graph);
            return [];
        } catch (WorkflowException $e) {
            return [$e->validationIssue()];
        }
    }

    private function guard(AuthorizedOperationContext $c, string $operation): void
    {
        $this->lifecycle->assertAvailable($c->tenantContext->tenantId);
        if ($c->resourceKey !== 'peanut.workflow.definition' || $c->operation !== $operation || $c->targets !== [] || $c->tenantContext->memberId < 1) {
            throw WorkflowException::subjectNotFound();
        }
    }

    private function revision(mixed $row, mixed $revision): void
    {
        if (!is_int($revision) || $revision < 1 || (int) $row['revision'] !== $revision) {
            throw WorkflowException::definitionConflict();
        }
    }

    private function definition(AuthorizedOperationContext $c, string $module, string $workflow): mixed
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $module) !== 1 || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $workflow) !== 1) {
            throw WorkflowException::definitionInvalid();
        }
        return Definition::scope('tenant', $this->scope($c))->where('module_key', $module)->where('workflow_key', $workflow);
    }

    private function scope(AuthorizedOperationContext $c): TenantScope
    {
        return TenantScope::fromTrustedContext($c->tenantContext->tenantId, 'workflow');
    }

    private function auditDefinition(AuthorizedOperationContext $c, Definition $row, string $action, string $permission): void
    {
        $this->audit->tenantMember($c->tenantContext, 'tenant.workflow.definition_' . $action, 'peanut.workflow.definition.' . $permission, 'workflow_definition', (string) $row['id'], targetCount: 1, metadata: ['revision' => (int) $row['revision']]);
    }
}
