<?php

declare(strict_types=1);

$fragment = ['paths' => [
    '/adminapi/custom.ai-access.catalog' => ['get' => [
        'operationId' => 'aiAccessCatalog',
        'tags' => ['AiAccess'],
        'description' => '读取当前租户的 AI 服务商、可用模型和令牌配置状态。',
        'x-peanut-permissions' => ['custom.ai-access.view'],
        'responses' => ['200' => ['description' => 'AI 接入配置目录']],
    ]],
    '/adminapi/custom.ai-access.save' => ['post' => [
        'operationId' => 'aiAccessSave',
        'tags' => ['AiAccess'],
        'description' => '新增或更新租户 AI 服务商、令牌或模型。同一服务商的地址和 API 前缀在登记后固定。',
        'x-peanut-permissions' => ['custom.ai-access.configure'],
        'responses' => ['200' => ['description' => '已保存的配置，不含令牌值']],
    ]],
    '/adminapi/custom.ai-access.discover' => ['post' => [
        'operationId' => 'aiAccessDiscover',
        'tags' => ['AiAccess'],
        'description' => '使用指定令牌读取服务商提供的模型候选。',
        'x-peanut-permissions' => ['custom.ai-access.configure'],
        'responses' => ['200' => ['description' => '远端模型候选']],
    ]],
    '/adminapi/custom.ai-access.generate' => ['post' => [
        'operationId' => 'aiAccessGenerate',
        'tags' => ['AiAccess'],
        'description' => '同步生成文字或 PNG 图片。',
        'x-peanut-permissions' => ['custom.ai-access.use'],
        'responses' => ['200' => ['description' => '生成结果与请求回执']],
    ]],
    '/adminapi/custom.ai-access.request-status' => ['get' => [
        'operationId' => 'aiAccessRequestStatus',
        'tags' => ['AiAccess'],
        'description' => '根据请求标识查阅当前成员的调用状态。',
        'x-peanut-permissions' => ['custom.ai-access.use'],
        'responses' => ['200' => ['description' => '请求回执']],
    ]],
]];

// App-owned HTTP schema, derived from Catalog, ProviderClient and the existing
// custom-ai-access Web client. This metadata never reads credentials or sends HTTP.
$ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$responseRef = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];
$identifier = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,63}$'];
$capability = ['type' => 'string', 'enum' => ['text', 'image']];
$nonnegative = ['type' => 'integer', 'minimum' => 0];
$positive = ['type' => 'integer', 'minimum' => 1];
$enabled = ['type' => 'boolean'];
$entryBase = ['key' => $identifier, 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
    'enabled' => $enabled];
$catalogEntry = static fn(array $extra, array $required = []): array => [
    'type' => 'object', 'additionalProperties' => false,
    'required' => [...array_keys($entryBase), ...$required],
    'properties' => array_replace($entryBase, $extra),
];
$protocols = ['type' => 'object', 'additionalProperties' => false, 'minProperties' => 1,
    'properties' => [
        'text' => ['type' => 'string', 'enum' => ['openai_text', 'openai_responses', 'anthropic_messages']],
        'image' => ['type' => 'string', 'enum' => ['openai_image']],
    ]];
$origin = ['type' => 'string', 'minLength' => 1, 'maxLength' => 255,
    'description' => 'Only the HTTPS origin already registered by the instance maintainer is eligible; the origin is never editable on the same provider identity.'];
$prefix = ['type' => 'string', 'pattern' => '^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$', 'maxLength' => 80];
$models = ['type' => 'array', 'items' => ['oneOf' => [$identifier, ['type' => 'string', 'enum' => ['*']]]]];
$capabilities = ['type' => 'array', 'minItems' => 1, 'items' => $capability, 'uniqueItems' => true];
$providerFields = ['priority' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
    'origin' => $origin, 'api_prefix' => $prefix];
$credentialFields = ['provider' => $identifier, 'models' => $models,
    'configured' => ['type' => 'boolean', 'readOnly' => true,
        'description' => 'Only whether an encrypted credential is configured; the credential value is never returned.']];
$modelFields = ['provider' => $identifier, 'priority' => $providerFields['priority'],
    'definition' => $identifier,
    'remote_model' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
    'protocols' => $protocols,
    'input_limit_chars' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200000],
    'max_output_tokens' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 128000]];
$definitionFields = ['author' => $identifier, 'capabilities' => $capabilities];
$defaultFields = ['provider' => $identifier, 'offering' => $identifier];
$receiptStatus = ['type' => 'string', 'enum' => ['sending', 'succeeded', 'rejected', 'unknown']];
$requestKey = ['type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,100}$'];
$receipt = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['request_key', 'provider_key', 'model_key', 'credential_key',
        'capability', 'status', 'provider_request_id', 'usage'],
    'properties' => [
        'request_key' => $requestKey, 'provider_key' => $identifier,
        'model_key' => $identifier, 'credential_key' => $identifier,
        'capability' => $capability, 'status' => $receiptStatus,
        'provider_request_id' => ['type' => 'string', 'nullable' => true, 'maxLength' => 180],
        'usage' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => false,
            'properties' => array_fill_keys(['prompt_tokens', 'completion_tokens', 'input_tokens',
                'output_tokens', 'total_tokens'], $nonnegative)],
    ]];
$saveCommon = ['kind' => ['type' => 'string'], 'key' => $identifier,
    'name' => $entryBase['name'], 'enabled' => $enabled];
// Catalog::save accepts extra fields without persisting them. Do not claim a
// stricter runtime input policy than that existing implementation.
$saveForm = static fn(string $kind, array $fields, array $required = []): array => [
    'type' => 'object', 'additionalProperties' => true,
    'required' => ['kind', 'key', 'name', 'enabled', ...$required],
    'properties' => ['kind' => ['type' => 'string', 'enum' => [$kind]]] + array_replace($saveCommon, $fields),
];
$schemas = [
    'AiAccessProvider' => $catalogEntry($providerFields, ['priority', 'origin', 'api_prefix']),
    'AiAccessCredential' => $catalogEntry($credentialFields, ['provider', 'models', 'configured']),
    'AiAccessModel' => $catalogEntry($modelFields, array_keys($modelFields)),
    'AiAccessAuthor' => $catalogEntry([]),
    'AiAccessDefinition' => $catalogEntry($definitionFields, ['author', 'capabilities']),
    'AiAccessDefault' => $catalogEntry(['key' => $capability] + $defaultFields, ['provider', 'offering']),
    'AiAccessCatalog' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['providers', 'credentials', 'models', 'authors', 'definitions',
            'defaults', 'can_configure'],
        'properties' => [
            'providers' => ['type' => 'array', 'items' => $ref('AiAccessProvider')],
            'credentials' => ['type' => 'array', 'items' => $ref('AiAccessCredential')],
            'models' => ['type' => 'array', 'items' => $ref('AiAccessModel')],
            'authors' => ['type' => 'array', 'items' => $ref('AiAccessAuthor')],
            'definitions' => ['type' => 'array', 'items' => $ref('AiAccessDefinition')],
            'defaults' => ['type' => 'array', 'items' => $ref('AiAccessDefault')],
            'can_configure' => $enabled,
        ]],
    'AiAccessSaveRequest' => ['oneOf' => [
        $saveForm('provider', $providerFields, ['origin', 'api_prefix']),
        $saveForm('credential', ['provider' => $identifier, 'models' => $models,
            'secret' => ['type' => 'string', 'writeOnly' => true, 'maxLength' => 8192,
                'pattern' => '^[^\x00-\x20\x7f]*$',
                'description' => 'Only supplied when configuring or replacing a credential; omitted on an existing credential to retain its encrypted value. Never echoed.']],
            ['provider', 'models']),
        $saveForm('model', $modelFields, array_values(array_diff(array_keys($modelFields), ['priority']))),
        $saveForm('author', []),
        $saveForm('definition', $definitionFields, ['author', 'capabilities']),
        $saveForm('default', ['key' => $capability] + $defaultFields, ['provider', 'offering']),
    ]],
    'AiAccessSavedEntry' => ['oneOf' => [
        $ref('AiAccessProvider'), $ref('AiAccessCredential'), $ref('AiAccessModel'),
        $ref('AiAccessAuthor'), $ref('AiAccessDefinition'), $ref('AiAccessDefault'),
    ]],
    'AiAccessDiscoverRequest' => ['type' => 'object',
        'required' => ['provider', 'credential'],
        'properties' => ['provider' => $identifier, 'credential' => $identifier]],
    'AiAccessRemoteModel' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'owner'],
        'properties' => [
            'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'owner' => ['type' => 'string', 'maxLength' => 120],
        ]],
    'AiAccessDiscoveredModels' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['items'],
        'properties' => ['items' => ['type' => 'array', 'items' => $ref('AiAccessRemoteModel')]]],
    'AiAccessGenerateRequest' => ['type' => 'object',
        'required' => ['capability', 'prompt', 'request_key'],
        'properties' => [
            'capability' => $capability,
            'prompt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200000,
                'pattern' => '\S', 'description' => 'UTF-8 text; outbound calls require a tenant-authorized model and a transaction-free context.'],
            'request_key' => $requestKey,
            'provider' => $identifier + ['nullable' => true],
            'model' => $identifier + ['nullable' => true],
            'credential' => $identifier + ['nullable' => true],
        ]],
    'AiAccessRequestReceipt' => $receipt,
    'AiAccessGenerateResult' => ['oneOf' => [
        ['type' => 'object', 'additionalProperties' => false,
            'required' => ['text', 'request'], 'properties' => [
                'text' => ['type' => 'string', 'minLength' => 1],
                'request' => $ref('AiAccessRequestReceipt')]],
        ['type' => 'object', 'additionalProperties' => false,
            'required' => ['image', 'mime_type', 'request'], 'properties' => [
                'image' => ['type' => 'string', 'minLength' => 1,
                    'description' => 'Base64-encoded PNG only, never a service URL.'],
                'mime_type' => ['type' => 'string', 'enum' => ['image/png']],
                'request' => $ref('AiAccessRequestReceipt')]],
    ]],
    'AiAccessErrorEnvelope' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['code', 'msg', 'data'],
        'properties' => [
            'code' => ['type' => 'integer', 'minimum' => 40000, 'maximum' => 59999],
            'msg' => ['type' => 'string'],
            'data' => ['type' => 'object', 'required' => ['error_code'],
                'additionalProperties' => true,
                'properties' => ['error_code' => ['type' => 'string',
                    'pattern' => '^[A-Z][A-Z0-9_]+$']]],
        ]],
];
$envelope = static fn(array $data): array => [
    'type' => 'object', 'additionalProperties' => false,
    'required' => ['code', 'msg', 'data'],
    'properties' => [
        'code' => ['type' => 'integer', 'enum' => [20000]],
        'msg' => ['type' => 'string', 'enum' => ['success']],
        'data' => $data,
    ],
];
foreach ([
    'AiAccessCatalogResponse' => $ref('AiAccessCatalog'),
    'AiAccessSaveResponse' => $ref('AiAccessSavedEntry'),
    'AiAccessDiscoverResponse' => $ref('AiAccessDiscoveredModels'),
    'AiAccessGenerateResponse' => $ref('AiAccessGenerateResult'),
    'AiAccessRequestStatusResponse' => $ref('AiAccessRequestReceipt'),
] as $name => $data) {
    $schemas[$name] = $envelope($data);
}
$fragment['components'] = [
    'schemas' => $schemas,
    'responses' => [
        'AiAccessErrorResponse' => [
            'description' => 'Current tenant/member authorization, input or downstream failure; no credential contents.',
            'content' => ['application/json' => ['schema' => $ref('AiAccessErrorEnvelope')]],
        ],
    ],
];
$body = static fn(string $schema): array => [
    'required' => true,
    'content' => ['application/json' => ['schema' => $ref($schema)]],
];
$endpoints = [
    ['path' => '/adminapi/custom.ai-access.catalog', 'method' => 'get',
        'response' => 'AiAccessCatalogResponse',
        'errors' => [],
        'statuses' => [401, 403]],
    ['path' => '/adminapi/custom.ai-access.save', 'method' => 'post',
        'response' => 'AiAccessSaveResponse', 'body' => 'AiAccessSaveRequest',
        'errors' => ['AI_ACCESS_CONFIGURATION_INVALID', 'AI_ACCESS_ORIGIN_DENIED',
            'AI_ACCESS_SECRET_UNAVAILABLE'],
        'statuses' => [400, 401, 403, 409, 422]],
    ['path' => '/adminapi/custom.ai-access.discover', 'method' => 'post',
        'response' => 'AiAccessDiscoverResponse', 'body' => 'AiAccessDiscoverRequest',
        'errors' => ['AI_ACCESS_CONFIGURATION_INVALID', 'AI_ACCESS_TARGET_UNAVAILABLE',
            'AI_ACCESS_ORIGIN_DENIED', 'AI_ACCESS_SECRET_UNAVAILABLE', 'AI_ACCESS_DISCOVERY_FAILED'],
        'statuses' => [400, 401, 403, 409]],
    ['path' => '/adminapi/custom.ai-access.generate', 'method' => 'post',
        'response' => 'AiAccessGenerateResponse', 'body' => 'AiAccessGenerateRequest',
        'errors' => ['AI_ACCESS_INPUT_INVALID', 'AI_ACCESS_REQUEST_KEY_INVALID',
            'AI_ACCESS_PERMISSION_DENIED', 'AI_ACCESS_ORIGIN_DENIED',
            'AI_ACCESS_CREDENTIAL_AMBIGUOUS', 'AI_ACCESS_TRANSACTION_ACTIVE',
            'AI_ACCESS_REQUEST_EXISTS', 'AI_ACCESS_TARGET_UNAVAILABLE',
            'AI_ACCESS_PROVIDER_REJECTED', 'AI_ACCESS_RESULT_UNKNOWN'],
        'statuses' => [400, 401, 403, 409]],
    ['path' => '/adminapi/custom.ai-access.request-status', 'method' => 'get',
        'response' => 'AiAccessRequestStatusResponse',
        'errors' => ['AI_ACCESS_REQUEST_KEY_INVALID', 'AI_ACCESS_PERMISSION_DENIED',
            'AI_ACCESS_REQUEST_NOT_FOUND'],
        'statuses' => [400, 401, 403, 404],
        'parameters' => [['in' => 'query', 'name' => 'request_key',
            'required' => true, 'schema' => $requestKey]],
    ],
];
foreach ($endpoints as $endpoint) {
    $operation = &$fragment['paths'][$endpoint['path']][$endpoint['method']];
    $operation['responses']['200']['content'] = [
        'application/json' => ['schema' => $ref($endpoint['response'])],
    ];
    foreach ($endpoint['statuses'] as $status) {
        $operation['responses'][(string) $status] = $responseRef('AiAccessErrorResponse');
    }
    if (isset($endpoint['body'])) {
        $operation['requestBody'] = $body($endpoint['body']);
    }
    if (isset($endpoint['parameters'])) {
        $operation['parameters'] = $endpoint['parameters'];
    }
    if ($endpoint['errors'] !== []) {
        $operation['x-peanut-errors'] = $endpoint['errors'];
    }
    unset($operation);
}
return $fragment;
