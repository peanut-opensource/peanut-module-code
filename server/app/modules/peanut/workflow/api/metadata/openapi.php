<?php

declare(strict_types=1);

// Runtime-only HTTP contracts survive the physical designer projection.
$ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$text = ['type' => 'string'];
$integer = ['type' => 'integer', 'minimum' => 1];
$instance = [['in' => 'path', 'name' => 'instanceKey', 'required' => true, 'schema' => $text]];
$response = static fn(array $data, array $meta = []): array => ['description' => 'Current-authorized Workflow runtime result.',
    'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => [
        'data' => $data, 'meta' => ['type' => 'object', 'required' => ['request_id'], 'properties' => ['request_id' => ['type' => 'string']] + $meta],
    ]]]]];
$operation = static fn(string $name, array $data, array $parameters, array $meta = []): array => ['operationId' => $name,
    'tags' => ['Workflow'], 'parameters' => $parameters, 'responses' => ['200' => $response($data, $meta),
        '400' => ['$ref' => '#/components/responses/WorkflowRuntimeError'], '401' => ['$ref' => '#/components/responses/WorkflowRuntimeError'],
        '403' => ['$ref' => '#/components/responses/WorkflowRuntimeError'], '404' => ['$ref' => '#/components/responses/WorkflowRuntimeError'],
        '409' => ['$ref' => '#/components/responses/WorkflowRuntimeError']]];
// Designer read contract: these two tenant-authorized GETs are issued together
// by WorkflowDesignerPage. The owning Query/Projection classes determine shape.
$nonnegative = ['type' => 'integer', 'minimum' => 0];
$designerDefinitionParams = [
    ['in' => 'path', 'name' => 'moduleKey', 'required' => true,
        'schema' => $text + ['pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$']],
    ['in' => 'path', 'name' => 'workflowKey', 'required' => true,
        'schema' => $text + ['pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$']],
];
// Definition and scenario capability validation can return the native 422 envelope.
$designerRead = static function (string $name, array $data) use ($operation, $designerDefinitionParams): array {
    $contract = $operation($name, $data, $designerDefinitionParams);
    $contract['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
    return $contract;
};
// Designer mutation contracts reuse the same authored graph/response boundary as reads.
// Only CAS/idempotent writes require Idempotency-Key. Validate/preview are simulations.
$designerWrite = static function (
    string $name, array $data, array $body, bool $transactional,
    array $parameters = [],
) use ($operation): array {
    $value = $operation($name, $data, $parameters);
    $value['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
    $value['parameters'][] = ['in' => 'header', 'name' => 'Idempotency-Key',
        'required' => $transactional,
        // PHP Core IdempotencyKey::fromString accepts 16..128 visible ASCII bytes.
        'schema' => ['type' => 'string', 'pattern' => '^[!-~]{16,128}$']];
    $value['requestBody'] = ['required' => true, 'content' => [
        'application/json' => ['schema' => $body]]];
    return $value;
};
$designerDraft = [
    'type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision', 'graph', 'layout'], 'properties' => [
        'expected_revision' => $integer + ['nullable' => true],
        'graph' => $ref('WorkflowDesignerGraph'),
        // Unlike the read projection, a save requires a concrete viewport.
        'layout' => $ref('WorkflowDesignerWritableLayout')]];
$designerValidate = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['graph'], 'properties' => ['graph' => $ref('WorkflowDesignerGraph')]];
$designerPreview = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['graph', 'basis'], 'properties' => [
        'graph' => $ref('WorkflowDesignerGraph'), 'basis' => $ref('WorkflowDesignerPreviewBasis')]];
$designerRevision = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision'], 'properties' => ['expected_revision' => $integer]];
$designerActivation = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision', 'version'], 'properties' => [
        'expected_revision' => $integer, 'version' => $integer]];
// WorkflowStartButton::start passes the already-read active definition revision.
// The business object and tenant checks remain exclusively inside WorkflowCommands::start.
$workflowStart = $operation('startWorkflowInstance', $ref('WorkflowRuntimeInstance'), []);
$workflowStart['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
$workflowStart['parameters'][] = ['in' => 'header', 'name' => 'Idempotency-Key',
    'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[!-~]{16,128}$']];
$workflowStart['requestBody'] = ['required' => true, 'content' => [
    'application/json' => ['schema' => $ref('WorkflowStartInput')]]];
$workflowInbox = $operation(
        'getWorkflowInbox', $ref('WorkflowInboxPage'),
        [['in' => 'query', 'name' => 'filter',
            'schema' => ['type' => 'string', 'enum' => ['assigned', 'claimable', 'completed',
                'initiated', 'participant', 'watcher'], 'default' => 'assigned']],
        ['in' => 'query', 'name' => 'page', 'schema' => $integer + ['maximum' => 1000000]],
        ['in' => 'query', 'name' => 'page_size', 'schema' => $integer + ['maximum' => 100]]],
        ['page' => $integer, 'page_size' => $integer, 'total' => $nonnegative]);
$workflowInbox['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
// Every task action delegates to WorkflowCommands::task, which checks three
// independent CAS revisions, current actor eligibility and native idempotency.
$taskBase = [
    'node_execution_key' => $text + ['minLength' => 1, 'maxLength' => 64],
    'expected_revision' => $integer, 'expected_node_revision' => $integer,
    'expected_task_revision' => $integer,
];
$taskFiles = ['type' => 'array', 'maxItems' => 100, 'items' => [
    'type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$']];
$taskComment = $text + ['nullable' => true, 'maxLength' => 2000];
$taskRequest = static function (array $extra = [], array $requiredExtra = []) use ($taskBase): array {
    return ['type' => 'object', 'additionalProperties' => false,
        'required' => [...array_keys($taskBase), ...$requiredExtra],
        'properties' => $taskBase + $extra];
};
$taskPlain = $taskRequest();
$taskDecision = ['oneOf' => [
    $taskRequest(['decision' => ['type' => 'string', 'enum' => ['approve']],
        'comment' => $taskComment, 'attachment_file_keys' => $taskFiles],
        ['comment', 'attachment_file_keys', 'decision']),
    $taskRequest(['decision' => ['type' => 'string', 'enum' => ['reject']],
        'comment' => $text + ['minLength' => 1, 'maxLength' => 2000, 'pattern' => '\\S'],
        'attachment_file_keys' => $taskFiles],
        ['comment', 'attachment_file_keys', 'decision']),
]];
$taskCompletion = $taskRequest(['comment' => $taskComment,
    'attachment_file_keys' => $taskFiles, 'input' => ['type' => 'object']],
    ['comment', 'attachment_file_keys', 'input']);
$taskReturn = $taskRequest(['comment' => $text + [
    'minLength' => 1, 'maxLength' => 2000, 'pattern' => '\\S'],
    'attachment_file_keys' => $taskFiles], ['comment', 'attachment_file_keys']);
$taskResubmit = $taskRequest(['comment' => $taskComment,
    'attachment_file_keys' => $taskFiles,
    'subject_revision_key' => $text + ['minLength' => 1, 'maxLength' => 160]],
    ['comment', 'attachment_file_keys', 'subject_revision_key']);
$workflowTaskWrite = static function (string $name, array $body) use ($operation, $ref, $text): array {
    $value = $operation($name, $ref('WorkflowRuntimeInstance'), [
        ['in' => 'path', 'name' => 'workItemKey', 'required' => true, 'schema' => $text]]);
    $value['parameters'][] = ['in' => 'header', 'name' => 'Idempotency-Key',
        'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[!-~]{16,128}$']];
    $value['requestBody'] = ['required' => true,
        'content' => ['application/json' => ['schema' => $body]]];
    $value['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
    return $value;
};
// WorkflowRuntimePage diagnostics/recovery. The Controller also requires native
// Task read/manage contexts; a Workflow route permission never replaces those checks.
$workflowInstanceScope = [['in' => 'path', 'name' => 'instanceKey',
    'required' => true, 'schema' => $text]];
$workflowReason = $text + ['minLength' => 1, 'maxLength' => 2000,
    'pattern' => '\\S'];
$workflowInstanceChange = [
    'type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision', 'comment'], 'properties' => [
        'expected_revision' => $integer, 'comment' => $workflowReason]];
$workflowReassign = [
    'type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision', 'comment', 'work_item_key',
        'assignee_member_id', 'expected_task_revision'], 'properties' => [
        'expected_revision' => $integer, 'comment' => $workflowReason,
        'work_item_key' => $text + ['minLength' => 1],
        'assignee_member_id' => $text + ['pattern' => '^[1-9][0-9]*$'],
        'expected_task_revision' => $integer]];
$workflowTerminate = ['oneOf' => [
    $workflowInstanceChange,
    ['type' => 'object', 'additionalProperties' => false,
        'required' => ['expected_revision', 'comment', 'expected_job_revision'],
        'properties' => [
            'expected_revision' => $integer, 'comment' => $workflowReason,
            'expected_job_revision' => $integer]],
]];
$workflowReconcile = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision', 'node_execution_key', 'expected_node_revision',
        'expected_job_revision', 'comment'], 'properties' => [
        'expected_revision' => $integer,
        'node_execution_key' => $text + ['minLength' => 1, 'maxLength' => 64],
        'expected_node_revision' => $integer, 'expected_job_revision' => $integer,
        'comment' => $workflowReason]];
$workflowRecover = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['expected_revision', 'node_execution_key', 'expected_node_revision',
        'expected_job_revision', 'strategy', 'comment'], 'properties' => [
        'expected_revision' => $integer,
        'node_execution_key' => $text + ['minLength' => 1, 'maxLength' => 64],
        'expected_node_revision' => $integer, 'expected_job_revision' => $integer,
        'strategy' => ['type' => 'string', 'enum' => ['retry_original', 'reauthorize']],
        'comment' => $workflowReason]];
$workflowMaintenance = static function (string $name, array $body, string $description)
    use ($operation, $ref, $workflowInstanceScope): array {
    $value = $operation($name, $ref('WorkflowRuntimeInstance'), $workflowInstanceScope);
    $value['description'] = $description;
    $value['parameters'][] = ['in' => 'header', 'name' => 'Idempotency-Key',
        'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[!-~]{16,128}$']];
    $value['requestBody'] = ['required' => true,
        'content' => ['application/json' => ['schema' => $body]]];
    $value['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
    return $value;
};
$workflowDiagnostics = $operation('getWorkflowTaskDiagnostics',
    $ref('WorkflowTaskDiagnostics'), $workflowInstanceScope);
$workflowDiagnostics['description'] = 'Current Workflow diagnostics.read plus current Task jobs.read authorization; optional Task jobs.manage only determines available recovery actions.';
$workflowDiagnostics['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
// WorkflowDefinitionController::index returns tenant-scoped, DB-paginated summaries.
// This existing public Admin API currently has no built-in Web page caller, but
// absence of one is not an intentional HTTP contract exclusion.
$workflowDefinitionList = $operation('listWorkflowDefinitions',
    $ref('WorkflowDefinitionListPage'), [
        ['in' => 'query', 'name' => 'page',
            'schema' => $integer + ['maximum' => 1000000, 'default' => 1]],
        ['in' => 'query', 'name' => 'page_size',
            'schema' => $integer + ['maximum' => 100, 'default' => 20]]],
    ['page' => $integer, 'page_size' => $integer + ['maximum' => 100],
        'total' => $nonnegative]);
$workflowDefinitionList['description'] = 'Existing tenant-authorized definition list; only the bounded WorkflowQueries::definitions storage projection, never a read of every definition followed by per-row detail calls.';
$workflowDefinitionList['responses']['422'] = ['$ref' => '#/components/responses/WorkflowRuntimeError'];
$recover = $operation('recoverWorkflowNotification', ['type' => 'object', 'required' => ['sequence_no', 'accepted'],
    'properties' => ['sequence_no' => $integer, 'accepted' => ['type' => 'boolean']]], $instance);
$recover['parameters'][] = ['in' => 'header', 'name' => 'Idempotency-Key', 'required' => true,
    'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9:_-]{1,128}$']];
$recover['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object',
    'additionalProperties' => false, 'required' => ['sequence_no', 'outbox_key', 'expected_job_revision', 'strategy'],
    'properties' => ['sequence_no' => $integer, 'outbox_key' => $text + ['nullable' => true],
        'expected_job_revision' => $integer + ['nullable' => true], 'strategy' => ['type' => 'string', 'enum' => ['retry_original', 'reauthorize']]]]]]];
return ['paths' => [
    '/adminapi/api/v1/workflow/definitions' => ['get' => $workflowDefinitionList],
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}' => ['get' =>
        $designerRead('getWorkflowDesignerDefinition', $ref('WorkflowDesignerDefinition'))],
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/scenario' => ['get' =>
        $designerRead('getWorkflowDesignerScenario', $ref('WorkflowDesignerScenario'))],
    // WorkflowDesignerPage: save -> validate/preview -> publish -> activate/retire.
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/draft' => ['put' =>
        $designerWrite('saveWorkflowDesignerDraft', $ref('WorkflowDesignerSaveReceipt'),
            $designerDraft, true, $designerDefinitionParams)],
    '/adminapi/api/v1/workflow/definitions/validate' => ['post' =>
        $designerWrite('validateWorkflowDesignerGraph', $ref('WorkflowDesignerValidationReceipt'),
            $designerValidate, false)],
    '/adminapi/api/v1/workflow/definitions/preview' => ['post' =>
        $designerWrite('previewWorkflowDesignerGraph', $ref('WorkflowDesignerPreviewReceipt'),
            $designerPreview, false)],
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/publish' => ['post' =>
        $designerWrite('publishWorkflowDesignerDefinition', $ref('WorkflowDesignerPublishReceipt'),
            $designerRevision, true, $designerDefinitionParams)],
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/activate' => ['post' =>
        $designerWrite('activateWorkflowDesignerDefinition', $ref('WorkflowDesignerStatusReceipt'),
            $designerActivation, true, $designerDefinitionParams)],
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/retire' => ['post' =>
        $designerWrite('retireWorkflowDesignerDefinition', $ref('WorkflowDesignerStatusReceipt'),
            $designerRevision, true, $designerDefinitionParams)],
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/summary' => ['get' => $operation('getWorkflowStartSummary', $ref('WorkflowStartSummary'), [
        ['in' => 'path', 'name' => 'moduleKey', 'required' => true, 'schema' => $text],
        ['in' => 'path', 'name' => 'workflowKey', 'required' => true, 'schema' => $text],
        ['in' => 'query', 'name' => 'subject_type', 'required' => true, 'schema' => $text],
        ['in' => 'query', 'name' => 'subject_key', 'required' => true, 'schema' => $text],
    ])],
    '/adminapi/api/v1/workflow/instances' => ['post' => $workflowStart],
    '/adminapi/api/v1/workflow/inbox' => ['get' => $workflowInbox],
    '/adminapi/api/v1/workflow/instances/{instanceKey}' => ['get' =>
        $operation('getWorkflowRuntimeInstance', $ref('WorkflowRuntimeInstance'), $instance)],
    '/adminapi/api/v1/workflow/tasks/{workItemKey}/claim' => ['post' =>
        $workflowTaskWrite('claimWorkflowTask', $taskPlain)],
    '/adminapi/api/v1/workflow/tasks/{workItemKey}/release' => ['post' =>
        $workflowTaskWrite('releaseWorkflowTask', $taskPlain)],
    '/adminapi/api/v1/workflow/tasks/{workItemKey}/decisions' => ['post' =>
        $workflowTaskWrite('decideWorkflowTask', $taskDecision)],
    '/adminapi/api/v1/workflow/tasks/{workItemKey}/completions' => ['post' =>
        $workflowTaskWrite('completeWorkflowTask', $taskCompletion)],
    '/adminapi/api/v1/workflow/tasks/{workItemKey}/return' => ['post' =>
        $workflowTaskWrite('returnWorkflowTask', $taskReturn)],
    '/adminapi/api/v1/workflow/tasks/{workItemKey}/resubmit' => ['post' =>
        $workflowTaskWrite('resubmitWorkflowTask', $taskResubmit)],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/diagnostics' => [
        'get' => $workflowDiagnostics],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/reconcile' => ['post' =>
        $workflowMaintenance('reconcileWorkflowServiceTask', $workflowReconcile,
            'Requires current Workflow diagnostics.read, Task jobs.manage, terminal original Task and exact instance/node/job revisions; cannot infer Task state from UI.')],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/recover' => ['post' =>
        $workflowMaintenance('recoverWorkflowServiceTask', $workflowRecover,
            'Requires current Workflow diagnostics.retry, Task jobs.manage and authorized current business effect; recovery of dead original job is bound to the same frozen effect identity.')],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/withdraw' => ['post' =>
        $workflowMaintenance('withdrawWorkflowInstance', $workflowInstanceChange,
            'Only initiating member before substantive action, with current Workflow instance.withdraw authorization.')],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/terminate' => ['post' =>
        $workflowMaintenance('terminateWorkflowInstance', $workflowTerminate,
            'Requires Workflow instance.terminate. Outstanding Task effects require current Task jobs.manage, cancelled terminal evidence and matching original Task revision.')],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/retry' => ['post' =>
        $workflowMaintenance('retryBlockedWorkflowInstance', $workflowInstanceChange,
            'Requires Workflow diagnostics.retry. Ordinary blocked node retry may not replace an existing Task job or resolve frozen assignee pools implicitly.')],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/reassign' => ['post' =>
        $workflowMaintenance('reassignWorkflowPendingItem', $workflowReassign,
            'Requires Workflow diagnostics.reassign and current eligible assignee in the same tenant; CAS on both instance and work-item revision.')],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/materials' => ['get' => $operation('getWorkflowFrozenMaterials', $ref('WorkflowFrozenMaterials'), $instance)],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/events' => ['get' => $operation('getWorkflowEvents', [
        'type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => $ref('WorkflowEvent')]],
    ], [...$instance, ['in' => 'query', 'name' => 'after_sequence', 'schema' => ['type' => 'integer', 'minimum' => 0]],
        ['in' => 'query', 'name' => 'page_size', 'schema' => $integer + ['maximum' => 100]]])],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/notifications' => ['get' => $operation('getWorkflowNotificationDiagnostics', [
        'type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => $ref('WorkflowNotificationDiagnostic')]],
    ], [...$instance, ['in' => 'query', 'name' => 'page', 'schema' => $integer],
        ['in' => 'query', 'name' => 'page_size', 'schema' => $integer + ['maximum' => 100]]],
        ['page' => $integer, 'page_size' => $integer, 'total' => ['type' => 'integer', 'minimum' => 0]])],
    '/adminapi/api/v1/workflow/instances/{instanceKey}/notifications/recover' => ['post' => $recover],
], 'components' => ['schemas' => [
    // WorkflowQueries::definitionSummary + WorkflowJsonProjection::definition.
    'WorkflowDesignerGraphNode' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['key', 'label', 'type', 'config'], 'properties' => [
            'key' => $text, 'label' => $text,
            'type' => ['type' => 'string', 'enum' => ['start', 'approval', 'human_task', 'exclusive_gateway', 'service_task', 'end']],
            // Node-specific config and nested input schema are authoritative to WorkflowGraph,
            // not a forged second schema enumerating business action fields.
            'config' => ['type' => 'object']]],
    'WorkflowDesignerGraphTransition' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['key', 'from', 'to', 'action', 'operation', 'permission_keys', 'return_edge', 'max_traversals'],
        'properties' => [
            'key' => $text, 'from' => $text, 'to' => $text, 'action' => $text,
            'operation' => $text + ['nullable' => true],
            'permission_keys' => ['type' => 'array', 'items' => $text],
            'return_edge' => ['type' => 'boolean'],
            'max_traversals' => $integer + ['nullable' => true]]],
    'WorkflowDesignerGraph' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['contract_version', 'scenario_contract_version', 'subject_resource_key',
            'subject_read_operation', 'subject_start_operation', 'start_permission_keys',
            'required_capabilities', 'nodes', 'transitions'], 'properties' => [
            'contract_version' => ['type' => 'integer', 'enum' => [2]],
            'scenario_contract_version' => $integer,
            'subject_resource_key' => $text, 'subject_read_operation' => $text,
            'subject_start_operation' => $text,
            'start_permission_keys' => ['type' => 'array', 'items' => $text],
            'required_capabilities' => ['type' => 'array', 'items' => $text],
            'nodes' => ['type' => 'array', 'maxItems' => 100, 'items' => $ref('WorkflowDesignerGraphNode')],
            'transitions' => ['type' => 'array', 'maxItems' => 200, 'items' => $ref('WorkflowDesignerGraphTransition')]]],
    'WorkflowDesignerLayout' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['nodes', 'viewport'], 'properties' => [
            'nodes' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['key', 'x', 'y'], 'properties' => [
                    'key' => $text, 'x' => ['type' => 'number'], 'y' => ['type' => 'number']]]],
            'viewport' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => false,
                'required' => ['x', 'y', 'zoom'], 'properties' => [
                    'x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'zoom' => ['type' => 'number']]]]],
    'WorkflowDesignerDefinitionVersion' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['version', 'graph_sha256', 'published_at'], 'properties' => [
            'version' => $integer,
            'graph_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'published_at' => $text]],
    'WorkflowDesignerDefinition' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['definition_id', 'module_key', 'workflow_key', 'status', 'draft_revision',
            'latest_version', 'active_version', 'graph', 'layout', 'versions'],
        'properties' => [
            'definition_id' => $text, 'module_key' => $text, 'workflow_key' => $text,
            'status' => $text, 'draft_revision' => $integer, 'latest_version' => $nonnegative,
            'active_version' => $integer + ['nullable' => true],
            'graph' => $ref('WorkflowDesignerGraph'), 'layout' => $ref('WorkflowDesignerLayout'),
            'versions' => ['type' => 'array', 'items' => $ref('WorkflowDesignerDefinitionVersion')]]],
    // WorkflowDefinitionService::scenarioMetadata + WorkflowJsonProjection::scenario.
    'WorkflowDesignerScenarioField' => ['type' => 'object',
        'required' => ['type', 'nullable'], 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['boolean', 'integer', 'string', 'enum', 'datetime', 'decimal']],
            'nullable' => ['type' => 'boolean'], 'values' => ['type' => 'array', 'items' => $text]]],
    'WorkflowDesignerScenarioAction' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['action_key', 'contract_version', 'input_schema'], 'properties' => [
            'action_key' => $text, 'contract_version' => $integer,
            'input_schema' => ['type' => 'object']]],
    'WorkflowDesignerScenario' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['scenario_contract_version', 'fields', 'result_codes', 'capabilities', 'actions'],
        'properties' => [
            'scenario_contract_version' => $integer,
            'fields' => ['type' => 'object', 'additionalProperties' => $ref('WorkflowDesignerScenarioField')],
            'result_codes' => ['type' => 'array', 'items' => $text],
            'capabilities' => ['type' => 'array', 'items' => $text],
            'actions' => ['type' => 'array', 'items' => $ref('WorkflowDesignerScenarioAction')]]],
    'WorkflowDesignerIssue' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['code', 'message'], 'properties' => [
            'code' => $text, 'message' => $text, 'path' => $text,
            'node_key' => $text, 'field' => $text]],
    'WorkflowDesignerValidationReceipt' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['issues'], 'properties' => [
            'issues' => ['type' => 'array', 'items' => $ref('WorkflowDesignerIssue')]]],
    'WorkflowDesignerSaveReceipt' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['definition_id', 'draft_revision', 'issues'], 'properties' => [
            'definition_id' => $text, 'draft_revision' => $integer,
            'issues' => ['type' => 'array', 'items' => $ref('WorkflowDesignerIssue')]]],
    'WorkflowDesignerPreviewBasis' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['initiator_member_id', 'business_department_key', 'previous_actor_member_id',
            'subject_type', 'subject_key'], 'properties' => [
            'initiator_member_id' => $text + ['pattern' => '^[1-9][0-9]*$'],
            'business_department_key' => $text + ['nullable' => true, 'maxLength' => 160],
            'previous_actor_member_id' => $text + ['nullable' => true,
                'pattern' => '^[1-9][0-9]*$'],
            'subject_type' => $text + ['minLength' => 1, 'maxLength' => 160],
            'subject_key' => $text + ['minLength' => 1, 'maxLength' => 160]]],
    'WorkflowDesignerPreviewMember' => ['type' => 'object',
        'required' => ['member_id', 'source_kind'], 'properties' => [
            'member_id' => $text, 'source_kind' => $text]],
    'WorkflowDesignerPreviewNode' => ['type' => 'object',
        'required' => ['node_key', 'members'], 'properties' => [
            'node_key' => $text, 'members' => ['type' => 'array',
                'items' => $ref('WorkflowDesignerPreviewMember')]]],
    'WorkflowDesignerPreviewReceipt' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['simulated', 'nodes', 'issues'], 'properties' => [
            'simulated' => ['type' => 'boolean', 'enum' => [true]],
            'nodes' => ['type' => 'array', 'items' => $ref('WorkflowDesignerPreviewNode')],
            'issues' => ['type' => 'array', 'items' => $ref('WorkflowDesignerIssue')]]],
    'WorkflowDesignerPublishReceipt' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['definition_id', 'version', 'active_version', 'draft_revision'],
        'properties' => [
            'definition_id' => $text, 'version' => $integer,
            'active_version' => $integer, 'draft_revision' => $integer]],
    'WorkflowDesignerStatusReceipt' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['definition_id', 'draft_revision', 'active_version', 'status'],
        'properties' => [
            'definition_id' => $text, 'draft_revision' => $integer,
            'active_version' => $integer + ['nullable' => true],
            'status' => ['type' => 'string', 'enum' => ['active', 'retired']]]],
    'WorkflowDesignerWritableLayout' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['nodes', 'viewport'], 'properties' => [
            'nodes' => ['type' => 'array', 'items' => ['type' => 'object',
                'additionalProperties' => false, 'required' => ['key', 'x', 'y'],
                'properties' => ['key' => $text, 'x' => ['type' => 'number'], 'y' => ['type' => 'number']]]],
            'viewport' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['x', 'y', 'zoom'], 'properties' => [
                    'x' => ['type' => 'number'], 'y' => ['type' => 'number'],
                    'zoom' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 100]]]]],
    // Start/Inbox/Instance are the three actual WorkflowStartButton + WorkflowRuntimePage
    // consumers. Inbox never grants action permission: detail must be read separately.
    'WorkflowStartInput' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['module_key', 'workflow_key', 'subject_type', 'subject_key',
            'subject_revision_key', 'expected_revision', 'business_department_key',
            'attachment_file_keys'],
        'properties' => [
            'module_key' => $text + ['minLength' => 1, 'maxLength' => 160],
            'workflow_key' => $text + ['minLength' => 1, 'maxLength' => 160],
            'subject_type' => $text + ['minLength' => 1, 'maxLength' => 160],
            'subject_key' => $text + ['minLength' => 1, 'maxLength' => 160],
            'subject_revision_key' => $text + ['minLength' => 1, 'maxLength' => 160],
            'expected_revision' => $integer,
            'business_department_key' => $text + ['nullable' => true,
                'minLength' => 1, 'maxLength' => 160],
            'attachment_file_keys' => ['type' => 'array', 'maxItems' => 100,
                'items' => ['type' => 'string', 'pattern' => '^file_[a-f0-9]{32}$']]]],
    'WorkflowInboxWorkItem' => ['type' => 'object',
        'required' => ['work_item_key', 'instance_key', 'node_execution_key', 'label',
            'type', 'revision', 'instance_revision', 'node_revision', 'status',
            'assignment_state', 'owner_member_id', 'assignee_member_id',
            'relations', 'decision'],
        'properties' => [
            'work_item_key' => $text, 'instance_key' => $text,
            'node_execution_key' => $text, 'label' => $text, 'type' => $text,
            'revision' => $integer, 'instance_revision' => $integer,
            'node_revision' => $integer,
            'status' => ['type' => 'string', 'enum' => ['pending', 'completed', 'cancelled']],
            'assignment_state' => ['type' => 'string', 'enum' => ['assigned', 'unassigned']],
            'owner_member_id' => $text + ['nullable' => true],
            'assignee_member_id' => $text + ['nullable' => true],
            'relations' => ['type' => 'array', 'items' => $text],
            'decision' => $text + ['nullable' => true]]],
    'WorkflowRuntimeWorkItem' => ['allOf' => [$ref('WorkflowInboxWorkItem'),
        ['type' => 'object', 'required' => ['available_actions'],
            'properties' => ['available_actions' => ['type' => 'array', 'items' => $text]]]]],
    'WorkflowRuntimeActiveNode' => ['type' => 'object',
        'required' => ['node_execution_key', 'label', 'status', 'revision',
            'task_termination_evidence_required'],
        'properties' => [
            'node_execution_key' => $text, 'label' => $text,
            'status' => $text, 'revision' => $integer,
            'task_termination_evidence_required' => ['type' => 'boolean']]],
    'WorkflowRuntimeReassignItem' => ['type' => 'object',
        'required' => ['work_item_key', 'label', 'revision'], 'properties' => [
            'work_item_key' => $text, 'label' => $text, 'revision' => $integer]],
    'WorkflowRuntimeBlocker' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['code', 'message', 'node_execution_key'], 'properties' => [
            'code' => $text, 'message' => $text,
            'node_execution_key' => $text + ['nullable' => true]]],
    'WorkflowRuntimeInstance' => ['type' => 'object',
        'required' => ['instance_key', 'subject_type', 'subject_key',
            'subject_revision_key', 'status', 'result_code', 'revision',
            'active_nodes', 'own_work_items', 'reassign_work_items',
            'available_actions', 'event_cursor', 'blocker'], 'properties' => [
            'instance_key' => $text, 'subject_type' => $text, 'subject_key' => $text,
            'subject_revision_key' => $text, 'status' => $text,
            'result_code' => $text + ['nullable' => true], 'revision' => $integer,
            'active_nodes' => ['type' => 'array', 'items' => $ref('WorkflowRuntimeActiveNode')],
            'own_work_items' => ['type' => 'array', 'items' => $ref('WorkflowRuntimeWorkItem')],
            'reassign_work_items' => ['type' => 'array',
                'items' => $ref('WorkflowRuntimeReassignItem')],
            'available_actions' => ['type' => 'array', 'items' => $text],
            'event_cursor' => $nonnegative,
            'blocker' => ['anyOf' => [$ref('WorkflowRuntimeBlocker'), ['type' => 'null']]]]],
    'WorkflowInboxPage' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['items'], 'properties' => [
            'items' => ['type' => 'array', 'items' => ['oneOf' => [
                $ref('WorkflowInboxWorkItem'), $ref('WorkflowRuntimeInstance')]]]]],
    'WorkflowTaskDiagnosticJob' => ['type' => 'object',
        'required' => ['job_key', 'status', 'revision', 'last_error_code'],
        'properties' => [
            'job_key' => $text, 'status' => $text, 'revision' => $integer,
            'last_error_code' => $text + ['nullable' => true]]],
    'WorkflowTaskDiagnosticNode' => ['type' => 'object',
        'required' => ['node_execution_key', 'node_revision', 'status', 'job',
            'available_actions'], 'properties' => [
            'node_execution_key' => $text, 'node_revision' => $integer,
            'status' => $text, 'job' => $ref('WorkflowTaskDiagnosticJob'),
            'available_actions' => ['type' => 'array', 'items' => ['type' => 'string',
                'enum' => ['reconcile', 'retry_original', 'reauthorize']]]]],
    'WorkflowTaskDiagnostics' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['instance_key', 'revision', 'items'], 'properties' => [
            'instance_key' => $text, 'revision' => $integer,
            'items' => ['type' => 'array', 'maxItems' => 100,
                'items' => $ref('WorkflowTaskDiagnosticNode')]]],
    'WorkflowDefinitionListItem' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['definition_id', 'module_key', 'workflow_key', 'status',
            'draft_revision', 'latest_version', 'active_version'],
        'properties' => [
            'definition_id' => $text, 'module_key' => $text, 'workflow_key' => $text,
            'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'retired']],
            'draft_revision' => $integer, 'latest_version' => $nonnegative,
            'active_version' => $integer + ['nullable' => true]]],
    'WorkflowDefinitionListPage' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['items'], 'properties' => [
            'items' => ['type' => 'array', 'items' => $ref('WorkflowDefinitionListItem')]]],
    'WorkflowAttachment' => ['type' => 'object', 'additionalProperties' => false,
        'description' => 'Persisted event-time metadata only. File preview, original download and management require separate current authorization; a changed source must not be delivered as the historical bytes.',
        'required' => ['file_key', 'name', 'media_type', 'size_bytes', 'sha256'], 'properties' => [
            'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'], 'name' => $text + ['minLength' => 1, 'maxLength' => 255],
            'media_type' => $text, 'size_bytes' => ['type' => 'integer', 'minimum' => 0], 'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$']]],
    'WorkflowEvent' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['sequence_no', 'event_key', 'node_execution_key', 'actor_member_id', 'comment', 'metadata', 'attachments', 'occurred_at'],
        'properties' => ['sequence_no' => $integer, 'event_key' => $text, 'node_execution_key' => $text + ['nullable' => true],
            'actor_member_id' => $text + ['nullable' => true], 'comment' => $text + ['nullable' => true], 'metadata' => ['type' => 'object'],
            'attachments' => ['type' => 'array', 'maxItems' => 100, 'items' => $ref('WorkflowAttachment')], 'occurred_at' => $text]],
    'WorkflowStartSummary' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['definition_id', 'module_key', 'workflow_key', 'status', 'draft_revision', 'active_version'],
        'properties' => ['definition_id' => $text, 'module_key' => $text, 'workflow_key' => $text,
            'status' => ['type' => 'string', 'enum' => ['active']], 'draft_revision' => $integer, 'active_version' => $integer]],
    'WorkflowFrozenMaterials' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['revision_key', 'sha256', 'fields', 'reference'], 'properties' => ['revision_key' => $text,
            'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'], 'fields' => ['type' => 'object'], 'reference' => ['type' => 'object']]],
    'WorkflowNotificationDiagnostic' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['sequence_no', 'status', 'last_error', 'outboxes', 'available_actions'], 'properties' => [
            'sequence_no' => $integer, 'status' => ['type' => 'string', 'enum' => ['pending_materialization', 'materialized']],
            'last_error' => $text + ['nullable' => true], 'available_actions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['reauthorize']]],
            'outboxes' => ['type' => 'array', 'items' => $ref('WorkflowNotificationOutboxDiagnostic')]]],
    'WorkflowNotificationOutboxDiagnostic' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['outbox_key', 'delivery_status', 'task', 'available_actions'], 'properties' => [
            'outbox_key' => $text, 'delivery_status' => $text,
            'available_actions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['retry_original', 'reauthorize']]],
            'task' => ['type' => 'object', 'nullable' => true, 'description' => 'The native redacted Task record; signed authorization and session references are excluded.']]],
    'WorkflowRuntimeErrorEnvelope' => ['type' => 'object', 'required' => ['code', 'msg', 'data'], 'properties' => [
        'code' => ['type' => 'integer'], 'msg' => $text, 'data' => ['type' => 'object', 'required' => ['error_code'], 'properties' => ['error_code' => $text]]]],
], 'responses' => ['WorkflowRuntimeError' => ['description' => 'Native error envelope; HTTP status and X-Request-ID are retained.',
    'content' => ['application/json' => ['schema' => $ref('WorkflowRuntimeErrorEnvelope')]]]]]];
