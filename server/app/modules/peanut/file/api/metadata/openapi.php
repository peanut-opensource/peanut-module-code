<?php

declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$typedError = $error;
$uploadBody = [
    'required' => true,
    'content' => ['multipart/form-data' => ['schema' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['file'],
        'properties' => [
            'file' => ['type' => 'string', 'format' => 'binary'],
            'cid' => ['$ref' => '#/components/schemas/FileNonNegativeIntegerInput'],
        ],
    ]]],
];
$uploadResponse = [
    'description' => '上传成功',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileUploadResponse']]],
];
$mutationResponse = [
    'description' => '操作成功',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileMutationResponse']]],
];
$visibilityEnvelope = static fn(array $data): array => ['type' => 'object', 'additionalProperties' => false,
    'required' => ['code','msg','data'], 'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]],
        'msg' => ['type' => 'string'], 'data' => $data]];
$visibilityResult = static fn(array $data): array => ['description' => 'Current durable visibility state; never a claim that downloaded third-party copies were recalled.',
    'content' => ['application/json' => ['schema' => $visibilityEnvelope($data)]]];
$visibilityErrors = ['400' => $error, '401' => $error, '403' => $error, '404' => $error, '409' => $error];
$visibilityBatchRef = ['$ref' => '#/components/schemas/FileVisibilityBatch'];
$visibilityKey = ['name' => 'batchKey', 'in' => 'path', 'required' => true,
    'schema' => ['type' => 'string', 'pattern' => '^batch_[a-f0-9]{32}$']];

// Instance-owned storage configuration; provider credentials never appear in snapshots.
$storageObject = static fn(array $properties, array $required = []): array => [
    'type' => 'object', 'additionalProperties' => false,
] + ($properties === [] ? [] : ['properties' => $properties]) + ($required === [] ? [] : ['required' => $required]);
$storageId = ['type' => 'integer', 'minimum' => 1];
$storageOutputId = ['type' => 'string', 'pattern' => '^[1-9][0-9]*$', 'description' => 'ThinkORM bigint projection.'];
$storageName = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Trimmed before validation.'];
$storageKey = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9._-]{2,63}$', 'description' => 'Trimmed ASCII key.'];
$storageDriver = ['type' => 'string', 'enum' => ['local', 'qiniu', 'aliyun', 'qcloud']];
$storageAccess = ['type' => 'string', 'enum' => ['public', 'private']];
$storageStatus = ['type' => 'string', 'enum' => ['active', 'read_only', 'disabled']];
$storageNullable = ['type' => 'string', 'nullable' => true];
$storageUrl = $storageNullable + ['description' => 'Blank normalizes to null; otherwise an absolute http(s) URL, with trailing slashes removed.'];
$storageTimes = ['created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string']];
$storageCredentials = $storageObject([
    'access_key' => ['type' => 'string', 'minLength' => 1, 'writeOnly' => true, 'description' => 'Non-empty after trimming.'],
    'secret_key' => ['type' => 'string', 'minLength' => 1, 'writeOnly' => true, 'description' => 'Non-empty after trimming.'],
], ['access_key', 'secret_key']);
$storageNoCredentials = ['oneOf' => [['type' => 'string', 'nullable' => true, 'enum' => [null]],
    ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']], $storageObject([])]];
$storageOptionalCredentials = ['oneOf' => [...$storageNoCredentials['oneOf'], $storageCredentials],
    'description' => 'For cloud accounts a non-empty value rotates both keys; omitted/null/empty leaves credentials unchanged. Local accounts reject non-empty credentials.'];
$storageReference = ['type' => 'string', 'nullable' => true, 'pattern' => '^\s*$', 'description' => 'Non-empty credential references are no longer supported.'];
$storageAccount = ['id' => $storageOutputId, 'account_key' => $storageKey, 'driver' => $storageDriver,
    'name' => $storageName, 'credential_key_version' => $storageNullable,
    'credential_rotated_at' => $storageNullable, 'credential_masked' => ['type' => 'string', 'nullable' => true, 'enum' => ['********', null]],
    'status' => ['type' => 'string', 'enum' => ['active', 'disabled']]] + $storageTimes;
$storageLocation = ['bucket' => $storageNullable, 'region' => $storageNullable, 'endpoint' => $storageUrl,
    'access_domain' => $storageUrl, 'local_path' => ['type' => 'string', 'nullable' => true, 'enum' => ['public/storage', 'private/storage', null]]];
$storageSpace = ['id' => $storageOutputId, 'space_key' => $storageKey, 'account_id' => $storageOutputId,
    'name' => $storageName, 'access_type' => $storageAccess] + $storageLocation + [
    'status' => $storageStatus, 'account_key' => $storageKey, 'driver' => $storageDriver] + $storageTimes;
$storageRouteKeys = ['default.public', 'default.private', 'material.image', 'material.image-variant', 'material.video',
    'material.file', 'member.avatar', 'article.asset', 'decoration.asset', 'website.asset', 'export.xlsx', 'export.csv',
    'attachment.sensitive', 'document.resource', 'document.snapshot', 'document.snapshot-sensitive'];
$storageRouteKey = ['type' => 'string', 'enum' => $storageRouteKeys];
$storageRoute = ['route_key' => $storageRouteKey, 'access_type' => $storageAccess, 'space_id' => $storageOutputId,
    'updated_at' => ['type' => 'string'], 'space_key' => $storageKey, 'space_name' => $storageName, 'driver' => $storageDriver];
$storageSchemas = [
    'StorageAccountSnapshot' => $storageObject($storageAccount, array_keys($storageAccount)),
    'StorageSpaceSnapshot' => $storageObject($storageSpace, array_keys($storageSpace)),
    'StorageRouteSnapshot' => $storageObject($storageRoute, array_keys($storageRoute)),
    'StorageSnapshot' => $storageObject([
        'accounts' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StorageAccountSnapshot']],
        'spaces' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StorageSpaceSnapshot']],
        'routes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StorageRouteSnapshot']],
        'purposes' => ['type' => 'array', 'minItems' => 5, 'maxItems' => 5, 'items' => ['type' => 'string',
            'enum' => ['material.image', 'material.video', 'material.file', 'export.xlsx', 'export.csv']]],
    ], ['accounts', 'spaces', 'routes', 'purposes']),
    'StorageAccountCreate' => ['oneOf' => [
        $storageObject(['account_key' => $storageKey, 'driver' => ['type' => 'string', 'enum' => ['local']],
            'name' => $storageName, 'credentials' => $storageNoCredentials, 'credential_ref' => $storageReference], ['account_key', 'driver', 'name']),
        $storageObject(['account_key' => $storageKey, 'driver' => ['type' => 'string', 'enum' => ['qiniu', 'aliyun', 'qcloud']],
            'name' => $storageName, 'credentials' => $storageCredentials, 'credential_ref' => $storageReference], ['account_key', 'driver', 'name', 'credentials']),
    ]],
    'StorageAccountUpdate' => $storageObject(['id' => $storageId, 'name' => $storageName,
        'status' => ['type' => 'string', 'enum' => ['active', 'disabled'], 'default' => 'active'],
        'credentials' => $storageOptionalCredentials, 'credential_ref' => $storageReference], ['id', 'name']),
    'StorageSpaceCreate' => $storageObject(['account_id' => $storageId, 'space_key' => $storageKey,
        'name' => $storageName, 'access_type' => $storageAccess] + $storageLocation, ['account_id', 'space_key', 'name', 'access_type']) + [
            'description' => 'The existing account selects the driver. local: local_path must equal public/storage or private/storage for access_type, bucket must be blank; persisted local region/endpoint must be null. Cloud: bucket required and local_path blank; qcloud also needs region, aliyun an http(s) endpoint, qiniu or any public cloud space an access_domain. Blank optional strings normalize to null. Account and location are immutable after creation.',
        ],
    'StorageSpaceUpdate' => $storageObject(['id' => $storageId, 'name' => $storageName,
        'access_domain' => $storageUrl, 'status' => $storageStatus + ['default' => 'active']], ['id', 'name']) + [
            'description' => 'Revalidates the existing immutable account/location. qiniu or a public cloud space must retain a non-blank access_domain; omitted access_domain clears it and can therefore reject.',
        ],
    'StorageRouteWrite' => $storageObject(['route_key' => $storageRouteKey, 'access_type' => $storageAccess,
        'space_id' => $storageId], ['route_key', 'access_type', 'space_id']) + [
            'description' => 'Target space must be active and have matching access_type. default.public/private must match their suffix; registered material/member/article/decoration/website purposes are public and export/attachment/document purposes are private. Upserts only this route.',
        ],
];
$storagePaths = [];
foreach ([
    '' => ['get', 'getStorageConfiguration', null, null],
    '/account' => ['post', 'createStorageAccount', 'StorageAccountCreate', 'STORAGE_ACCOUNT_CREATE'],
    '/account/update' => ['post', 'updateStorageAccount', 'StorageAccountUpdate', 'STORAGE_ACCOUNT_UPDATE'],
    '/space' => ['post', 'createStorageSpace', 'StorageSpaceCreate', 'STORAGE_SPACE_CREATE'],
    '/space/update' => ['post', 'updateStorageSpace', 'StorageSpaceUpdate', 'STORAGE_SPACE_UPDATE'],
    '/route' => ['post', 'setStorageRoute', 'StorageRouteWrite', 'STORAGE_ROUTE'],
] as $suffix => [$method, $operationId, $body, $reason]) {
    $data = $suffix === '' ? ['$ref' => '#/components/schemas/StorageSnapshot']
        : (in_array($suffix, ['/account', '/space'], true) ? $storageObject(['id' => $storageId], ['id'])
            : ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']]);
    $operation = ['tags' => ['Storage'], 'operationId' => $operationId,
        'description' => $suffix === '' ? 'Requires platform.ops.read. Instance-owned snapshot; credential ciphertext and keys are never returned.'
            : 'Requires platform.ops.maintenance.manage. Validated configuration mutation and platform audit run in the existing transaction. Unknown body keys reject; no provider connection or data migration is requested.',
        'responses' => ['200' => ['description' => '成功', 'content' => ['application/json' => ['schema' => $visibilityEnvelope($data)]]],
            '400' => $error, '401' => $error, '403' => $error, '422' => $error, '500' => $error]];
    if ($body !== null) {
        $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $body]]]];
        $operation['x-peanut-errors'] = [$reason . '_INPUT_INVALID'];
    }
    $storagePaths['/platformapi/infrastructure/storage' . $suffix][$method] = $operation;
}

return [
    'paths' => [
        ...$storagePaths,
        '/adminapi/api/v1/files/visibility/preview' => ['post' => [
            'tags' => ['File'], 'operationId' => 'previewFileVisibility',
            'description' => 'Read-only whole-batch preflight. Requires manage and visibility.change on every source and derivative. Unqualified external origins reject before intent.',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object',
                'additionalProperties' => false, 'required' => ['file_keys'], 'properties' => ['file_keys' => ['type' => 'array',
                    'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => ['type' => 'string', 'pattern' => '^file_[a-f0-9]{32}$']]]]]]],
            'responses' => ['200' => $visibilityResult(['type' => 'object', 'additionalProperties' => false,
                'required' => ['confirmation','signature','execution_started'], 'properties' => [
                    'confirmation' => ['$ref' => '#/components/schemas/FileVisibilityConfirmation'],
                    'signature' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'execution_started' => ['type' => 'boolean', 'enum' => [false]]]])] + $visibilityErrors,
        ]],
        '/adminapi/api/v1/files/visibility/batches' => [
            'post' => ['tags' => ['File'], 'operationId' => 'submitFileVisibility',
                'description' => 'Accept all objects atomically, then persist each nontransactional storage step. Duplicate requests return the original batch. Unknown HTTP outcomes require querying that batch.',
                'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9._:-]{16,96}$']]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object',
                    'additionalProperties' => false, 'required' => ['confirmation','signature','impact_confirmed'], 'properties' => [
                        'confirmation' => ['$ref' => '#/components/schemas/FileVisibilityConfirmation'],
                        'signature' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'impact_confirmed' => ['type' => 'boolean', 'enum' => [true]],
                    ]]]]], 'responses' => ['200' => $visibilityResult($visibilityBatchRef)] + $visibilityErrors],
            'get' => ['tags' => ['File'], 'operationId' => 'recentFileVisibilityBatches',
                'responses' => ['200' => $visibilityResult(['type' => 'object', 'required' => ['items'], 'additionalProperties' => false,
                    'properties' => ['items' => ['type' => 'array', 'maxItems' => 50, 'items' => ['$ref' => '#/components/schemas/FileVisibilityBatchSummary']]]])] + $visibilityErrors],
        ],
        '/adminapi/api/v1/files/visibility/batches/{batchKey}' => ['get' => [
            'tags' => ['File'], 'operationId' => 'getFileVisibilityBatch', 'parameters' => [$visibilityKey],
            'responses' => ['200' => $visibilityResult($visibilityBatchRef)] + $visibilityErrors,
        ]],
        '/adminapi/api/v1/files/visibility/batches/{batchKey}/resume' => ['post' => [
            'tags' => ['File'], 'operationId' => 'resumeFileVisibilityBatch', 'parameters' => [$visibilityKey],
            'description' => 'Explicit recovery of the same durable batch, under current permissions. Inspect original locations before retry; never reopen public access or replace the accepted plan.',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => false]]]],
            'responses' => ['200' => $visibilityResult($visibilityBatchRef)] + $visibilityErrors,
        ]],
        '/adminapi/peanut.file.snapshot.pending' => ['get' => [
            'tags' => ['File'],'operationId' => 'pendingDocumentFileSnapshots','responses' => [
                '200' => ['description' => 'Old unclaimed captures only; no storage address or credentials.', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object','additionalProperties' => false,'required' => ['code','msg','data'],'properties' => [
                        'code' => ['type' => 'integer','enum' => [20000]],'msg' => ['type' => 'string'],'data' => ['type' => 'array','items' => [
                            'type' => 'object','additionalProperties' => false,'required' => ['file_key','status','created_at'],'properties' => [
                                'file_key' => ['type' => 'string','pattern' => '^file_[0-9a-f]{32}$'],'status' => ['type' => 'string','enum' => ['captured','cleanup_pending','interrupted_capture']],'created_at' => ['type' => 'string'],
                            ],
                        ]],
                    ],
                ]]]], '401' => $error,'403' => $error,
            ],
        ]],
        '/adminapi/peanut.file.snapshot.recover' => ['post' => [
            'tags' => ['File'],'operationId' => 'recoverDocumentFileSnapshot',
            'requestBody' => ['required' => true,'content' => ['application/json' => ['schema' => [
                'type' => 'object','additionalProperties' => false,'required' => ['file_key'],'properties' => ['file_key' => ['type' => 'string','pattern' => '^file_[0-9a-f]{32}$']],
            ]]]], 'responses' => [
                '200' => ['description' => 'Real provider deletion result. Unknown outcome remains cleanup_pending; claimed versions always reject.', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object','additionalProperties' => false,'required' => ['code','msg','data'],'properties' => [
                        'code' => ['type' => 'integer','enum' => [20000]],'msg' => ['type' => 'string'],'data' => [
                            'type' => 'object','additionalProperties' => false,'required' => ['file_key','cleanup_pending','provider_delete_acknowledged'],
                            'properties' => ['file_key' => ['type' => 'string'],'cleanup_pending' => ['type' => 'boolean'],'provider_delete_acknowledged' => ['type' => 'boolean'],'provider_outcome' => ['type' => 'string']],
                        ],
                    ],
                ]]]], '401' => $error,'403' => $error,'409' => $error,'422' => $error,
            ],
        ]],
        '/adminapi/api/v1/files/resolve' => ['post' => [
            'tags' => ['File'], 'operationId' => 'resolveFileAccess',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['file_key', 'operation'],
                'properties' => [
                    'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                    'operation' => ['type' => 'string', 'enum' => ['preview', 'download_original', 'manage']],
                    'document_id' => ['type' => 'integer', 'minimum' => 1],
                    'variant_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,31}$', 'maxLength' => 32],
                    'viewer_adapter_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                ],
            ]]]],
            'responses' => [
                '200' => ['description' => 'Current live File access, or explicit unavailable preview. No original bytes without download authorization.',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileAccessResponse']]]],
                '400' => $error, '401' => $error, '403' => $error, '404' => $error, '409' => $error,
            ],
        ]],
        '/adminapi/api/v1/files/assets' => [
            'get' => [
                'tags' => ['File'], 'operationId' => 'listFileAssets',
                'parameters' => [
                    ['name' => 'cid', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                    ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ['name' => 'source', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                    ['name' => 'page_no', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Canonical Tenant image assets and derivatives.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileAssetListResponse']]],
                    ],
                    '401' => $error, '403' => $error, '422' => $error,
                ],
            ],
        ],
        '/adminapi/peanut.file.upload.image' => ['post' => [
            'tags' => ['File'], 'operationId' => 'uploadAdminImage',
            'requestBody' => $uploadBody,
            'responses' => ['200' => $uploadResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['UPLOAD_FILE_REQUIRED'],
        ]],
        '/adminapi/peanut.file.upload.video' => ['post' => [
            'tags' => ['File'], 'operationId' => 'uploadAdminVideo',
            'requestBody' => $uploadBody,
            'responses' => ['200' => $uploadResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['UPLOAD_FILE_REQUIRED'],
        ]],
        '/api/upload/image' => ['x-peanut-required-modules' => ['peanut.member'], 'post' => [
            'tags' => ['File'], 'operationId' => 'uploadMemberImage',
            'requestBody' => $uploadBody,
            'responses' => ['200' => $uploadResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['UPLOAD_FILE_REQUIRED'],
        ]],
        '/adminapi/peanut.file.move' => ['post' => [
            'tags' => ['File'], 'operationId' => 'moveFiles',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileMoveRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
        ]],
        '/adminapi/peanut.file.rename' => ['post' => [
            'tags' => ['File'], 'operationId' => 'renameFile',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileRenameRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
            'x-peanut-errors' => ['FILE_NAME_REQUIRED', 'FILE_NAME_TOO_LONG'],
        ]],
        '/adminapi/peanut.file.delete' => ['post' => [
            'tags' => ['File'], 'operationId' => 'deleteFiles',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileIdsRequest']]]],
            'responses' => [
                '200' => ['description' => '素材及对应存储对象删除计数', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileDeleteResponse']]]],
                '400' => $typedError, '401' => $typedError, '403' => $typedError,
            ],
        ]],
        '/adminapi/peanut.file.category.list' => ['get' => [
            'tags' => ['File'], 'operationId' => 'listFileCategories',
            'parameters' => [[
                'name' => 'type', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'enum' => [10, 20, 30], 'default' => 10],
            ]],
            'responses' => [
                '200' => [
                    'description' => '当前租户的文件分类树；每个节点保留 ORM 行的全部已声明字段。',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryListResponse']]],
                ],
                '400' => $error, '401' => $error, '403' => $error,
            ],
        ]],
        '/adminapi/peanut.file.category.add' => ['post' => [
            'tags' => ['File'], 'operationId' => 'createFileCategory',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryCreateRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
        ]],
        '/adminapi/peanut.file.category.edit' => ['post' => [
            'tags' => ['File'], 'operationId' => 'updateFileCategory',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryUpdateRequest']]]],
            'responses' => ['200' => $mutationResponse, '400' => $typedError, '401' => $typedError, '403' => $typedError],
        ]],
        '/adminapi/peanut.file.category.delete' => ['post' => [
            'tags' => ['File'], 'operationId' => 'deleteFileCategory',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileIdentifierRequest']]]],
            'responses' => [
                '200' => ['description' => '分类子树、素材和存储对象删除计数', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/FileCategoryDeleteResponse']]]],
                '400' => $typedError, '401' => $typedError, '403' => $typedError,
            ],
        ]],
    ],
    'components' => ['schemas' => [
        ...$storageSchemas,
        'FileVisibilityConfirmation' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['version','batch_key','actor','expires_at','target','manifest'], 'properties' => [
                'version' => ['type' => 'integer', 'enum' => [1]], 'batch_key' => ['type' => 'string', 'pattern' => '^batch_[a-f0-9]{32}$'],
                'actor' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['tenant_id','account_id','member_id','client_key','session_digest','authorization_revision'],
                    'properties' => ['tenant_id' => ['type' => 'string'], 'account_id' => ['type' => 'string'], 'member_id' => ['type' => 'string'],
                        'client_key' => ['type' => 'string'], 'session_digest' => ['type' => 'string'], 'authorization_revision' => ['type' => 'string']]],
                'expires_at' => ['type' => 'integer'], 'target' => ['type' => 'string', 'enum' => ['private']],
                'manifest' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['roots','files','derivatives_sha256','registered_reference_count','references_sha256','known_cdn','external_usage_complete','warning'],
                    'properties' => ['roots' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'files' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => false,
                            'required' => ['source','target'], 'properties' => [
                                'source' => ['type' => 'object', 'additionalProperties' => false,
                                    'required' => ['file_key','tenant_id','purpose','access_type','storage_space_id','object_key','original_name','media_type','size_bytes','sha256','revision','driver','local_path','space_key'],
                                    'properties' => array_fill_keys(['file_key','tenant_id','purpose','access_type','storage_space_id','object_key','original_name','media_type','size_bytes','sha256','revision','driver','local_path','space_key'], ['type' => 'string'])],
                                'target' => ['type' => 'object', 'additionalProperties' => false,
                                    'required' => ['storage_space_id','space_key','object_key','access_type','driver','local_path'],
                                    'properties' => array_fill_keys(['storage_space_id','space_key','object_key','access_type','driver','local_path'], ['type' => 'string'])],
                            ]]],
                        'derivatives_sha256' => ['type' => 'string'], 'references_sha256' => ['type' => 'string'],
                        'registered_reference_count' => ['type' => 'integer'], 'known_cdn' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'external_usage_complete' => ['type' => 'boolean', 'enum' => [false]], 'warning' => ['type' => 'string'],
                    ]],
            ]],
        'FileVisibilityBatchSummary' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['batch_key','status','revision','created_at','updated_at'], 'properties' => [
                'batch_key' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['pending','running','completed','attention']],
                'revision' => ['type' => 'integer'], 'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            ]],
        'FileVisibilityBatch' => ['allOf' => [
            ['type' => 'object', 'required' => ['batch_key','status','revision','created_at','updated_at'], 'properties' => [
                'batch_key' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['pending','running','completed','attention']],
                'revision' => ['type' => 'integer'], 'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
            ]],
            ['type' => 'object', 'required' => ['items','external_usage_complete','downloaded_copies_recoverable'], 'properties' => [
                'external_usage_complete' => ['type' => 'boolean', 'enum' => [false]], 'downloaded_copies_recoverable' => ['type' => 'boolean', 'enum' => [false]],
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['file_key','status','step','attempts','error_code','receipts'], 'properties' => [
                        'file_key' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['restricting','verifying','ready_private','restricted_attention']],
                        'step' => ['type' => 'string', 'enum' => ['copy','switch','close_source','verify','completed']], 'attempts' => ['type' => 'integer'],
                        'error_code' => ['type' => 'string', 'nullable' => true],
                        'receipts' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => true]],
                    ]]],
            ]],
        ]],
        'FileImageVariant' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['variant_key', 'file_key', 'width', 'height', 'media_type', 'delivery_uri'],
            'properties' => [
                'variant_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,31}$'],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'width' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4096],
                'height' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4096],
                'media_type' => ['type' => 'string', 'enum' => ['image/jpeg', 'image/png']],
                'delivery_uri' => ['type' => 'string', 'nullable' => true],
            ],
        ],
        'FileAssetCandidate' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'file_key', 'original_name', 'media_type', 'width', 'height', 'preview_uri', 'variants'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'original_name' => ['type' => 'string', 'minLength' => 1],
                'media_type' => ['type' => 'string', 'minLength' => 1],
                'width' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true],
                'height' => ['type' => 'integer', 'minimum' => 1, 'nullable' => true],
                'preview_uri' => ['type' => 'string', 'nullable' => true],
                'variants' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileImageVariant']],
            ],
        ],
        'FileAssetListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileAssetCandidate']],
                ]],
                'meta' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['request_id', 'page', 'page_size', 'total'], 'properties' => [
                    'request_id' => ['type' => 'string', 'minLength' => 1],
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'page_size' => ['type' => 'integer', 'minimum' => 1],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                ]],
            ],
        ],
        'FileNonNegativeIntegerInput' => [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 0],
                ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]*)$'],
            ],
        ],
        'FilePositiveIntegerInput' => [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            ],
        ],
        'FileIdentifierRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
            'properties' => ['id' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput']],
        ],
        'FileIdsRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['ids'],
            'properties' => ['ids' => [
                'type' => 'array', 'minItems' => 1, 'uniqueItems' => true,
                'items' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput'],
            ]],
        ],
        'FileMoveRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['ids', 'cid'],
            'properties' => [
                'ids' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput']],
                'cid' => ['$ref' => '#/components/schemas/FileNonNegativeIntegerInput'],
            ],
        ],
        'FileRenameRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'],
            'properties' => [
                'id' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ],
        'FileCategoryCreateRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'name'],
            'properties' => [
                'pid' => ['$ref' => '#/components/schemas/FileNonNegativeIntegerInput'],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ],
        'FileCategoryUpdateRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'],
            'properties' => [
                'id' => ['$ref' => '#/components/schemas/FilePositiveIntegerInput'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ],
        'FileCategoryRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'pid', 'type', 'name', 'create_time', 'update_time', 'delete_time', 'tenant_id', 'children'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'pid' => ['type' => 'integer', 'minimum' => 0],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]],
                'name' => ['type' => 'string', 'maxLength' => 64],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
                'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileCategoryRecord']],
            ],
        ],
        'FileListRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'id', 'cid', 'source_id', 'source', 'type', 'name', 'create_time',
                'update_time', 'delete_time', 'tenant_id', 'file_key', 'url',
            ],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'cid' => ['type' => 'integer', 'minimum' => 0],
                'source_id' => ['type' => 'integer', 'minimum' => 0],
                'source' => ['type' => 'integer', 'enum' => [0, 1]],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]],
                'name' => ['type' => 'string'],
                'create_time' => ['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                'update_time' => ['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                'delete_time' => ['nullable' => true, 'oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'url' => ['type' => 'string'],
                'preview_state' => ['type' => 'string'],
                'can_download_original' => ['type' => 'boolean'],
                'verified_mime_type' => ['type' => 'string', 'nullable' => true],
                'source_sha256' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[0-9a-f]{64}$'],
                'capabilities' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true],
                'preview_unavailable_reason' => ['type' => 'string', 'nullable' => true],
                'authorization_revision' => ['type' => 'string', 'nullable' => true],
            ],
        ],
        'FileListPage' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileListRecord']],
                'count' => ['type' => 'integer', 'minimum' => 0],
                'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ],
        'FileListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => ['$ref' => '#/components/schemas/FileListPage'],
            ],
        ],
        'FileAccessResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['file_key'], 'properties' => [
                    'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                    'delivery_file_key' => ['type' => 'string'], 'source_file_key' => ['type' => 'string'],
                    'source_sha256' => ['type' => 'string'], 'media_type' => ['type' => 'string'],
                    'original_name' => ['type' => 'string'], 'revision' => ['type' => 'integer', 'minimum' => 1],
                    'can_manage' => ['type' => 'boolean'], 'can_download_original' => ['type' => 'boolean'],
                    'state' => ['type' => 'string'], 'purpose' => ['type' => 'string', 'enum' => ['preview', 'download_original']],
                    'bytes' => ['type' => 'string', 'enum' => ['original', 'derivative']],
                    'variant_key' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[a-z][a-z0-9-]{0,31}$', 'description' => 'The actual selected derivative variant; null for original bytes.'],
                    'authorization_revision' => ['type' => 'string'], 'expires_at' => ['type' => 'integer'],
                    'url' => ['type' => 'string', 'nullable' => true],
                    'capabilities' => ['type' => 'object', 'additionalProperties' => true],
                ]]],
        ],
        'FileUploadResult' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'cid', 'type', 'name', 'file_key', 'uri', 'url'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'cid' => ['type' => 'integer', 'minimum' => 0],
                'type' => ['type' => 'integer', 'enum' => [10, 20, 30]], 'name' => ['type' => 'string'],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'uri' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'], 'url' => ['type' => 'string'],
            ],
        ],
        'FileDeleteResult' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['files_deleted', 'storage_deleted'],
            'properties' => ['files_deleted' => ['type' => 'integer', 'minimum' => 0], 'storage_deleted' => ['type' => 'integer', 'minimum' => 0]],
        ],
        'FileCategoryDeleteResult' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['categories_deleted', 'files_deleted', 'storage_deleted'],
            'properties' => [
                'categories_deleted' => ['type' => 'integer', 'minimum' => 1],
                'files_deleted' => ['type' => 'integer', 'minimum' => 0],
                'storage_deleted' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'FileMutationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'maxItems' => 0]],
        ],
        'FileUploadResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/FileUploadResult']],
        ],
        'FileDeleteResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/FileDeleteResult']],
        ],
        'FileCategoryDeleteResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/FileCategoryDeleteResult']],
        ],
        'FileCategoryListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/FileCategoryRecord']],
            ],
        ],
    ]],
];
