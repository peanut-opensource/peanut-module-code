<?php

declare(strict_types=1);

/*
 * Article HTTP contract source.
 *
 * Request fields come from the controller CRUD_INPUT_FIELDS declarations and
 * ArticleValidate / ArticleCateValidate scenes. Response fields and stable
 * business error codes come from the two administration services. Keep this
 * fragment beside the Module; scripts/generate-api-contracts.php merges it
 * with the application contract and runtime route inventory.
 */

$schemaRef = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$responseRef = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];

$jsonBody = static fn(string $schema): array => [
    'required' => true,
    'content' => [
        'application/json' => [
            'schema' => ['$ref' => '#/components/schemas/' . $schema],
        ],
    ],
];

$successResponse = static fn(string $schema, string $description): array => [
    'description' => $description,
    'content' => [
        'application/json' => [
            'schema' => ['$ref' => '#/components/schemas/' . $schema],
        ],
    ],
];

$operation = static function (
    string $operationId,
    string $summary,
    string $responseSchema,
    array $parameters = [],
    ?string $requestSchema = null,
    array $businessErrors = [],
    array $errorStatuses = [],
) use ($jsonBody, $successResponse, $responseRef): array {
    $value = [
        'tags' => ['Article'],
        'operationId' => $operationId,
        'summary' => $summary,
        'parameters' => $parameters,
        'responses' => [
            '200' => $successResponse($responseSchema, $summary . '成功'),
            '401' => $responseRef('ArticleErrorResponse'),
            '403' => $responseRef('ArticleErrorResponse'),
        ],
    ];
    if ($requestSchema !== null) {
        $value['requestBody'] = $jsonBody($requestSchema);
    }
    foreach ($errorStatuses as $status) {
        $value['responses'][(string) $status] = $responseRef('ArticleErrorResponse');
    }
    if ($businessErrors !== []) {
        $value['x-peanut-errors'] = $businessErrors;
    }
    return $value;
};

$positiveIntegerInput = $schemaRef('ArticlePositiveIntegerInput');
$identifierParameter = static fn(string $description): array => [
    'in' => 'query',
    'name' => 'id',
    'required' => true,
    'description' => $description,
    'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput'],
    'example' => 12,
];

$listParameters = static function (string $textField, bool $withCategory = false): array {
    $parameters = [
        ['$ref' => '#/components/parameters/PageNo'],
        ['$ref' => '#/components/parameters/PageSize'],
        ['in' => 'query', 'name' => 'page_start', 'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput']],
        ['in' => 'query', 'name' => 'page_end', 'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput']],
        ['in' => 'query', 'name' => 'file_name', 'schema' => ['type' => 'string', 'maxLength' => 120]],
        ['in' => 'query', 'name' => 'page_type', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
        ['in' => 'query', 'name' => 'order_by', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
        ['in' => 'query', 'name' => 'field', 'schema' => ['type' => 'string', 'enum' => ['create_time', 'id']]],
        ['in' => 'query', 'name' => $textField, 'schema' => ['type' => 'string']],
        ['in' => 'query', 'name' => 'is_show', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
        ['in' => 'query', 'name' => 'start_time', 'schema' => ['type' => 'string'], 'example' => '2026-09-01 00:00:00'],
        ['in' => 'query', 'name' => 'end_time', 'schema' => ['type' => 'string'], 'example' => '2026-09-30 23:59:59'],
        ['in' => 'query', 'name' => 'start', 'schema' => ['type' => 'number']],
        ['in' => 'query', 'name' => 'end', 'schema' => ['type' => 'number']],
    ];
    if ($withCategory) {
        $parameters[] = ['in' => 'query', 'name' => 'cid', 'schema' => ['$ref' => '#/components/schemas/ArticlePositiveIntegerInput']];
    }
    return $parameters;
};

$categoryListParameters = $listParameters('name');
$articleListParameters = $listParameters('title', true);
$categoryListParameters[] = ['in' => 'query', 'name' => 'export', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]];
$articleListParameters[] = ['in' => 'query', 'name' => 'export', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]];
// export=1 returns filtered metadata; export=2 uses the same query and private XLSX storage.
// page_type=0 means ALL authorized rows (reject >25000); page_type=1 selects an explicit bounded range.

$categoryPermissionError = ['ARTICLE_CATEGORY_ADMIN_PERMISSION_DENIED'];
$articlePermissionError = ['ARTICLE_ADMIN_PERMISSION_DENIED'];

$bodyId = ['type' => 'integer', 'minimum' => 1, 'maximum' => 9007199254740991];
$bodySeq = ['type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991];
$bodyHash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
$bodyKey = ['type' => 'string', 'pattern' => '^[A-Za-z0-9:_-]{1,128}$'];
$bodyObject = static fn(array $properties, array $required): array => [
    'type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required,
];
$bodyEnvelope = static fn(array $data): array => $bodyObject([
    'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $data,
], ['code', 'msg', 'data']);
$bodyOwner = $bodyObject([
    'module_key' => ['type' => 'string', 'enum' => ['peanut.article']],
    'object_type' => ['type' => 'string', 'enum' => ['article']], 'object_id' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
    'slot' => ['type' => 'string', 'enum' => ['body']],
], ['module_key', 'object_type', 'object_id', 'slot']);
$bodyBinding = $bodyObject([
    'document_id' => $bodyId, 'revision_id' => $bodyId, 'source_state_seq' => $bodySeq, 'snapshot_sha256' => $bodyHash,
], ['document_id', 'revision_id', 'source_state_seq', 'snapshot_sha256']);
$bodySaved = $bodyObject([
    'article_id' => $bodyId, 'request_id' => $bodyKey, 'phase' => ['type' => 'string', 'enum' => ['completed']],
    'binding' => $bodyBinding, 'published' => ['type' => 'boolean', 'enum' => [false]],
], ['article_id', 'request_id', 'phase', 'binding', 'published']);
$bodySaveProperties = [
    'id' => $positiveIntegerInput, 'document_id' => $positiveIntegerInput,
    'expected_state_seq' => ['oneOf' => [$bodySeq, ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]*)$']]],
    'owner_revision' => $bodyHash, 'request_id' => $bodyKey, 'cid' => $positiveIntegerInput,
    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
    'desc' => ['type' => 'string', 'maxLength' => 255], 'abstract' => ['type' => 'string', 'maxLength' => 10000],
    'image' => ['type' => 'string', 'maxLength' => 2048], 'author' => ['type' => 'string', 'maxLength' => 255],
    'click_virtual' => ['oneOf' => [$bodySeq, ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]*)$']]],
    'is_show' => ['oneOf' => [['type' => 'integer', 'enum' => [0]], ['type' => 'string', 'enum' => ['0']]]],
    'sort' => ['oneOf' => [$bodySeq, ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]*)$']]],
];
$bodyEditor = $bodyObject([
    'document_id' => $bodyId, 'content_kind' => ['type' => 'string', 'enum' => ['rich_text']],
    'schema_version' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['active']],
    'state_seq' => $bodySeq, 'auth_revision' => $bodyId, 'owner' => $bodyOwner, 'authorization_revision' => $bodyHash,
    'collaboration_enabled' => ['type' => 'boolean'], 'structured_content' => $schemaRef('DocumentStructuredContent'),
    'digest' => $bodyHash, 'persisted_at' => ['type' => 'string'], 'can_edit' => ['type' => 'boolean', 'enum' => [true]],
    'can_download_original' => ['type' => 'boolean', 'enum' => [false]], 'capabilities' => $schemaRef('DocumentContentCapabilities'),
    'article_id' => $bodyId, 'owner_revision' => $bodyHash, 'saved_binding' => $bodyBinding + ['nullable' => true],
    'article_metadata' => $bodyObject([
        'cid' => $bodyId, 'title' => ['type' => 'string'], 'desc' => ['type' => 'string'],
        'abstract' => ['type' => 'string'], 'image' => ['type' => 'string', 'pattern' => '^(|file_[0-9a-f]{32})$'],
        'image_url' => ['type' => 'string', 'description' => 'Display delivery URL only; never a writable content reference.'], 'author' => ['type' => 'string'],
        'click_virtual' => $bodySeq, 'sort' => $bodySeq, 'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
    ], ['cid', 'title', 'desc', 'abstract', 'image', 'image_url', 'author', 'click_virtual', 'sort', 'is_show']),
], ['document_id', 'content_kind', 'schema_version', 'status', 'state_seq', 'auth_revision', 'owner', 'authorization_revision',
    'collaboration_enabled', 'structured_content', 'digest', 'persisted_at', 'can_edit', 'can_download_original', 'capabilities', 'article_id', 'owner_revision', 'saved_binding', 'article_metadata']);
$bodyTransport = $bodyObject([
    'document_id' => $bodyId, 'mode' => ['type' => 'string', 'enum' => ['write']], 'protocol' => ['type' => 'integer', 'enum' => [2]],
    'owner' => $bodyOwner, 'authorization_revision' => $bodyHash, 'document_name' => ['type' => 'string'],
    'article_id' => $bodyId, 'owner_revision' => $bodyHash, 'capabilities' => $schemaRef('DocumentContentCapabilities'),
    'wss_url' => ['type' => 'string', 'format' => 'uri', 'pattern' => '^wss?://[^/?#@]+:[0-9]+/collaboration$'],
], ['document_id', 'mode', 'protocol', 'owner', 'authorization_revision', 'document_name', 'article_id', 'owner_revision', 'capabilities', 'wss_url']);
$bodyOperation = $bodyObject([
    'article_id' => $bodyId, 'request_id' => $bodyKey, 'document_id' => $bodyId,
    'phase' => ['type' => 'string', 'enum' => ['prepared', 'completed', 'conflicted']], 'result' => $bodySaved + ['nullable' => true],
    'error_code' => ['type' => 'string', 'enum' => ['ARTICLE_BODY_SAVE_OWNER_CHANGED'], 'nullable' => true],
    'continuation' => ['type' => 'string', 'enum' => ['same_request_same_payload'], 'nullable' => true],
], ['article_id', 'request_id', 'document_id', 'phase', 'result', 'error_code', 'continuation']);

$articleContract = [
    'paths' => [
        '/adminapi/peanut.article.document.editor' => ['get' => $operation(
            'articleBodyEditor',
            '读取当前人员获准编辑的既有正文',
            'ArticleBodyEditorResponse',
            [$identifierParameter('资讯 ID')],
            errorStatuses: [404, 409, 422],
        )],
        '/adminapi/peanut.article.document.transport' => ['post' => $operation(
            'articleBodyTransport',
            '签发既有正文写票据（不含部署发现地址）',
            'ArticleBodyTransportResponse',
            requestSchema: 'ArticleIdentifierRequest',
            errorStatuses: [404, 409, 422, 503],
        )],
        '/adminapi/peanut.article.document.save' => ['post' => $operation(
            'saveArticleBody',
            '元数据与正文不可变版本原子绑定',
            'ArticleBodySaveResponse',
            requestSchema: 'ArticleBodySaveRequest',
            businessErrors: ['ARTICLE_BODY_SAVE_OWNER_CHANGED', 'ARTICLE_BODY_SAVE_REQUEST_CONFLICT', 'DOCUMENT_SAVE_STATE_CHANGED', 'ARTICLE_PUBLIC_FIXED_VERSION_UNAVAILABLE'],
            errorStatuses: [404, 409, 422, 503],
        )],
        '/adminapi/peanut.article.document.operation' => ['get' => $operation(
            'articleBodySaveOperation',
            '读取原人员的原保存请求；未完成时重放相同输入',
            'ArticleBodySaveOperationResponse',
            [$identifierParameter('资讯 ID'), ['in' => 'query', 'name' => 'request_id', 'required' => true, 'schema' => $bodyKey]],
            errorStatuses: [404, 409, 422],
        )],
        '/adminapi/peanut.article.document' => ['post' => [
            'tags' => ['Article'],'operationId' => 'ensureArticleBodyDocument',
            'requestBody' => ['required' => true,'content' => ['application/json' => ['schema' => [
                'type' => 'object','additionalProperties' => false,'required' => ['id','request_id'],
                'properties' => ['id' => ['type' => 'integer','minimum' => 1],'request_id' => ['type' => 'string','pattern' => '^[A-Za-z0-9:_-]{1,128}$']],
            ]]]],
            'responses' => ['200' => ['description' => 'Business-owned body document; no automatic conversion of legacy Article content.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DocumentMetadataResponse']]]],
                '401' => $responseRef('ArticleErrorResponse'),'403' => $responseRef('ArticleErrorResponse'),'404' => $responseRef('ArticleErrorResponse'),'409' => $responseRef('ArticleErrorResponse'),'422' => $responseRef('ArticleErrorResponse')],
        ]],
        '/adminapi/peanut.article.category.list' => [
            'get' => $operation(
                'listArticleCategories',
                '查询资讯分类',
                'ArticleCategoryPageResponse',
                $categoryListParameters,
                businessErrors: [...$categoryPermissionError, 'ARTICLE_EXPORT_RANGE_INVALID', 'ARTICLE_LIST_TIME_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.category.all' => [
            'get' => $operation(
                'listEnabledArticleCategories',
                '查询全部启用资讯分类',
                'ArticleCategoryCollectionResponse',
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/peanut.article.category.detail' => [
            'get' => $operation(
                'getArticleCategory',
                '查询资讯分类详情',
                'ArticleCategoryDetailResponse',
                [$identifierParameter('资讯分类 ID')],
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/peanut.article.category.add' => [
            'post' => $operation(
                'createArticleCategory',
                '创建资讯分类',
                'ArticleMutationResponse',
                requestSchema: 'ArticleCategoryCreateRequest',
                businessErrors: $categoryPermissionError,
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.category.edit' => [
            'post' => $operation(
                'updateArticleCategory',
                '更新资讯分类',
                'ArticleMutationResponse',
                requestSchema: 'ArticleCategoryUpdateRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/peanut.article.category.delete' => [
            'post' => $operation(
                'deleteArticleCategory',
                '软删除资讯分类',
                'ArticleMutationResponse',
                requestSchema: 'ArticleIdentifierRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_NOT_FOUND', 'ARTICLE_CATEGORY_IN_USE'],
                errorStatuses: [400, 404, 409],
            ),
        ],
        '/adminapi/peanut.article.category.update-status' => [
            'post' => $operation(
                'updateArticleCategoryStatus',
                '更新资讯分类启用状态',
                'ArticleMutationResponse',
                requestSchema: 'ArticleStatusRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/peanut.article.category.recycle.list' => [
            'get' => $operation(
                'listRecycledArticleCategories',
                '查询已删除资讯分类',
                'ArticleCategoryPageResponse',
                $categoryListParameters,
                businessErrors: [...$categoryPermissionError, 'ARTICLE_EXPORT_RANGE_INVALID', 'ARTICLE_LIST_TIME_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.category.recycle.detail' => [
            'get' => $operation(
                'getRecycledArticleCategory',
                '查询已删除资讯分类详情',
                'ArticleCategoryDetailResponse',
                [$identifierParameter('已删除资讯分类 ID')],
                businessErrors: $categoryPermissionError,
            ),
        ],
        '/adminapi/peanut.article.category.restore' => [
            'post' => $operation(
                'restoreArticleCategories',
                '恢复已删除资讯分类',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.category.force-delete' => [
            'post' => $operation(
                'forceDeleteArticleCategories',
                '永久删除资讯分类',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$categoryPermissionError, 'ARTICLE_CATEGORY_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.list' => [
            'get' => $operation(
                'listAdminArticles',
                '查询资讯',
                'ArticlePageResponse',
                $articleListParameters,
                businessErrors: [...$articlePermissionError, 'ARTICLE_EXPORT_RANGE_INVALID', 'ARTICLE_LIST_TIME_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.detail' => [
            'get' => $operation(
                'getAdminArticle',
                '查询资讯详情',
                'ArticleDetailResponse',
                [$identifierParameter('资讯 ID')],
                businessErrors: $articlePermissionError,
            ),
        ],
        '/adminapi/peanut.article.add' => [
            'post' => $operation(
                'createArticle',
                '创建资讯',
                'ArticleMutationResponse',
                requestSchema: 'ArticleCreateRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_CATEGORY_UNAVAILABLE'],
                errorStatuses: [400, 409],
            ),
        ],
        '/adminapi/peanut.article.edit' => [
            'post' => $operation(
                'updateArticle',
                '更新资讯',
                'ArticleMutationResponse',
                requestSchema: 'ArticleUpdateRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_NOT_FOUND', 'ARTICLE_CATEGORY_UNAVAILABLE'],
                errorStatuses: [400, 404, 409],
            ),
        ],
        '/adminapi/peanut.article.delete' => [
            'post' => $operation(
                'deleteArticle',
                '软删除资讯',
                'ArticleMutationResponse',
                requestSchema: 'ArticleIdentifierRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/peanut.article.update-status' => [
            'post' => $operation(
                'updateArticleStatus',
                '更新资讯展示状态',
                'ArticleMutationResponse',
                requestSchema: 'ArticleStatusRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_NOT_FOUND'],
                errorStatuses: [400, 404],
            ),
        ],
        '/adminapi/peanut.article.recycle.list' => [
            'get' => $operation(
                'listRecycledArticles',
                '查询已删除资讯',
                'ArticlePageResponse',
                $articleListParameters,
                businessErrors: [...$articlePermissionError, 'ARTICLE_EXPORT_RANGE_INVALID', 'ARTICLE_LIST_TIME_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.recycle.detail' => [
            'get' => $operation(
                'getRecycledArticle',
                '查询已删除资讯详情',
                'ArticleDetailResponse',
                [$identifierParameter('已删除资讯 ID')],
                businessErrors: $articlePermissionError,
            ),
        ],
        '/adminapi/peanut.article.restore' => [
            'post' => $operation(
                'restoreArticles',
                '恢复已删除资讯',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
        '/adminapi/peanut.article.force-delete' => [
            'post' => $operation(
                'forceDeleteArticles',
                '永久删除资讯',
                'ArticleBatchMutationResponse',
                requestSchema: 'ArticleBatchIdentifierRequest',
                businessErrors: [...$articlePermissionError, 'ARTICLE_BATCH_IDS_INVALID'],
                errorStatuses: [400],
            ),
        ],
    ],
    'components' => [
        'schemas' => [
            'ArticleBodySaveRequest' => $bodyObject($bodySaveProperties, ['id', 'document_id', 'expected_state_seq', 'owner_revision', 'request_id', 'cid', 'title', 'is_show']),
            'ArticleBodyEditorResponse' => $bodyEnvelope($bodyEditor),
            'ArticleBodyTransportResponse' => $bodyEnvelope($bodyTransport),
            'ArticleBodySaveResponse' => $bodyEnvelope($bodySaved),
            'ArticleBodySaveOperationResponse' => $bodyEnvelope($bodyOperation),
            'ArticlePositiveIntegerInput' => [
                'description' => '服务端接受正整数或仅含十进制数字的字符串。',
                'oneOf' => [
                    ['type' => 'integer', 'minimum' => 1],
                    ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                ],
            ],
            'ArticleIdentifierRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id'],
                'properties' => ['id' => $positiveIntegerInput],
                'example' => ['id' => 12],
            ],
            'ArticleBatchIdentifierRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'ids' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 100,
                        'uniqueItems' => true,
                        'items' => $positiveIntegerInput,
                    ],
                ],
                'oneOf' => [
                    ['required' => ['id'], 'not' => ['required' => ['ids']]],
                    ['required' => ['ids'], 'not' => ['required' => ['id']]],
                ],
                'example' => ['ids' => [12, 13]],
            ],
            'ArticleStatusRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'is_show'],
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                ],
                'example' => ['id' => 12, 'is_show' => 1],
            ],
            'ArticleCategoryCreateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['name', 'is_show'],
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 90],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
                'example' => ['name' => '产品动态', 'is_show' => 1, 'sort' => 10],
            ],
            'ArticleCategoryUpdateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'is_show'],
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 90],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ArticleCreateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['title', 'cid', 'is_show'],
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'cid' => $positiveIntegerInput,
                    'desc' => ['type' => 'string', 'maxLength' => 255],
                    'abstract' => ['type' => 'string', 'maxLength' => 10000],
                    'image' => ['type' => 'string', 'maxLength' => 2048],
                    'author' => ['type' => 'string', 'maxLength' => 255],
                    'content' => ['type' => 'string', 'maxLength' => 1000000],
                    'click_virtual' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
                'example' => [
                    'title' => '多租户产品动态',
                    'cid' => 3,
                    'abstract' => '资讯摘要',
                    'content' => '<p>正文</p>',
                    'is_show' => 1,
                    'sort' => 10,
                ],
            ],
            'ArticleUpdateRequest' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'title', 'cid', 'is_show'],
                'properties' => [
                    'id' => $positiveIntegerInput,
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'cid' => $positiveIntegerInput,
                    'desc' => ['type' => 'string', 'maxLength' => 255],
                    'abstract' => ['type' => 'string', 'maxLength' => 10000],
                    'image' => ['type' => 'string', 'maxLength' => 2048],
                    'author' => ['type' => 'string', 'maxLength' => 255],
                    'content' => ['type' => 'string', 'maxLength' => 1000000],
                    'click_virtual' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ArticleCategoryRecord' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'name', 'sort', 'is_show', 'create_time', 'update_time', 'delete_time'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string'],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'create_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'update_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'delete_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'article_count' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ArticleRecord' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'id', 'cid', 'title', 'desc', 'abstract', 'image', 'author', 'content',
                    'click_virtual', 'click_actual', 'click', 'is_show', 'sort', 'cate_name',
                    'create_time', 'update_time', 'delete_time',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'cid' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'],
                    'desc' => ['type' => 'string'],
                    'abstract' => ['type' => 'string'],
                    'image' => ['type' => 'string'],
                    'author' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'click_virtual' => ['type' => 'integer', 'minimum' => 0],
                    'click_actual' => ['type' => 'integer', 'minimum' => 0],
                    'click' => ['type' => 'integer', 'minimum' => 0],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                    'sort' => ['type' => 'integer', 'minimum' => 0],
                    'cate_name' => ['type' => 'string'],
                    'create_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'update_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                    'delete_time' => ['$ref' => '#/components/schemas/ArticleTimestamp'],
                ],
            ],
            'ArticleTimestamp' => [
                'type' => 'string',
                'description' => '空字符串或服务端格式化的本地日期时间。',
                'pattern' => '^$|^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
            ],
            'ArticlePage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schemaRef('ArticleRecord')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'ArticleCategoryPage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schemaRef('ArticleCategoryRecord')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'ArticleBatchFailure' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['id', 'code', 'message'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'code' => ['type' => 'string', 'pattern' => '^ARTICLE_[A-Z0-9_]+$'],
                    'message' => ['type' => 'string'],
                ],
            ],
            'ArticleBatchMutationResult' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['requested', 'already_active', 'failed'],
                'properties' => [
                    'requested' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'restored' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'deleted' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'already_active' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                    'failed' => ['type' => 'array', 'items' => $schemaRef('ArticleBatchFailure')],
                ],
            ],
            'ArticleMutationResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'maxItems' => 0],
                ],
            ],
            'ArticlePageResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => ['oneOf' => [$schemaRef('ArticlePage'), $schemaRef('ExportPageInfo'), $schemaRef('ExportFile')]],
                ],
            ],
            'ArticleCategoryPageResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => ['oneOf' => [$schemaRef('ArticleCategoryPage'), $schemaRef('ExportPageInfo'), $schemaRef('ExportFile')]],
                ],
            ],
            'ArticleDetailResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => [
                        'oneOf' => [
                            $schemaRef('ArticleDetailRecord'),
                            ['type' => 'array', 'maxItems' => 0],
                        ],
                    ],
                ],
            ],
            'ArticleCategoryDetailResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => [
                        'oneOf' => [
                            $schemaRef('ArticleCategoryRecord'),
                            ['type' => 'array', 'maxItems' => 0],
                        ],
                    ],
                ],
            ],
            'ArticleCategoryCollectionResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => ['type' => 'array', 'items' => $schemaRef('ArticleCategoryRecord')],
                ],
            ],
            'ArticleBatchMutationResponse' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string', 'enum' => ['success']],
                    'data' => $schemaRef('ArticleBatchMutationResult'),
                ],
            ],
            'ArticleErrorEnvelope' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'minimum' => 40000, 'maximum' => 59999],
                    'msg' => ['type' => 'string'],
                    'data' => [
                        'type' => 'object',
                        'required' => ['error_code'],
                        'properties' => [
                            'error_code' => ['type' => 'string', 'pattern' => '^[A-Z][A-Z0-9_]+$'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'example' => [
                    'code' => 40900,
                    'msg' => '仅回收站资讯可永久删除',
                    'data' => ['error_code' => 'ARTICLE_FORCE_DELETE_REQUIRES_TRASHED'],
                ],
            ],
        ],
        'responses' => [
            'ArticleErrorResponse' => [
                'description' => '统一 API 错误；X-Request-Id 响应头用于追踪。',
                'headers' => [
                    'X-Request-Id' => [
                        'description' => '请求追踪 ID',
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'content' => [
                    'application/json' => [
                        'schema' => $schemaRef('ArticleErrorEnvelope'),
                    ],
                ],
            ],
        ],
    ],
];
$articleContract['components']['schemas']['ArticleDetailRecord'] = $articleContract['components']['schemas']['ArticleRecord'];
$articleContract['components']['schemas']['ArticleDetailRecord']['properties']['body_document_id'] = ['type' => 'integer', 'minimum' => 1, 'nullable' => true];
$articleContract['components']['schemas']['ArticleDetailRecord']['required'][] = 'body_document_id';
return $articleContract;
