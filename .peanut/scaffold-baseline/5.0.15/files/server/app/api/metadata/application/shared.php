<?php

declare(strict_types=1);

$common = require __DIR__ . '/common.php';
extract($common, EXTR_SKIP);

$applicationError = $responseRef('ErrorResponse');
$applicationEnvelope = static fn(array $data): array => [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['code', 'msg', 'data'],
    'properties' => [
        'code' => ['type' => 'integer', 'enum' => [20000]],
        'msg' => ['type' => 'string'],
        'data' => $data,
    ],
];
$applicationTime = [
    'oneOf' => [
        ['type' => 'integer', 'minimum' => 0],
        ['type' => 'string'],
    ],
];

return [
    'paths' => [
        '/api/index/config' => ['get' => $operation(
            'getApplicationPublicConfig',
            'Application',
            [
                '200' => $success($ref('ApplicationPublicConfig')),
                '403' => $applicationError,
            ],
        )],
        '/api/index/policy' => ['get' => $operation(
            'getApplicationPolicy',
            'Application',
            [
                '200' => $success($ref('ApplicationPolicy')),
                '403' => $applicationError,
            ],
            [$query('type', ['type' => 'string', 'enum' => ['privacy', 'service']], false)],
        )],
        '/api/search/hotLists' => ['get' => $operation(
            'listPublicHotSearches',
            'Application',
            [
                '200' => $success($ref('ApplicationHotSearchData')),
                '403' => $applicationError,
            ],
        )],
        '/api/decoration/mobile' => ['get' => $operation(
            'getPublicMobileDecorationPage',
            'Decoration',
            [
                '200' => $success($ref('ApplicationDecorationPage')),
                '400' => $applicationError,
                '403' => $applicationError,
            ],
            [$query('type', ['type' => 'integer', 'enum' => [1, 2, 3], 'default' => 1])],
            errors: ['DECORATION_PAGE_TYPE_INVALID'],
        )],
        '/api/decoration/tabbar' => ['get' => $operation(
            'getVisibleDecorationTabbar',
            'Decoration',
            [
                '200' => $success($ref('ApplicationDecorationTabbar')),
                '403' => $applicationError,
            ],
        )],
        '/api/decoration/pc' => ['get' => $operation(
            'getPublicPcDecorationPage',
            'Decoration',
            [
                '200' => $success($ref('ApplicationDecorationPage')),
                '403' => $applicationError,
            ],
        )],
        '/api/pc/config' => ['get' => $operation(
            'getPcPublicConfig',
            'Application',
            [
                '200' => $success($ref('ApplicationPublicConfig')),
                '403' => $applicationError,
            ],
        )],
    ],
    'components' => [
        'securitySchemes' => [
            'tenantHost' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'Host',
                'description' => '必须命中已登记的 Tenant Admin 入口；Host 不是身份凭证。',
            ],
            'tenantRefreshCookie' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'opaque tenant access token',
                'description' => 'Bearer 必须是发起刷新的精确 access token（允许过期或已轮换）。浏览器自动携带对应的 Secure HttpOnly refresh cookie，名称为本 client 的 __Host-pa_tenant_refresh_<client> 加下划线及 access token 的 SHA-256；hash 仅用于选择 cookie，服务端仍校验凭据属于同一会话。另要求可信 same-origin 浏览器请求；OpenAPI 固定 name 的 cookie scheme 无法表示该动态名称。',
            ],
            'platformHost' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'Host',
                'description' => '必须命中实例登记的 Platform Host；该约束不授予 Platform 权限。',
            ],
            'platformRefreshCookie' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'opaque platform access token',
                'description' => 'Bearer 指定发起刷新的精确 access token。浏览器自动携带对应的 Secure HttpOnly refresh cookie，名称为 __Host-pa_platform_refresh 加下划线及 access token 的 SHA-256；hash 仅用于选择 cookie，不代替 Platform refresh 凭据校验。OpenAPI 固定 name 的 cookie scheme 无法表示该动态名称。',
            ],
            'installationSetupToken' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'opaque setup token',
                'description' => '仅 guided 安装使用；请求还必须通过同源校验。',
            ],
        ],
        'parameters' => [
            'PlatformPage' => [
                'in' => 'query', 'name' => 'page',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            'PlatformPageSize' => [
                'in' => 'query', 'name' => 'page_size',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
            'IdempotencyKey' => [
                'in' => 'header', 'name' => 'Idempotency-Key', 'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            ],
            'IfMatchRevision' => [
                'in' => 'header', 'name' => 'If-Match', 'required' => true,
                'schema' => ['type' => 'string', 'pattern' => '^"rev-[0-9]+"$'],
            ],
        ],
        'schemas' => [
            'ApplicationDynamicValue' => [
                'description' => '受业务定义约束的 JSON 值；对象的每个扩展值仍必须是该明确递归类型。',
                'nullable' => true,
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                    ['type' => 'number'],
                    ['type' => 'boolean'],
                    ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ApplicationDynamicValue']],
                    [
                        'type' => 'object',
                        'additionalProperties' => ['$ref' => '#/components/schemas/ApplicationDynamicValue'],
                    ],
                ],
            ],
            'PositiveIntegerId' => ['type' => 'integer', 'minimum' => 1],
            'NumericStringId' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            'NullableNumericStringId' => [
                'type' => 'string', 'pattern' => '^[1-9][0-9]*$', 'nullable' => true,
            ],
            'UtcInstant' => [
                'type' => 'string',
                'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{3})?Z?$',
            ],
            'NullableUtcInstant' => [
                'type' => 'string',
                'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{3})?Z?$',
                'nullable' => true,
            ],
            'ExportPageInfo' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['count', 'page_size', 'sum_page', 'max_page', 'all_max_size', 'page_start', 'page_end', 'file_name'],
                'properties' => [
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'page_size' => ['type' => 'integer', 'minimum' => 1],
                    'sum_page' => ['type' => 'integer', 'minimum' => 1],
                    'max_page' => ['type' => 'integer', 'minimum' => 0],
                    'all_max_size' => ['type' => 'integer', 'minimum' => 1],
                    'page_start' => ['type' => 'integer', 'minimum' => 1],
                    'page_end' => ['type' => 'integer', 'minimum' => 1],
                    'file_name' => ['type' => 'string'],
                ],
            ],
            'ExportFile' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['url', 'file_name'],
                'properties' => [
                    'url' => ['type' => 'string'],
                    'file_name' => ['type' => 'string'],
                ],
            ],
            'StringList' => ['type' => 'array', 'items' => ['type' => 'string']],
            'IntegerList' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
            'ApplicationFlexibleTime' => $applicationTime,
            'ApplicationDynamicObject' => [
                'type' => 'object',
                'additionalProperties' => $ref('ApplicationDynamicValue'),
            ],
            'ApplicationDecorationComponent' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['title', 'name', 'content', 'styles'],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'disabled' => ['type' => 'integer', 'enum' => [0, 1]],
                    'content' => $ref('ApplicationDynamicObject'),
                    'styles' => $ref('ApplicationDynamicObject'),
                ],
            ],
            'ApplicationDecorationPayload' => [
                'oneOf' => [
                    ['type' => 'array', 'items' => $ref('ApplicationDecorationComponent')],
                    $ref('ApplicationDynamicObject'),
                ],
                'description' => '由已登记装修组件 schema 决定；组件集合保留显式信封，内部提供方值使用递归 JSON 类型。',
            ],
            'ApplicationDecorationPage' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'type', 'name', 'data', 'meta', 'create_time', 'update_time', 'tenant_id'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'type' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
                    'name' => ['type' => 'string'],
                    'data' => $ref('ApplicationDecorationPayload'),
                    'meta' => $ref('ApplicationDecorationPayload'),
                    'create_time' => $applicationTime,
                    'update_time' => $applicationTime,
                    'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'ApplicationDecorationLink' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['target_type', 'target'],
                'properties' => [
                    'target_type' => ['type' => 'string', 'enum' => ['shop', 'article', 'custom', 'mini_program']],
                    'target' => ['oneOf' => [['type' => 'string'], ['type' => 'integer', 'minimum' => 1]]],
                    'query' => $ref('ApplicationDynamicObject'),
                ],
            ],
            'ApplicationDecorationTabbarItem' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'position', 'name', 'link', 'is_show', 'create_time',
                    'update_time', 'selected', 'unselected', 'tenant_id',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'position' => ['type' => 'integer', 'minimum' => 0],
                    'name' => ['type' => 'string'],
                    'link' => $ref('ApplicationDecorationLink'),
                    'is_show' => ['type' => 'integer', 'enum' => [1]],
                    'create_time' => $applicationTime,
                    'update_time' => $applicationTime,
                    'selected' => ['type' => 'string'],
                    'unselected' => ['type' => 'string'],
                    'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'ApplicationDecorationTabbar' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['style', 'list'],
                'properties' => [
                    'style' => [
                        'type' => 'object',
                        'required' => ['default_color', 'selected_color'],
                        'properties' => [
                            'default_color' => ['type' => 'string'],
                            'selected_color' => ['type' => 'string'],
                        ],
                        'additionalProperties' => $ref('ApplicationDynamicValue'),
                    ],
                    'list' => ['type' => 'array', 'items' => $ref('ApplicationDecorationTabbarItem')],
                ],
            ],
            'ApplicationWebsiteConfig' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo',
                    'pc_logo', 'pc_title', 'pc_ico', 'pc_desc', 'pc_keywords', 'h5_favicon',
                    'slogan', 'copyright', 'official_url', 'github_url',
                ],
                'properties' => array_fill_keys([
                    'name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo',
                    'pc_logo', 'pc_title', 'pc_ico', 'pc_desc', 'pc_keywords', 'h5_favicon',
                    'slogan', 'copyright', 'official_url', 'github_url',
                ], ['type' => 'string']),
            ],
            'ApplicationPublicConfig' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'domain', 'website', 'tenantName', 'demo', 'login', 'copyright',
                    'site_statistics', 'web_page', 'tabbar', 'theme', 'version',
                ],
                'properties' => [
                    'domain' => ['type' => 'string'],
                    'website' => $ref('ApplicationWebsiteConfig'),
                    'tenantName' => ['type' => 'string'],
                    'demo' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['enabled', 'email', 'password'],
                        'properties' => [
                            'enabled' => ['type' => 'boolean'],
                            'email' => ['type' => 'string'],
                            'password' => ['type' => 'string'],
                        ],
                    ],
                    'login' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['login_way', 'coerce_mobile', 'login_agreement', 'third_auth', 'wechat_auth'],
                        'properties' => [
                            'login_way' => ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => [1, 2]]],
                            'coerce_mobile' => ['type' => 'integer', 'enum' => [0, 1]],
                            'login_agreement' => ['type' => 'integer', 'enum' => [0, 1]],
                            'third_auth' => ['type' => 'integer', 'enum' => [0, 1]],
                            'wechat_auth' => ['type' => 'integer', 'enum' => [0, 1]],
                        ],
                    ],
                    'copyright' => $ref('ApplicationDynamicObject'),
                    'site_statistics' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['clarity_code'],
                        'properties' => ['clarity_code' => ['type' => 'string']],
                    ],
                    'web_page' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['status', 'page_status', 'page_url', 'url'],
                        'properties' => [
                            'status' => ['type' => 'integer', 'enum' => [0, 1]],
                            'page_status' => ['type' => 'integer', 'enum' => [0, 1]],
                            'page_url' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                    'tabbar' => $ref('ApplicationDecorationTabbar'),
                    'theme' => $ref('ApplicationDecorationPage'),
                    'version' => ['type' => 'string'],
                ],
            ],
            'ApplicationPolicy' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['title', 'content'],
                'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string']],
            ],
            'ApplicationHotSearchData' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['status', 'data'],
                'properties' => [
                    'status' => ['type' => 'integer', 'enum' => [0, 1]],
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object', 'additionalProperties' => false,
                            'required' => ['name', 'sort'],
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'sort' => ['type' => 'integer', 'minimum' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
