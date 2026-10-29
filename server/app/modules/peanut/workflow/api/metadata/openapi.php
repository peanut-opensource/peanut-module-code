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
$recover = $operation('recoverWorkflowNotification', ['type' => 'object', 'required' => ['sequence_no', 'accepted'],
    'properties' => ['sequence_no' => $integer, 'accepted' => ['type' => 'boolean']]], $instance);
$recover['parameters'][] = ['in' => 'header', 'name' => 'Idempotency-Key', 'required' => true,
    'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9:_-]{1,128}$']];
$recover['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object',
    'additionalProperties' => false, 'required' => ['sequence_no', 'outbox_key', 'expected_job_revision', 'strategy'],
    'properties' => ['sequence_no' => $integer, 'outbox_key' => $text + ['nullable' => true],
        'expected_job_revision' => $integer + ['nullable' => true], 'strategy' => ['type' => 'string', 'enum' => ['retry_original', 'reauthorize']]]]]]];
return ['paths' => [
    '/adminapi/api/v1/workflow/definitions/{moduleKey}/{workflowKey}/summary' => ['get' => $operation('getWorkflowStartSummary', $ref('WorkflowStartSummary'), [
        ['in' => 'path', 'name' => 'moduleKey', 'required' => true, 'schema' => $text],
        ['in' => 'path', 'name' => 'workflowKey', 'required' => true, 'schema' => $text],
        ['in' => 'query', 'name' => 'subject_type', 'required' => true, 'schema' => $text],
        ['in' => 'query', 'name' => 'subject_key', 'required' => true, 'schema' => $text],
    ])],
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
