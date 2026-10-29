<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Definition\WorkflowGraph;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionRecord as Definition;
use PeanutAdmin\Modules\Workflow\Persistence\Model\WorkflowDefinitionVersionRecord as Version;

/** Trusted native install/upgrade only. No general HTTP import or signature key requirement. */
final readonly class WorkflowManagedDefinitions
{
    public function __construct(private CompiledModuleRegistry $registry, private WorkflowCommandTransaction $transactions, private WorkflowDefinitionValidator $validator, private WorkflowAssignmentResolver $assignments, private AuditService $audit, private WorkflowLifecycleQueries $lifecycle) {}

    public function install(AuthorizedOperationContext $context, string $module, string $workflow, string $expectedManifestDigest, array $bindings, string $idempotencyKey): array
    {
        $guard = static function () use ($context): void {
            if ($context->resourceKey !== 'peanut.workflow.managed-definition' || $context->operation !== 'install' || $context->targets !== [] || $context->tenantContext->memberId < 1) {
                throw WorkflowException::assignmentDenied();
            }
        };
        $guard();
        $manifest = $this->registry->requireManifest($module);
        if (!hash_equals($manifest->digest, $expectedManifestDigest) || preg_match('/^[a-z][a-z0-9.-]*$/D', $workflow) !== 1) {
            throw WorkflowException::definitionInvalid('Fixed Module manifest and workflow key are required.');
        }
        $path = $manifest->root . '/resources/workflows/' . $workflow . '.json';
        if (!is_file($path) || is_link($path) || realpath(dirname($path)) !== realpath($manifest->root . '/resources/workflows')) {
            throw WorkflowException::definitionInvalid('Owned managed template is required.');
        }
        $bytes = (string) file_get_contents($path);
        $template = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        WorkflowGraph::keys($template, ['template_version', 'module_key', 'workflow_key', 'scenario_contract_version', 'graph_sha256', 'graph']);
        $source = WorkflowGraph::fromArray($template['graph']);
        if ($template['module_key'] !== $module || $template['workflow_key'] !== $workflow || !is_int($template['template_version']) || $template['template_version'] < 1
            || $template['scenario_contract_version'] !== $source->data['scenario_contract_version'] || !hash_equals($source->sha256, $template['graph_sha256'])) {
            throw WorkflowException::definitionInvalid('Managed template identity or digest mismatch.');
        }
        WorkflowGraph::keys($bindings, ['departments', 'roles']);
        $bound = $source->data;
        foreach ($bound['nodes'] as &$node) {
            foreach (['assignments', 'participant_rules', 'watcher_rules'] as $field) {
                foreach ($node['config'][$field] ?? [] as $index => $rule) {
                    $group = match ($rule['kind']) {
                        'department_members', 'department_leaders' => 'departments', 'role_members' => 'roles', default => null
                    };
                    if ($group !== null && $rule['key'] !== null) {
                        $key = $bindings[$group][$rule['key']] ?? null;
                        if (!is_string($key) || $key === '' || strlen($key) > 128) {
                            throw WorkflowException::unresolved('Bind logical organization key: ' . $rule['key']);
                        }
                        $rule['key'] = $key;
                    }
                    if ($rule['department_scope'] !== null) {
                        $scope = $bindings['departments'][$rule['department_scope']] ?? null;
                        if (!is_string($scope) || $scope === '') {
                            throw WorkflowException::unresolved('Bind logical department scope.');
                        }
                        $rule['department_scope'] = $scope;
                    }
                    if ($group !== null && $rule['key'] !== null && $rule['required'] && $this->assignments->resolve($context, $rule, []) === []) {
                        throw WorkflowException::unresolved('The bound organization has no eligible member.');
                    }
                    $node['config'][$field][$index] = $rule;
                }
            }
        }
        unset($node);
        $graph = WorkflowGraph::fromArray($bound);
        $this->validator->validate($context, $module, $workflow, $graph);
        $metadata = ['module_key' => $module, 'module_version' => $manifest->data['version'], 'manifest_sha256' => $manifest->digest, 'template_version' => $template['template_version'], 'template_sha256' => hash('sha256', $bytes), 'source_graph_sha256' => $source->sha256, 'installed_graph_sha256' => $graph->sha256, 'bindings' => $bindings];
        return $this->transactions->run(
            $context,
            'workflow.install-managed',
            $module . ':' . $workflow,
            $metadata,
            $idempotencyKey,
            $guard,
            function (string $now) use ($context, $module, $workflow, $graph, $metadata): array {
                $this->lifecycle->assertNewOperations($context->tenantContext->tenantId, true);
                $scope = TenantScope::fromTrustedContext($context->tenantContext->tenantId, 'workflow');
                $row = Definition::scope('tenant', $scope)->where('module_key', $module)->where('workflow_key', $workflow)->lock(true)->find();
                if ($row !== null) {
                    $baseline = $row['managed_template_json'];
                    $active = $row['active_version'] === null ? null : Version::scope('tenant', $scope)->where('definition_id', $row['id'])->where('version', $row['active_version'])->find();
                    if ($row['status'] !== 'active' || $active === null || $active['graph_sha256'] !== ($baseline['installed_graph_sha256'] ?? null)) {
                        throw WorkflowException::definitionConflict();
                    }
                    if (!is_array($baseline) || ($baseline['module_key'] ?? null) !== $module || $row['draft_graph_sha256'] !== ($baseline['installed_graph_sha256'] ?? null)) {
                        throw WorkflowException::definitionConflict();
                    }
                    if ($metadata['template_version'] < $baseline['template_version'] || ($metadata['template_version'] === $baseline['template_version'] && ($metadata['source_graph_sha256'] !== $baseline['source_graph_sha256'] || $metadata['template_sha256'] !== $baseline['template_sha256']))) {
                        throw WorkflowException::definitionConflict();
                    }
                    if ($baseline === $metadata) {
                        return ['definition_id' => (string) $row['id'], 'active_version' => (int) $row['active_version'], 'draft_revision' => (int) $row['revision'], 'changed' => false];
                    }
                } else {
                    $row = new Definition();
                    $row->save(['tenant_id' => $context->tenantContext->tenantId, 'module_key' => $module, 'workflow_key' => $workflow, 'status' => 'draft', 'draft_graph_json' => $graph->data, 'draft_graph_sha256' => $graph->sha256, 'layout_json' => ['nodes' => [], 'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1]], 'latest_version' => 0, 'active_version' => null, 'revision' => 1, 'created_by_member_id' => $context->tenantContext->memberId, 'updated_by_member_id' => $context->tenantContext->memberId, 'created_at' => $now, 'updated_at' => $now]);
                }
                $version = Version::scope('tenant', $scope)->where('definition_id', $row['id'])->where('graph_sha256', $graph->sha256)->find();
                $number = $version === null ? (int) $row['latest_version'] + 1 : (int) $version['version'];
                if ($version === null) {
                    (new Version())->save(['tenant_id' => $context->tenantContext->tenantId, 'definition_id' => $row['id'], 'version' => $number, 'graph_json' => $graph->data, 'graph_sha256' => $graph->sha256, 'contract_version' => 2, 'published_by_member_id' => $context->tenantContext->memberId, 'published_at' => $now]);
                }
                $row->save(['status' => 'active', 'retired_at' => null, 'draft_graph_json' => $graph->data, 'draft_graph_sha256' => $graph->sha256, 'managed_template_json' => $metadata, 'latest_version' => max((int) $row['latest_version'], $number), 'active_version' => $number, 'revision' => (int) $row['revision'] + 1, 'updated_by_member_id' => $context->tenantContext->memberId, 'updated_at' => $now]);
                $this->audit->tenantMember($context->tenantContext, 'tenant.workflow.managed_definition_installed', 'peanut.workflow.managed.install', 'workflow_definition', (string) $row['id'], targetCount: 1, metadata: ['template_version' => $metadata['template_version'], 'graph_sha256' => $graph->sha256]);
                return ['definition_id' => (string) $row['id'], 'active_version' => $number, 'draft_revision' => (int) $row['revision'], 'changed' => true];
            },
        );
    }
}
