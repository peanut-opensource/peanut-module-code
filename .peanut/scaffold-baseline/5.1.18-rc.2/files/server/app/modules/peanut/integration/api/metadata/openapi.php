<?php

declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$response = static fn(string $schema, string $description = 'Successful integration response.'): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$pathKey = static fn(string $name, string $pattern): array => [
    'name' => $name, 'in' => 'path', 'required' => true,
    'schema' => ['type' => 'string', 'pattern' => $pattern],
];
$revision = [
    'required' => true,
    'content' => ['application/json' => ['schema' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['revision'],
        'properties' => ['revision' => ['type' => 'integer', 'minimum' => 1]],
    ]]],
];
$page = [
    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
];

return [
    'paths' => [
        '/adminapi/api/v1/integration-security/machine-identities' => [
            'get' => [
                'tags' => ['Integration'], 'operationId' => 'listIntegrationMachines',
                'responses' => ['200' => $response('IntegrationMachineListResponse'), '401' => $error, '403' => $error, '503' => $error],
            ],
            'post' => [
                'tags' => ['Integration'], 'operationId' => 'createIntegrationMachine',
                'requestBody' => [
                    'required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'scopes'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                            'scopes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 32, 'uniqueItems' => true, 'items' => ['type' => 'string', 'maxLength' => 96]],
                            'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                        ],
                    ]]],
                ],
                'responses' => ['201' => $response('IntegrationProvisionedMachineResponse'), '401' => $error, '403' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/machine-identities/{identityKey}/rotate' => [
            'post' => [
                'tags' => ['Integration'], 'operationId' => 'rotateIntegrationMachine',
                'parameters' => [$pathKey('identityKey', '^machine_[0-9a-f]{32}$')], 'requestBody' => $revision,
                'responses' => ['200' => $response('IntegrationProvisionedMachineResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/machine-identities/{identityKey}' => [
            'delete' => [
                'tags' => ['Integration'], 'operationId' => 'revokeIntegrationMachine',
                'parameters' => [$pathKey('identityKey', '^machine_[0-9a-f]{32}$')], 'requestBody' => $revision,
                'responses' => ['200' => $response('IntegrationMachineResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/webhooks' => [
            'get' => [
                'tags' => ['Integration'], 'operationId' => 'listIntegrationWebhooks',
                'responses' => ['200' => $response('IntegrationWebhookListResponse'), '401' => $error, '403' => $error],
            ],
            'post' => [
                'tags' => ['Integration'], 'operationId' => 'createIntegrationWebhook',
                'requestBody' => [
                    'required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'url', 'events'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                            'url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                            'events' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 32, 'uniqueItems' => true, 'items' => ['type' => 'string', 'maxLength' => 96]],
                        ],
                    ]]],
                ],
                'responses' => ['201' => $response('IntegrationProvisionedWebhookResponse'), '401' => $error, '403' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/webhooks/{endpointKey}/rotate-secret' => [
            'post' => [
                'tags' => ['Integration'], 'operationId' => 'rotateIntegrationWebhookSecret',
                'parameters' => [$pathKey('endpointKey', '^webhook_[0-9a-f]{32}$')], 'requestBody' => $revision,
                'responses' => ['200' => $response('IntegrationProvisionedWebhookResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/webhooks/{endpointKey}' => [
            'delete' => [
                'tags' => ['Integration'], 'operationId' => 'disableIntegrationWebhook',
                'parameters' => [$pathKey('endpointKey', '^webhook_[0-9a-f]{32}$')], 'requestBody' => $revision,
                'responses' => ['200' => $response('IntegrationWebhookResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/deliveries' => [
            'get' => [
                'tags' => ['Integration'], 'operationId' => 'listIntegrationDeliveries', 'parameters' => $page,
                'responses' => ['200' => $response('IntegrationDeliveryListResponse'), '401' => $error, '403' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/deliveries/{deliveryKey}/attempts' => [
            'get' => [
                'tags' => ['Integration'], 'operationId' => 'listIntegrationDeliveryAttempts',
                'parameters' => [$pathKey('deliveryKey', '^delivery_[0-9a-f]{32}$'), ...$page],
                'responses' => ['200' => $response('IntegrationAttemptListResponse'), '401' => $error, '403' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/sessions' => [
            'get' => [
                'tags' => ['Integration'], 'operationId' => 'listIntegrationSessions',
                'responses' => ['200' => $response('IntegrationSessionListResponse'), '401' => $error, '403' => $error],
            ],
        ],
        '/adminapi/api/v1/integration-security/sessions/{sessionKey}/revoke' => [
            'post' => [
                'tags' => ['Integration'], 'operationId' => 'revokeIntegrationSession',
                'parameters' => [$pathKey('sessionKey', '^[0-9A-HJKMNP-TV-Z]{26}$')],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => false]]]],
                'responses' => ['200' => $response('IntegrationSessionResponse'), '401' => $error, '403' => $error, '404' => $error, '422' => $error],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'IntegrationRequestMeta' => [
            'type' => 'object', 'additionalProperties' => true, 'required' => ['request_id'],
            'properties' => ['request_id' => ['type' => 'string', 'minLength' => 1]],
        ],
        'IntegrationMachine' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['identity_key', 'name', 'scopes', 'status', 'token_prefix', 'token_last_four', 'expires_at', 'last_used_at', 'revision', 'created_at'],
            'properties' => [
                'identity_key' => ['type' => 'string', 'pattern' => '^machine_[0-9a-f]{32}$'],
                'name' => ['type' => 'string'], 'scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'status' => ['type' => 'string', 'enum' => ['active', 'rotated', 'revoked']],
                'token_prefix' => ['type' => 'string'], 'token_last_four' => ['type' => 'string'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'last_used_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'revision' => ['type' => 'integer', 'minimum' => 1], 'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
        'IntegrationWebhook' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['endpoint_key', 'name', 'url', 'events', 'status', 'revision', 'created_at'],
            'properties' => [
                'endpoint_key' => ['type' => 'string', 'pattern' => '^webhook_[0-9a-f]{32}$'],
                'name' => ['type' => 'string'], 'url' => ['type' => 'string', 'format' => 'uri'],
                'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
                'revision' => ['type' => 'integer', 'minimum' => 1], 'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
        'IntegrationSession' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['session_key', 'client_key', 'status', 'current', 'masked_ip', 'user_agent_fingerprint', 'issued_at', 'last_seen_at', 'absolute_expires_at', 'revoked_at'],
            'properties' => [
                'session_key' => ['type' => 'string'], 'client_key' => ['type' => 'string'],
                'status' => ['type' => 'string'], 'current' => ['type' => 'boolean'],
                'masked_ip' => ['type' => 'string', 'nullable' => true], 'user_agent_fingerprint' => ['type' => 'string', 'nullable' => true],
                'issued_at' => ['type' => 'string', 'format' => 'date-time'], 'last_seen_at' => ['type' => 'string', 'format' => 'date-time'],
                'absolute_expires_at' => ['type' => 'string', 'format' => 'date-time'], 'revoked_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'IntegrationDelivery' => [
            'type' => 'object',
            'description' => 'Webhook delivery status visible to the owning Tenant. Payload, endpoint URL, signing material and lease data are intentionally excluded.',
            'additionalProperties' => false,
            'required' => [
                'delivery_key', 'endpoint_key', 'event_type', 'status', 'attempt_count',
                'last_status_code', 'last_error_code', 'created_at', 'updated_at', 'delivered_at',
            ],
            'properties' => [
                'delivery_key' => ['type' => 'string', 'pattern' => '^delivery_[0-9a-f]{32}$', 'description' => 'Stable public delivery identifier.'],
                'endpoint_key' => ['type' => 'string', 'pattern' => '^webhook_[0-9a-f]{32}$', 'description' => 'Webhook endpoint that owns the delivery.'],
                'event_type' => ['type' => 'string', 'maxLength' => 96, 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$', 'description' => 'Registered event type; the event payload is not returned by this log API.'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'delivering', 'retryable', 'delivered', 'permanent_failed'],
                    'description' => 'Current delivery lifecycle state.',
                ],
                'attempt_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 8, 'description' => 'Attempts already claimed for this delivery.'],
                'last_status_code' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 599, 'nullable' => true, 'description' => 'Last remote HTTP status, or null when no response was received.'],
                'last_error_code' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Z][A-Z0-9_]{2,63}$', 'nullable' => true, 'description' => 'Safe machine-readable failure code; remote bodies and internal exception text are not exposed.'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'delivered_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'Completion time for delivered records; otherwise null.'],
            ],
        ],
        'IntegrationAttempt' => [
            'type' => 'object',
            'description' => 'One durable attempt record for a webhook delivery. Response bodies and request payloads are intentionally excluded.',
            'additionalProperties' => false,
            'required' => ['attempt_number', 'outcome', 'response_status', 'error_code', 'duration_ms', 'attempted_at'],
            'properties' => [
                'attempt_number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'description' => 'One-based attempt number within the delivery.'],
                'outcome' => ['type' => 'string', 'enum' => ['delivered', 'retryable', 'permanent_failed'], 'description' => 'Recorded result of this attempt.'],
                'response_status' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 599, 'nullable' => true, 'description' => 'Remote HTTP status, or null when no response was received.'],
                'error_code' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Z][A-Z0-9_]{2,63}$', 'nullable' => true, 'description' => 'Safe machine-readable failure code.'],
                'duration_ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 30000, 'description' => 'Measured attempt duration in milliseconds.'],
                'attempted_at' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
        'IntegrationMachineResponse' => selfEnvelope('#/components/schemas/IntegrationMachine'),
        'IntegrationWebhookResponse' => selfEnvelope('#/components/schemas/IntegrationWebhook'),
        'IntegrationSessionResponse' => selfEnvelope('#/components/schemas/IntegrationSession'),
        'IntegrationMachineListResponse' => selfListEnvelope('#/components/schemas/IntegrationMachine'),
        'IntegrationWebhookListResponse' => selfListEnvelope('#/components/schemas/IntegrationWebhook'),
        'IntegrationSessionListResponse' => selfListEnvelope('#/components/schemas/IntegrationSession'),
        'IntegrationProvisionedMachineResponse' => selfEnvelope([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['identity', 'token'],
            'properties' => ['identity' => ['$ref' => '#/components/schemas/IntegrationMachine'], 'token' => ['type' => 'string', 'writeOnly' => true]],
        ]),
        'IntegrationProvisionedWebhookResponse' => selfEnvelope([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['endpoint', 'signing_secret'],
            'properties' => ['endpoint' => ['$ref' => '#/components/schemas/IntegrationWebhook'], 'signing_secret' => ['type' => 'string', 'writeOnly' => true]],
        ]),
        'IntegrationDeliveryListResponse' => selfPageEnvelope('#/components/schemas/IntegrationDelivery'),
        'IntegrationAttemptListResponse' => selfPageEnvelope('#/components/schemas/IntegrationAttempt'),
    ]],
];

/** @param string|array<string,mixed> $data */
function selfEnvelope(string|array $data): array
{
    return [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
        'properties' => [
            'data' => is_string($data) ? ['$ref' => $data] : $data,
            'meta' => ['$ref' => '#/components/schemas/IntegrationRequestMeta'],
        ],
    ];
}

function selfListEnvelope(string $item): array
{
    return selfEnvelope([
        'type' => 'object', 'additionalProperties' => false, 'required' => ['items'],
        'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => $item]]],
    ]);
}

function selfPageEnvelope(string $item): array
{
    $schema = selfListEnvelope($item);
    $schema['properties']['meta'] = [
        'allOf' => [
            ['$ref' => '#/components/schemas/IntegrationRequestMeta'],
            ['type' => 'object', 'required' => ['page', 'page_size', 'total'], 'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'page_size' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
            ]],
        ],
    ];
    return $schema;
}
