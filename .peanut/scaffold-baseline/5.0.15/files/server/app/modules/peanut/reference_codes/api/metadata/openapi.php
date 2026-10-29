<?php

declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$moduleKey = ['name' => 'moduleKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'maxLength' => 96, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$',
]];
$localKeySchema = ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$'];
$setKey = ['name' => 'setKey', 'in' => 'path', 'required' => true, 'schema' => $localKeySchema];
$code = ['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => $localKeySchema];
$idempotency = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => [
    'type' => 'string', 'minLength' => 16, 'maxLength' => 128, 'pattern' => '^[!-~]+$',
]];
$ifMatch = ['name' => 'If-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$']];
$entryResponse = [
    'description' => 'Reference-code entry and strong ETag.',
    'headers' => ['ETag' => ['required' => true, 'schema' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$']]],
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeEntryResponse']]],
];
$legacySuccess = static fn(string $schema, string $description): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$legacyBody = static fn(string $schema): array => [
    'required' => true,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$legacyId = ['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]];
$legacyPage = [
    ['name' => 'page_no', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
    ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
];

return [
    'paths' => [
        '/adminapi/dict/type/lists' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'listLegacyDictionaryTypes',
            'parameters' => [
                ...$legacyPage,
                ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'is_disable', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
            ],
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyTypeListResponse', '字典类型分页列表。'), '400' => $error, '401' => $error, '403' => $error],
        ]],
        '/adminapi/dict/type/all' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'listEnabledLegacyDictionaryTypes',
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyTypeOptionsResponse', '全部启用字典类型。'), '401' => $error, '403' => $error],
        ]],
        '/adminapi/dict/type/detail' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'getLegacyDictionaryType', 'parameters' => [$legacyId],
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyTypeDetailResponse', '字典类型详情。'), '400' => $error, '401' => $error, '403' => $error, '404' => $error],
            'x-peanut-errors' => ['ADMIN_RESOURCE_NOT_FOUND'],
        ]],
        '/adminapi/dict/type/add' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'createLegacyDictionaryType', 'requestBody' => $legacyBody('ReferenceCodeLegacyTypeCreateRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典类型创建成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/type/edit' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'updateLegacyDictionaryType', 'requestBody' => $legacyBody('ReferenceCodeLegacyTypeUpdateRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典类型更新成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/type/delete' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'deleteLegacyDictionaryType', 'requestBody' => $legacyBody('ReferenceCodeLegacyIdentifierRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典类型删除成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/type/status' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'setLegacyDictionaryTypeStatus', 'requestBody' => $legacyBody('ReferenceCodeLegacyStatusRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典类型状态更新成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/data/lists' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'listLegacyDictionaryEntries',
            'parameters' => [
                ...$legacyPage,
                ['name' => 'type_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'is_disable', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
            ],
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyEntryListResponse', '字典数据分页列表。'), '400' => $error, '401' => $error, '403' => $error],
        ]],
        '/adminapi/dict/data/byType' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'listLegacyDictionaryEntriesByType',
            'parameters' => [[
                'name' => 'type_value', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'string', 'default' => ''],
            ]],
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyEntryOptionsResponse', '按类型标识合并系统与租户启用项。'), '401' => $error, '403' => $error],
        ]],
        '/adminapi/dict/data/detail' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'getLegacyDictionaryEntry', 'parameters' => [$legacyId],
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyEntryDetailResponse', '字典数据详情。'), '400' => $error, '401' => $error, '403' => $error, '404' => $error],
            'x-peanut-errors' => ['ADMIN_RESOURCE_NOT_FOUND'],
        ]],
        '/adminapi/dict/data/add' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'createLegacyDictionaryEntry', 'requestBody' => $legacyBody('ReferenceCodeLegacyEntryCreateRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典数据创建成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/data/edit' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'updateLegacyDictionaryEntry', 'requestBody' => $legacyBody('ReferenceCodeLegacyEntryUpdateRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典数据更新成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/data/delete' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'deleteLegacyDictionaryEntry', 'requestBody' => $legacyBody('ReferenceCodeLegacyIdentifierRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典数据删除成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/dict/data/status' => ['post' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'setLegacyDictionaryEntryStatus', 'requestBody' => $legacyBody('ReferenceCodeLegacyStatusRequest'),
            'responses' => ['200' => $legacySuccess('ReferenceCodeLegacyMutationResponse', '字典数据状态更新成功。'), '400' => $error, '401' => $error, '403' => $error, '500' => $error],
        ]],
        '/adminapi/api/v1/reference-code-sets' => ['get' => [
            'tags' => ['ReferenceCodes'], 'operationId' => 'listReferenceCodeSets',
            'responses' => [
                '200' => ['description' => 'Registered reference-code sets.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeSetsResponse']]]],
                '401' => $error, '403' => $error,
            ],
        ]],
        '/adminapi/api/v1/reference-code-sets/{moduleKey}/{setKey}/codes' => [
            'get' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'listReferenceCodes',
                'parameters' => [$moduleKey, $setKey,
                    ['name' => 'as_of', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date-time']],
                    ['name' => 'effective_status', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['active', 'inactive', 'all']]],
                    ['name' => 'include_retired', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'boolean']],
                    ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000]],
                    ['name' => 'page_size', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ],
                'responses' => ['200' => ['description' => 'Reference-code snapshot.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeListResponse']]]], '401' => $error, '403' => $error, '404' => $error, '422' => $error],
            ],
            'post' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'createReferenceCode',
                'parameters' => [$moduleKey, $setKey, $idempotency, [
                    'name' => 'If-None-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['*']],
                ]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeCreateRequest']]]],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '500' => $error],
            ],
        ],
        '/adminapi/api/v1/reference-code-sets/{moduleKey}/{setKey}/codes/{code}' => [
            'get' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'getReferenceCode',
                'parameters' => [$moduleKey, $setKey, $code, ['name' => 'as_of', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date-time']]],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '422' => $error],
            ],
            'put' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'replaceReferenceCode',
                'parameters' => [$moduleKey, $setKey, $code, $idempotency, $ifMatch],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ReferenceCodeVersionRequest']]]],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '500' => $error],
            ],
            'delete' => [
                'tags' => ['ReferenceCodes'], 'operationId' => 'retireReferenceCode',
                'parameters' => [$moduleKey, $setKey, $code, $idempotency, $ifMatch],
                'responses' => ['200' => $entryResponse, '401' => $error, '403' => $error, '404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error, '500' => $error],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'ReferenceCodeLegacyIdentifierRequest' => [
            'type' => 'object', 'description' => '旧控制器保留原始请求；未列字段会被忽略。',
            'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
        ],
        'ReferenceCodeLegacyStatusRequest' => [
            'type' => 'object', 'description' => '旧控制器保留原始请求；未列字段会被忽略。',
            'required' => ['id', 'is_disable'], 'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
            ],
        ],
        'ReferenceCodeLegacyTypeCreateRequest' => [
            'type' => 'object', 'description' => '服务只写入列出的字段；旧控制器会忽略其他字段。',
            'required' => ['name', 'type'], 'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$'],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                'remark' => ['type' => 'string', 'maxLength' => 255],
            ],
        ],
        'ReferenceCodeLegacyTypeUpdateRequest' => [
            'type' => 'object', 'description' => '服务只写入列出的字段；旧控制器会忽略其他字段。',
            'required' => ['id', 'name', 'type'], 'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[A-Za-z0-9_-]+$'],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                'remark' => ['type' => 'string', 'maxLength' => 255],
            ],
        ],
        'ReferenceCodeLegacyEntryCreateRequest' => [
            'type' => 'object', 'description' => '服务只写入列出的字段；旧控制器会忽略其他字段。',
            'required' => ['type_id', 'name', 'value'], 'properties' => [
                'type_id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'sort' => ['type' => 'integer', 'minimum' => 0],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                'remark' => ['type' => 'string', 'maxLength' => 255],
            ],
        ],
        'ReferenceCodeLegacyEntryUpdateRequest' => [
            'type' => 'object', 'description' => '服务只写入列出的字段；旧控制器会忽略其他字段。',
            'required' => ['id', 'name', 'value'], 'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'sort' => ['type' => 'integer', 'minimum' => 0],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                'remark' => ['type' => 'string', 'maxLength' => 255],
            ],
        ],
        'ReferenceCodeLegacyTypeRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'name', 'type', 'is_disable', 'remark', 'create_time', 'update_time', 'delete_time', 'tenant_id', 'active_type'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'type' => ['type' => 'string', 'maxLength' => 100],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                'remark' => ['type' => 'string', 'maxLength' => 255],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
                'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'active_type' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
            ],
        ],
        'ReferenceCodeLegacyTypeOption' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name', 'type'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'type' => ['type' => 'string', 'maxLength' => 100],
            ],
        ],
        'ReferenceCodeLegacyEntryRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'name', 'value', 'type_id', 'type_value', 'sort', 'is_disable', 'remark', 'create_time', 'update_time', 'delete_time', 'tenant_id'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'value' => ['type' => 'string', 'maxLength' => 255],
                'type_id' => ['type' => 'integer', 'minimum' => 1],
                'type_value' => ['type' => 'string', 'maxLength' => 100],
                'sort' => ['type' => 'integer'],
                'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                'remark' => ['type' => 'string', 'maxLength' => 255],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
                'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'ReferenceCodeLegacyEntryOption' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name', 'value', 'sort', 'source'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'value' => ['type' => 'string', 'maxLength' => 255],
                'sort' => ['type' => 'integer'],
                'source' => ['type' => 'string', 'enum' => ['system', 'tenant']],
            ],
        ],
        'ReferenceCodeLegacyTypePage' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyTypeRecord']],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1], 'pageSize' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'ReferenceCodeLegacyEntryPage' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyEntryRecord']],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1], 'pageSize' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'ReferenceCodeLegacyMutationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'maxItems' => 0]],
        ],
        'ReferenceCodeLegacyTypeListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyTypePage']],
        ],
        'ReferenceCodeLegacyTypeOptionsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyTypeOption']]],
        ],
        'ReferenceCodeLegacyTypeDetailResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyTypeRecord']],
        ],
        'ReferenceCodeLegacyEntryListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyEntryPage']],
        ],
        'ReferenceCodeLegacyEntryOptionsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyEntryOption']]],
        ],
        'ReferenceCodeLegacyEntryDetailResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/ReferenceCodeLegacyEntryRecord']],
        ],
        'ReferenceCodeMetadata' => [
            'type' => 'object', 'maxProperties' => 32,
            'additionalProperties' => ['oneOf' => [
                ['type' => 'string', 'maxLength' => 500, 'nullable' => true], ['type' => 'number'], ['type' => 'boolean'],
            ]],
        ],
        'ReferenceCodeVersionRequest' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['label', 'metadata', 'status', 'sort_order', 'effective_at', 'expires_at'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'metadata' => ['$ref' => '#/components/schemas/ReferenceCodeMetadata'],
                'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                'sort_order' => ['type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000],
                'effective_at' => ['type' => 'string', 'format' => 'date-time'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeCreateRequest' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['code', 'label', 'metadata', 'status', 'sort_order', 'effective_at', 'expires_at'],
            'properties' => [
                'code' => $localKeySchema,
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'metadata' => ['$ref' => '#/components/schemas/ReferenceCodeMetadata'],
                'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                'sort_order' => ['type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000],
                'effective_at' => ['type' => 'string', 'format' => 'date-time'],
                'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeSetSummary' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['module_key', 'set_key', 'name', 'description', 'definition_revision'],
            'properties' => [
                'module_key' => $moduleKey['schema'], 'set_key' => $localKeySchema,
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'definition_revision' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'ReferenceCodeEffectiveVersion' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['revision', 'label', 'metadata', 'status', 'sort_order', 'effective_at', 'expires_at'],
            'properties' => [
                'revision' => ['type' => 'integer', 'minimum' => 1], 'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'metadata' => ['$ref' => '#/components/schemas/ReferenceCodeMetadata'], 'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                'sort_order' => ['type' => 'integer', 'minimum' => -1000000, 'maximum' => 1000000],
                'effective_at' => ['type' => 'string', 'format' => 'date-time'], 'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeEntry' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['module_key', 'set_key', 'code', 'lifecycle', 'revision', 'etag', 'effective', 'created_at', 'updated_at', 'retired_at'],
            'properties' => [
                'module_key' => $moduleKey['schema'], 'set_key' => $localKeySchema, 'code' => $localKeySchema,
                'lifecycle' => ['type' => 'string', 'enum' => ['active', 'retired']], 'revision' => ['type' => 'integer', 'minimum' => 1],
                'etag' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$'],
                'effective' => ['$ref' => '#/components/schemas/ReferenceCodeEffectiveVersion', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time'], 'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'retired_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ReferenceCodeMeta' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['request_id'], 'properties' => ['request_id' => ['type' => 'string', 'minLength' => 1]]],
        'ReferenceCodeSetsResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'], 'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeSetSummary']]]],
                'meta' => ['$ref' => '#/components/schemas/ReferenceCodeMeta'],
            ],
        ],
        'ReferenceCodeEntryResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/ReferenceCodeEntry'], 'meta' => ['$ref' => '#/components/schemas/ReferenceCodeMeta']],
        ],
        'ReferenceCodeListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'], 'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items', 'as_of', 'page', 'page_size', 'total'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ReferenceCodeEntry']],
                    'as_of' => ['type' => 'string', 'format' => 'date-time'], 'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'total' => ['type' => 'integer', 'minimum' => 0],
                ]],
                'meta' => ['$ref' => '#/components/schemas/ReferenceCodeMeta'],
            ],
        ],
    ]],
];
