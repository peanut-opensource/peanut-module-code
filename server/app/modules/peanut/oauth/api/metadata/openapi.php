<?php

declare(strict_types=1);

/*
 * OAuth HTTP contract source.
 *
 * The JSON contracts below follow the route controllers, validators,
 * application services and DTO projections.  The two official-account
 * callbacks and the browser bridges deliberately describe their native
 * text/XML/redirect responses instead of the application's JSON envelope.
 */

$schema = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$response = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];
$error = $response('ErrorResponse');
$body = static fn(string $name, string $mediaType = 'application/json'): array => [
    'required' => true,
    'content' => [$mediaType => ['schema' => ['$ref' => '#/components/schemas/' . $name]]],
];
$ok = static fn(string $name, string $description): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $name]]],
];
$operation = static function (
    string $id,
    string $summary,
    string $responseSchema,
    string $audience,
    array $parameters = [],
    ?string $requestSchema = null,
    array $errors = [],
    array $statuses = [],
) use ($body, $ok, $error): array {
    $value = [
        'tags' => ['OAuth'],
        'operationId' => $id,
        'summary' => $summary,
        'parameters' => $parameters,
        'responses' => ['200' => $ok($responseSchema, $summary . '成功')],
        'x-peanut-audience' => $audience,
    ];
    if ($requestSchema !== null) {
        $value['requestBody'] = $body($requestSchema);
    }
    foreach ($statuses as $status) {
        $value['responses'][(string) $status] = $error;
    }
    if ($errors !== []) {
        $value['x-peanut-errors'] = $errors;
    }
    return $value;
};
$envelope = static fn(array $data): array => [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['code', 'msg', 'data'],
    'properties' => [
        'code' => ['type' => 'integer', 'enum' => [20000]],
        'msg' => ['type' => 'string'],
        'data' => $data,
    ],
];
$idQuery = [
    'in' => 'query', 'name' => 'id', 'required' => true,
    'schema' => ['$ref' => '#/components/schemas/OAuthPositiveIntegerInput'],
];
$callbackBinding = [
    'in' => 'path', 'name' => 'binding', 'required' => true,
    'schema' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
];
$signatureQuery = [
    ['in' => 'query', 'name' => 'signature', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
    ['in' => 'query', 'name' => 'timestamp', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
    ['in' => 'query', 'name' => 'nonce', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
];
$redirectQuery = [
    ['in' => 'query', 'name' => 'code', 'schema' => ['type' => 'string']],
    ['in' => 'query', 'name' => 'state', 'schema' => ['type' => 'string']],
    ['in' => 'query', 'name' => 'error', 'schema' => ['type' => 'string']],
    ['in' => 'query', 'name' => 'error_description', 'schema' => ['type' => 'string']],
];
$redirectResponse = [
    'description' => '重定向到对应 PC/H5 OAuth 回调页。仅转发 code、state、error 和 error_description。',
    'headers' => [
        'Location' => ['required' => true, 'schema' => ['type' => 'string']],
    ],
];
$textForbidden = [
    'description' => '回调绑定、签名、模块状态或消息校验失败。',
    'content' => ['text/plain' => ['schema' => ['type' => 'string', 'enum' => ['callback rejected']]]],
];

return [
    'paths' => [
        '/adminapi/peanut.oauth.web-page.config' => ['get' => $operation(
            'getOAuthWebPageConfig',
            '查询 H5 网页渠道配置',
            'OAuthWebPageConfigResponse',
            'tenant_admin',
            statuses: [401, 403, 503],
        )],
        '/adminapi/peanut.oauth.web-page.save' => ['post' => $operation(
            'replaceOAuthWebPageConfig',
            '保存 H5 网页渠道配置',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthWebPageConfigRequest',
            statuses: [400, 401, 403, 422, 503],
        )],
        '/adminapi/peanut.oauth.mini-program.config' => ['get' => $operation(
            'getOAuthMiniProgramConfig',
            '查询微信小程序配置',
            'OAuthMiniProgramConfigResponse',
            'tenant_admin',
            statuses: [401, 403, 503],
        )],
        '/adminapi/peanut.oauth.mini-program.save' => ['post' => $operation(
            'replaceOAuthMiniProgramConfig',
            '保存微信小程序配置',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthMiniProgramConfigRequest',
            errors: ['OAUTH_APP_SECRET_REQUIRED'],
            statuses: [400, 401, 403, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.config' => ['get' => $operation(
            'getOAuthOfficialAccountConfig',
            '查询微信公众号配置',
            'OAuthOfficialAccountConfigResponse',
            'tenant_admin',
            statuses: [401, 403, 503],
        )],
        '/adminapi/peanut.oauth.official-account.save' => ['post' => $operation(
            'replaceOAuthOfficialAccountConfig',
            '保存微信公众号配置',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthOfficialAccountConfigRequest',
            errors: ['OAUTH_APP_SECRET_REQUIRED'],
            statuses: [400, 401, 403, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.menu.detail' => ['get' => $operation(
            'getOAuthOfficialAccountMenu',
            '查询微信公众号菜单',
            'OAuthMenuResponse',
            'tenant_admin',
            statuses: [401, 403, 503],
        )],
        '/adminapi/peanut.oauth.official-account.menu.save' => ['post' => $operation(
            'saveOAuthOfficialAccountMenu',
            '保存微信公众号菜单',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthMenuRequest',
            statuses: [400, 401, 403, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.menu.publish' => ['post' => $operation(
            'publishOAuthOfficialAccountMenu',
            '保存并发布微信公众号菜单',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthMenuRequest',
            statuses: [400, 401, 403, 422, 500, 503],
        )],
        '/adminapi/peanut.oauth.official-account.reply.list' => ['get' => $operation(
            'listOAuthOfficialAccountReplies',
            '查询微信公众号自动回复',
            'OAuthReplyPageResponse',
            'tenant_admin',
            [
                ['$ref' => '#/components/parameters/PageNo'],
                ['in' => 'query', 'name' => 'page_size', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15]],
                ['in' => 'query', 'name' => 'page', 'deprecated' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['in' => 'query', 'name' => 'limit', 'deprecated' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ['in' => 'query', 'name' => 'reply_type', 'schema' => ['type' => 'integer', 'enum' => [1, 2, 3]]],
            ],
            statuses: [400, 401, 403, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.reply.detail' => ['get' => $operation(
            'getOAuthOfficialAccountReply',
            '查询微信公众号自动回复详情',
            'OAuthReplyDetailResponse',
            'tenant_admin',
            [$idQuery],
            errors: ['ADMIN_RESOURCE_NOT_FOUND'],
            statuses: [400, 401, 403, 404, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.reply.add' => ['post' => $operation(
            'createOAuthOfficialAccountReply',
            '创建微信公众号自动回复',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthReplyCreateRequest',
            statuses: [400, 401, 403, 409, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.reply.edit' => ['post' => $operation(
            'updateOAuthOfficialAccountReply',
            '更新微信公众号自动回复',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthReplyUpdateRequest',
            errors: ['OAUTH_REPLY_NOT_FOUND'],
            statuses: [400, 401, 403, 404, 409, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.reply.delete' => ['post' => $operation(
            'deleteOAuthOfficialAccountReply',
            '删除微信公众号自动回复',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthIdentifierRequest',
            errors: ['OAUTH_REPLY_NOT_FOUND'],
            statuses: [400, 401, 403, 404, 422, 503],
        )],
        '/adminapi/peanut.oauth.official-account.reply.update-status' => ['post' => $operation(
            'updateOAuthOfficialAccountReplyStatus',
            '更新微信公众号自动回复状态',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthReplyStatusRequest',
            errors: ['OAUTH_REPLY_NOT_FOUND'],
            statuses: [400, 401, 403, 404, 409, 422, 503],
        )],
        '/adminapi/peanut.oauth.open-platform.config' => ['get' => $operation(
            'getOAuthOpenPlatformConfig',
            '查询微信开放平台配置',
            'OAuthOpenPlatformConfigResponse',
            'tenant_admin',
            statuses: [401, 403, 503],
        )],
        '/adminapi/peanut.oauth.open-platform.save' => ['post' => $operation(
            'replaceOAuthOpenPlatformConfig',
            '保存微信开放平台配置',
            'OAuthMutationResponse',
            'tenant_admin',
            requestSchema: 'OAuthOpenPlatformConfigRequest',
            errors: ['OAUTH_APP_SECRET_REQUIRED'],
            statuses: [400, 401, 403, 422, 503],
        )],

        '/api/oauth/wechat/begin' => ['post' => $operation(
            'beginWechatOAuth',
            '发起微信浏览器授权',
            'OAuthAuthorizationResponse',
            'public_external_callback',
            requestSchema: 'OAuthBeginRequest',
            errors: ['OAUTH_SCENE_UNSUPPORTED', 'OAUTH_RETURN_PATH_INVALID'],
            statuses: [400, 403, 409, 422, 500, 503],
        )],
        '/api/oauth/wechat/callback' => ['post' => $operation(
            'completeWechatOAuthCallback',
            '处理微信浏览器授权回调',
            'OAuthLoginResponse',
            'public_external_callback',
            requestSchema: 'OAuthCallbackRequest',
            errors: [
                'OAUTH_SCENE_INVALID', 'OAUTH_STATE_REQUIRED', 'OAUTH_STATE_INVALID', 'MEMBER_DISABLED',
                'OAUTH_MEMBER_NOT_FOUND', 'OAUTH_PRINCIPAL_OWNERSHIP_CONFLICT', 'OAUTH_CLIENT_IDENTITY_EXISTS',
                'OAUTH_LOGIN_BUSY',
            ],
            statuses: [400, 403, 404, 409, 422, 500, 503],
        )],
        '/api/oauth/wechat/mini-program' => ['post' => $operation(
            'loginWithWechatMiniProgram',
            '微信小程序登录',
            'OAuthLoginResponse',
            'public_external_callback',
            requestSchema: 'OAuthMiniProgramLoginRequest',
            errors: [
                'MEMBER_DISABLED', 'OAUTH_MEMBER_NOT_FOUND', 'OAUTH_PRINCIPAL_OWNERSHIP_CONFLICT',
                'OAUTH_CLIENT_IDENTITY_EXISTS', 'OAUTH_LOGIN_BUSY',
            ],
            statuses: [400, 403, 404, 409, 422, 500, 503],
        )],
        '/api/oauth/wechat/complete' => ['post' => $operation(
            'completeWechatOAuthProfile',
            '补全微信登录资料',
            'OAuthLoginResponse',
            'public_external_callback',
            requestSchema: 'OAuthCompletionRequest',
            errors: [
                'OAUTH_COMPLETION_TICKET_REQUIRED', 'OAUTH_COMPLETION_TICKET_INVALID', 'MEMBER_UNAVAILABLE',
                'MEMBER_NICKNAME_INVALID', 'MEMBER_MOBILE_INVALID', 'MEMBER_VERIFICATION_REJECTED', 'MEMBER_NOT_FOUND',
            ],
            statuses: [400, 403, 404, 409, 422, 500, 503],
        )],
        '/api/oauth/wechat/redirect/pc' => ['get' => [
            'tags' => ['OAuth'], 'operationId' => 'redirectWechatOAuthToPc',
            'summary' => '重定向微信授权结果到 PC 客户端', 'parameters' => $redirectQuery,
            'responses' => ['302' => $redirectResponse], 'x-peanut-audience' => 'public_external_callback',
        ]],
        '/api/oauth/wechat/redirect/official-account' => ['get' => [
            'tags' => ['OAuth'], 'operationId' => 'redirectWechatOAuthToOfficialAccount',
            'summary' => '重定向微信授权结果到公众号 H5 客户端', 'parameters' => $redirectQuery,
            'responses' => ['302' => $redirectResponse], 'x-peanut-audience' => 'public_external_callback',
        ]],
        '/api/oauth/wechat/bind' => ['post' => $operation(
            'bindWechatIdentity',
            '绑定当前会员的微信身份',
            'OAuthMutationResponse',
            'member',
            requestSchema: 'OAuthBindRequest',
            errors: [
                'OAUTH_BIND_SCENE_UNSUPPORTED', 'MEMBER_UNAVAILABLE', 'OAUTH_IDENTITY_ALREADY_BOUND',
                'OAUTH_PRINCIPAL_OWNERSHIP_CONFLICT', 'OAUTH_CLIENT_IDENTITY_EXISTS', 'OAUTH_LOGIN_BUSY',
            ],
            statuses: [400, 401, 403, 404, 409, 422, 503],
        )],
        '/api/wechat/official-account/callback/{binding}' => [
            'get' => [
                'tags' => ['OAuth'], 'operationId' => 'verifyWechatOfficialAccountCallback',
                'summary' => '验证微信公众号回调地址',
                'parameters' => [$callbackBinding, ...$signatureQuery, [
                    'in' => 'query', 'name' => 'echostr', 'required' => true, 'schema' => ['type' => 'string'],
                ]],
                'responses' => [
                    '200' => [
                        'description' => '原样返回微信提供的 echostr。',
                        'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                    ],
                    '403' => $textForbidden,
                ],
                'x-peanut-audience' => 'public_external_callback',
            ],
            'post' => [
                'tags' => ['OAuth'], 'operationId' => 'receiveWechatOfficialAccountMessage',
                'summary' => '接收微信公众号明文消息',
                'parameters' => [$callbackBinding, ...$signatureQuery, [
                    'in' => 'query', 'name' => 'encrypt_type', 'schema' => ['type' => 'string'],
                    'description' => '当前实现拒绝值 aes，仅支持明文模式。',
                ]],
                'requestBody' => $body('OAuthOfficialAccountMessageXml', 'application/xml'),
                'responses' => [
                    '200' => [
                        'description' => '无回复时返回 success；有文本回复时返回公众号 XML。',
                        'content' => [
                            'text/plain' => ['schema' => ['type' => 'string', 'enum' => ['success']]],
                            'application/xml' => ['schema' => ['$ref' => '#/components/schemas/OAuthOfficialAccountMessageXml']],
                        ],
                    ],
                    '403' => $textForbidden,
                ],
                'x-peanut-audience' => 'public_external_callback',
                'x-peanut-errors' => ['OFFICIAL_ACCOUNT_MESSAGE_INVALID'],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'OAuthPositiveIntegerInput' => [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            ],
        ],
        'OAuthEmptyData' => ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']],
        'OAuthIdentifierRequest' => [
            'type' => 'object', 'required' => ['id'],
            'description' => '当前旧校验入口保留未知字段，但业务只消费 id，其他字段会被忽略。',
            'properties' => ['id' => $schema('OAuthPositiveIntegerInput')],
        ],
        'OAuthWebPageConfigRequest' => [
            'type' => 'object', 'required' => ['status', 'page_status'],
            'description' => 'page_status=1 时 page_url 必填且必须为 http/https 绝对地址。未列出的字段会被当前服务忽略。',
            'properties' => [
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
                'page_status' => ['type' => 'integer', 'enum' => [0, 1]],
                'page_url' => ['type' => 'string', 'format' => 'uri'],
            ],
        ],
        'OAuthWebPageConfig' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['status', 'page_status', 'page_url', 'url'],
            'properties' => [
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
                'page_status' => ['type' => 'integer', 'enum' => [0, 1]],
                'page_url' => ['type' => 'string'],
                'url' => ['type' => 'string'],
            ],
        ],
        'OAuthMiniProgramConfigRequest' => [
            'type' => 'object', 'required' => ['app_id', 'app_secret'],
            'description' => 'app_secret 可传 ****** 保留已有秘密；未列出的字段会被当前服务忽略。',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 100],
                'original_id' => ['type' => 'string', 'maxLength' => 100],
                'qr_code' => ['type' => 'string', 'maxLength' => 255],
                'app_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                'app_secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'format' => 'password', 'writeOnly' => true],
            ],
        ],
        'OAuthMiniProgramConfig' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'name', 'original_id', 'qr_code', 'app_id', 'app_secret', 'app_secret_configured',
                'request_domain', 'socket_domain', 'upload_file_domain', 'download_file_domain', 'udp_domain', 'business_domain',
            ],
            'properties' => [
                'name' => ['type' => 'string'], 'original_id' => ['type' => 'string'],
                'qr_code' => ['type' => 'string'], 'app_id' => ['type' => 'string'],
                'app_secret' => ['type' => 'string', 'description' => '已配置时固定返回 ******，否则返回空字符串。', 'readOnly' => true],
                'app_secret_configured' => ['type' => 'boolean'],
                'request_domain' => ['type' => 'string'], 'socket_domain' => ['type' => 'string'],
                'upload_file_domain' => ['type' => 'string'], 'download_file_domain' => ['type' => 'string'],
                'udp_domain' => ['type' => 'string'], 'business_domain' => ['type' => 'string'],
            ],
        ],
        'OAuthOfficialAccountConfigRequest' => [
            'type' => 'object', 'required' => ['app_id', 'app_secret'],
            'description' => 'app_secret 与 token 可传 ****** 保留已有秘密；token 传空字符串表示清除。未列出的字段会被当前服务忽略。',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 100],
                'original_id' => ['type' => 'string', 'maxLength' => 100],
                'qr_code' => ['type' => 'string', 'maxLength' => 255],
                'app_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                'app_secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'format' => 'password', 'writeOnly' => true],
                'token' => [
                    'type' => 'string', 'maxLength' => 255, 'format' => 'password', 'writeOnly' => true,
                    'description' => '****** 表示保留已存 Token，空字符串表示清除。',
                ],
            ],
        ],
        'OAuthOfficialAccountConfig' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'name', 'original_id', 'qr_code', 'app_id', 'app_secret', 'app_secret_configured',
                'url', 'token', 'token_configured',
                'business_domain', 'js_secure_domain', 'web_auth_domain', 'callback_mode',
            ],
            'properties' => [
                'name' => ['type' => 'string'], 'original_id' => ['type' => 'string'],
                'qr_code' => ['type' => 'string'], 'app_id' => ['type' => 'string'],
                'app_secret' => [
                    'type' => 'string', 'enum' => ['', '******'],
                    'description' => '已配置时固定返回 ******，否则返回空字符串。', 'readOnly' => true,
                ],
                'app_secret_configured' => ['type' => 'boolean'], 'url' => ['type' => 'string'],
                'token' => [
                    'type' => 'string', 'enum' => ['', '******'],
                    'description' => '已配置时固定返回 ******，否则返回空字符串。', 'readOnly' => true,
                ],
                'token_configured' => ['type' => 'boolean'],
                'business_domain' => ['type' => 'string'], 'js_secure_domain' => ['type' => 'string'],
                'web_auth_domain' => ['type' => 'string'], 'callback_mode' => ['type' => 'string', 'enum' => ['plaintext']],
            ],
        ],
        'OAuthOpenPlatformConfigRequest' => [
            'type' => 'object', 'required' => ['app_id', 'app_secret'],
            'description' => 'app_secret 可传 ****** 保留已有秘密；未列出的字段会被当前服务忽略。',
            'properties' => [
                'app_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                'app_secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'format' => 'password', 'writeOnly' => true],
            ],
        ],
        'OAuthOpenPlatformConfig' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['app_id', 'app_secret', 'app_secret_configured'],
            'properties' => [
                'app_id' => ['type' => 'string'],
                'app_secret' => ['type' => 'string', 'description' => '已配置时固定返回 ******，否则返回空字符串。', 'readOnly' => true],
                'app_secret_configured' => ['type' => 'boolean'],
            ],
        ],
        'OAuthMenuRequest' => [
            'type' => 'object', 'required' => ['menu'],
            'description' => '顶层未知字段会被业务忽略；menu 节点内的扩展 JSON 字段会完整持久化并由详情接口返回。',
            'properties' => [
                'menu' => ['type' => 'array', 'maxItems' => 3, 'items' => $schema('OAuthMenuNode')],
            ],
        ],
        'OAuthMenuNode' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/OAuthMenuParentNode'],
                ['$ref' => '#/components/schemas/OAuthMenuTopClickNode'],
                ['$ref' => '#/components/schemas/OAuthMenuTopViewNode'],
                ['$ref' => '#/components/schemas/OAuthMenuTopMiniProgramNode'],
            ],
        ],
        'OAuthMenuParentNode' => [
            'type' => 'object', 'required' => ['name', 'sub_button'],
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费 name 和 sub_button。',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                'sub_button' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/OAuthMenuClickNode'],
                        ['$ref' => '#/components/schemas/OAuthMenuViewNode'],
                        ['$ref' => '#/components/schemas/OAuthMenuMiniProgramNode'],
                    ],
                ]],
            ],
        ],
        'OAuthMenuTopClickNode' => [
            'type' => 'object', 'required' => ['name', 'type', 'key'],
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费 name、type 和 key。',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                'type' => ['type' => 'string', 'enum' => ['click']], 'key' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
        'OAuthMenuTopViewNode' => [
            'type' => 'object', 'required' => ['name', 'type', 'url'],
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费 name、type 和 url。',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                'type' => ['type' => 'string', 'enum' => ['view']], 'url' => ['type' => 'string', 'format' => 'uri'],
            ],
        ],
        'OAuthMenuTopMiniProgramNode' => [
            'type' => 'object',
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费列出的微信标准字段。',
            'required' => ['name', 'type', 'url', 'appid', 'pagepath'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                'type' => ['type' => 'string', 'enum' => ['miniprogram']],
                'url' => ['type' => 'string', 'format' => 'uri'], 'appid' => ['type' => 'string', 'minLength' => 1],
                'pagepath' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
        'OAuthMenuClickNode' => [
            'type' => 'object', 'required' => ['name', 'type', 'key'],
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费 name、type 和 key。',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                'type' => ['type' => 'string', 'enum' => ['click']], 'key' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
        'OAuthMenuViewNode' => [
            'type' => 'object', 'required' => ['name', 'type', 'url'],
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费 name、type 和 url。',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                'type' => ['type' => 'string', 'enum' => ['view']], 'url' => ['type' => 'string', 'format' => 'uri'],
            ],
        ],
        'OAuthMenuMiniProgramNode' => [
            'type' => 'object',
            'additionalProperties' => $schema('OAuthMenuExtensionValue'),
            'description' => '保存和详情会保留额外 JSON 字段；发布到微信 Provider 时只消费列出的微信标准字段。',
            'required' => ['name', 'type', 'url', 'appid', 'pagepath'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                'type' => ['type' => 'string', 'enum' => ['miniprogram']],
                'url' => ['type' => 'string', 'format' => 'uri'], 'appid' => ['type' => 'string', 'minLength' => 1],
                'pagepath' => ['type' => 'string', 'minLength' => 1],
            ],
        ],
        'OAuthReplyCreateRequest' => [
            'type' => 'object', 'required' => ['reply_type', 'name', 'content_type', 'content', 'status'],
            'description' => 'reply_type=2 时 keyword、matching_type 和非负 sort 必填；未列出的字段会被当前服务忽略。',
            'properties' => [
                'reply_type' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'keyword' => ['type' => 'string', 'maxLength' => 255],
                'matching_type' => ['type' => 'integer', 'enum' => [1, 2]],
                'content_type' => ['type' => 'integer', 'enum' => [1]],
                'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
                'sort' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'OAuthReplyUpdateRequest' => [
            'type' => 'object', 'required' => ['id', 'reply_type', 'name', 'content_type', 'content', 'status'],
            'description' => 'reply_type=2 时 keyword、matching_type 和非负 sort 必填；未列出的字段会被当前服务忽略。',
            'properties' => [
                'id' => $schema('OAuthPositiveIntegerInput'),
                'reply_type' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'keyword' => ['type' => 'string', 'maxLength' => 255],
                'matching_type' => ['type' => 'integer', 'enum' => [1, 2]],
                'content_type' => ['type' => 'integer', 'enum' => [1]],
                'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
                'sort' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'OAuthReplyStatusRequest' => [
            'type' => 'object', 'required' => ['id', 'status'],
            'description' => '当前旧校验入口保留未知字段，但业务只消费 id 和 status，其他字段会被忽略。',
            'properties' => [
                'id' => $schema('OAuthPositiveIntegerInput'), 'status' => ['type' => 'integer', 'enum' => [0, 1]],
            ],
        ],
        'OAuthReplyRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'id', 'name', 'keyword', 'reply_type', 'matching_type', 'content_type', 'content',
                'status', 'sort', 'create_time', 'update_time',
            ],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string'],
                'keyword' => ['type' => 'string'], 'reply_type' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'matching_type' => ['type' => 'integer', 'enum' => [1, 2]],
                'content_type' => ['type' => 'integer', 'enum' => [1]], 'content' => ['type' => 'string'],
                'status' => ['type' => 'integer', 'enum' => [0, 1]], 'sort' => ['type' => 'integer', 'minimum' => 0],
                'create_time' => ['type' => 'integer', 'minimum' => 0], 'update_time' => ['type' => 'integer', 'minimum' => 0],
                'delete_time' => ['type' => 'integer', 'minimum' => 0], 'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'singleton_active_key' => ['type' => 'integer', 'nullable' => true],
            ],
        ],
        'OAuthBeginRequest' => [
            'type' => 'object', 'required' => ['scene', 'return_path'],
            'description' => '当前旧校验入口保留未知字段，但业务只消费列出的字段，其他字段会被忽略。',
            'properties' => [
                'scene' => ['type' => 'string', 'enum' => ['oa', 'open_pc']],
                'return_path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'pattern' => '^/(?!/)(?!.*\\\\).*$'],
                'client_id' => ['type' => 'string', 'maxLength' => 191],
            ],
        ],
        'OAuthCallbackRequest' => [
            'type' => 'object', 'required' => ['scene', 'code', 'state'],
            'description' => '当前旧校验入口保留未知字段，但业务只消费列出的字段，其他字段会被忽略。',
            'properties' => [
                'scene' => ['type' => 'string', 'enum' => ['oa', 'open_pc']],
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048],
                'state' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
        ],
        'OAuthMiniProgramLoginRequest' => [
            'type' => 'object', 'required' => ['code'],
            'description' => '当前旧校验入口保留未知字段，但业务只消费 code 和 client_id，其他字段会被忽略。',
            'properties' => [
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048],
                'client_id' => ['type' => 'string', 'maxLength' => 191],
            ],
        ],
        'OAuthCompletionRequest' => [
            'type' => 'object', 'required' => ['ticket'],
            'description' => 'need_profile/need_mobile 由票据决定；相应字段仅在服务要求时必须有效。当前旧校验入口保留未知字段，但补全业务不会消费其他字段。',
            'properties' => [
                'ticket' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'format' => 'password', 'writeOnly' => true],
                'nickname' => ['type' => 'string', 'maxLength' => 50], 'avatar' => ['type' => 'string', 'maxLength' => 1000],
                'mobile' => ['type' => 'string', 'maxLength' => 20],
                'verification_code' => ['type' => 'string', 'maxLength' => 12, 'format' => 'password', 'writeOnly' => true],
            ],
        ],
        'OAuthBindRequest' => [
            'type' => 'object', 'required' => ['scene', 'code'],
            'description' => '当前旧校验入口保留未知字段，但业务只消费 scene 和 code，其他字段会被忽略。',
            'properties' => [
                'scene' => ['type' => 'string', 'enum' => ['mnp', 'oa']],
                'code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048, 'format' => 'password', 'writeOnly' => true],
            ],
        ],
        'OAuthAuthorizationData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['authorization_url', 'expires_in'],
            'properties' => [
                'authorization_url' => ['type' => 'string', 'format' => 'uri'],
                'expires_in' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'OAuthMemberSummary' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'sn', 'nickname', 'avatar', 'mobile'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1], 'sn' => ['type' => 'string'],
                'nickname' => ['type' => 'string'], 'avatar' => ['type' => 'string'], 'mobile' => ['type' => 'string'],
            ],
        ],
        'OAuthLoginCompletedData' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['completed', 'member', 'token'],
            'properties' => [
                'completed' => ['type' => 'boolean', 'enum' => [true]], 'member' => $schema('OAuthMemberSummary'),
                'token' => ['type' => 'string', 'readOnly' => true, 'description' => '本次完整登录签发的会员访问令牌。'],
                'return_path' => ['type' => 'string'],
            ],
        ],
        'OAuthLoginPendingData' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['completed', 'member', 'completion_ticket', 'expires_in', 'need_profile', 'need_mobile'],
            'properties' => [
                'completed' => ['type' => 'boolean', 'enum' => [false]], 'member' => $schema('OAuthMemberSummary'),
                'completion_ticket' => ['type' => 'string', 'readOnly' => true, 'description' => '短期、单次使用的资料补全票据。'],
                'expires_in' => ['type' => 'integer', 'minimum' => 1], 'need_profile' => ['type' => 'boolean'],
                'need_mobile' => ['type' => 'boolean'], 'return_path' => ['type' => 'string'],
            ],
        ],
        'OAuthOfficialAccountMessageXml' => [
            'type' => 'string', 'description' => '微信公众号明文 XML 消息或文本回复 XML。',
        ],
        'OAuthMenuExtensionValue' => [
            'description' => '菜单扩展字段中可原样持久化并由详情接口返回的 JSON 值。',
            'oneOf' => [
                ['type' => 'string', 'nullable' => true],
                ['type' => 'number'],
                ['type' => 'boolean'],
                ['type' => 'array', 'items' => $schema('OAuthMenuExtensionValue')],
                [
                    'type' => 'object',
                    'additionalProperties' => $schema('OAuthMenuExtensionValue'),
                ],
            ],
        ],
        'OAuthMutationResponse' => $envelope($schema('OAuthEmptyData')),
        'OAuthWebPageConfigResponse' => $envelope($schema('OAuthWebPageConfig')),
        'OAuthMiniProgramConfigResponse' => $envelope($schema('OAuthMiniProgramConfig')),
        'OAuthOfficialAccountConfigResponse' => $envelope($schema('OAuthOfficialAccountConfig')),
        'OAuthOpenPlatformConfigResponse' => $envelope($schema('OAuthOpenPlatformConfig')),
        'OAuthMenuResponse' => $envelope([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['menu'],
            'properties' => ['menu' => ['type' => 'array', 'items' => $schema('OAuthMenuNode')]],
        ]),
        'OAuthReplyDetailResponse' => $envelope($schema('OAuthReplyRecord')),
        'OAuthReplyPageResponse' => $envelope([
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => $schema('OAuthReplyRecord')],
                'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ]),
        'OAuthAuthorizationResponse' => $envelope($schema('OAuthAuthorizationData')),
        'OAuthLoginResponse' => $envelope([
            'oneOf' => [
                ['$ref' => '#/components/schemas/OAuthLoginCompletedData'],
                ['$ref' => '#/components/schemas/OAuthLoginPendingData'],
            ],
        ]),
    ]],
];
