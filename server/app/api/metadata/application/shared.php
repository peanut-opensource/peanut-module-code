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
$articleDetailRequired = [
    'id', 'desc', 'abstract', 'is_show', 'create_time', 'update_time', 'delete_time',
    'title', 'author', 'content', 'sort', 'image', 'tenant_id', 'cid', 'click', 'collect',
];
$articleDetailProperties = [
    'id' => ['type' => 'integer', 'minimum' => 1],
    'desc' => ['type' => 'string', 'nullable' => true],
    'abstract' => ['type' => 'string', 'nullable' => true],
    'is_show' => ['type' => 'integer', 'enum' => [1]],
    'create_time' => $applicationTime,
    'update_time' => $applicationTime,
    'delete_time' => ['nullable' => true, 'oneOf' => [$applicationTime, ['type' => 'integer', 'enum' => [0]]]],
    'title' => ['type' => 'string'],
    'author' => ['type' => 'string', 'nullable' => true],
    'content' => ['type' => 'string'],
    'sort' => ['type' => 'integer'],
    'image' => ['type' => 'string'],
    'tenant_id' => ['type' => 'integer', 'minimum' => 1],
    'cid' => ['type' => 'integer', 'minimum' => 0],
    'click' => ['type' => 'integer', 'minimum' => 0],
    'collect' => ['type' => 'boolean'],
];

return [
    'paths' => [
        '/api/index/index' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'getApplicationHome',
            'Application',
            [
                '200' => $success($ref('ApplicationHomeData')),
                '403' => $applicationError,
            ],
        )],
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
        '/api/login/logout' => ['x-peanut-required-modules' => ['peanut.member'], 'post' => $operation(
            'logoutMemberSession',
            'Auth',
            ['200' => $emptySuccess, '401' => $applicationError],
        )],
        '/api/article/cate' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'listPublicArticleCategories',
            'Article',
            [
                '200' => $success(['type' => 'array', 'items' => $ref('ApplicationArticleCategory')]),
                '403' => $applicationError,
            ],
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
        '/api/pc/index' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'getPcHome',
            'Article',
            [
                '200' => $success($ref('ApplicationPcHomeData')),
                '403' => $applicationError,
            ],
        )],
        '/api/pc/infoCenter' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'getPcInformationCenter',
            'Article',
            [
                '200' => $success(['type' => 'array', 'items' => $ref('ApplicationInformationCategory')]),
                '403' => $applicationError,
            ],
        )],
        '/api/pc/articleDetail' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'getPcArticleDetail',
            'Article',
            [
                '200' => $success([
                    'oneOf' => [
                        $ref('ApplicationPcArticleDetail'),
                        ['type' => 'array', 'maxItems' => 0],
                    ],
                ]),
                '403' => $applicationError,
            ],
            [
                $query('id', ['type' => 'integer', 'minimum' => 0, 'default' => 0]),
                $query('source', ['type' => 'string', 'enum' => ['default', 'all', 'new', 'hot'], 'default' => 'default']),
            ],
        )],
        '/api/article/addCollect' => ['x-peanut-required-modules' => ['peanut.article', 'peanut.member'], 'post' => $operation(
            'addArticleCollection',
            'Article',
            [
                '200' => $emptySuccess,
                '400' => $applicationError,
                '401' => $applicationError,
                '403' => $applicationError,
                '404' => $applicationError,
            ],
            requestBody: $jsonBody($ref('ApplicationArticleCollectionRequest')),
            errors: ['ARTICLE_NOT_FOUND'],
        )],
        '/api/article/cancelCollect' => ['x-peanut-required-modules' => ['peanut.article', 'peanut.member'], 'post' => $operation(
            'cancelArticleCollection',
            'Article',
            [
                '200' => $emptySuccess,
                '400' => $applicationError,
                '401' => $applicationError,
                '403' => $applicationError,
            ],
            requestBody: $jsonBody($ref('ApplicationArticleCollectionRequest')),
        )],
        '/api/article/collect' => ['x-peanut-required-modules' => ['peanut.article', 'peanut.member'], 'get' => $operation(
            'listArticleCollections',
            'Article',
            [
                '200' => $success($ref('ApplicationArticleCollectionPage')),
                '400' => $applicationError,
                '401' => $applicationError,
                '403' => $applicationError,
            ],
            [$parameterRef('PageNo'), $parameterRef('PageSize')],
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
            'ApplicationArticleCategory' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string'],
                ],
            ],
            'ApplicationArticleListItem' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'cid', 'title', 'desc', 'image', 'create_time', 'click', 'collect'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'cid' => ['type' => 'integer', 'minimum' => 0],
                    'title' => ['type' => 'string'],
                    'desc' => ['type' => 'string', 'nullable' => true],
                    'image' => ['type' => 'string'],
                    'create_time' => $applicationTime,
                    'click' => ['type' => 'integer', 'minimum' => 0],
                    'collect' => ['type' => 'boolean'],
                ],
            ],
            'ApplicationArticleListPage' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $ref('ApplicationArticleListItem')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
            ],
            'ApplicationArticleDetail' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => $articleDetailRequired,
                'properties' => $articleDetailProperties,
            ],
            'ApplicationArticleNavigationItem' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'cid', 'title', 'desc', 'abstract', 'image', 'author', 'create_time', 'click'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'cid' => ['type' => 'integer', 'minimum' => 0],
                    'title' => ['type' => 'string'],
                    'desc' => ['type' => 'string', 'nullable' => true],
                    'abstract' => ['type' => 'string', 'nullable' => true],
                    'image' => ['type' => 'string'],
                    'author' => ['type' => 'string', 'nullable' => true],
                    'create_time' => $applicationTime,
                    'click' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ApplicationPcArticleDetail' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [...$articleDetailRequired, 'last', 'next', 'new', 'cate_name'],
                'properties' => [
                    ...$articleDetailProperties,
                    'last' => ['oneOf' => [$ref('ApplicationArticleNavigationItem'), ['type' => 'array', 'maxItems' => 0]]],
                    'next' => ['oneOf' => [$ref('ApplicationArticleNavigationItem'), ['type' => 'array', 'maxItems' => 0]]],
                    'new' => ['type' => 'array', 'items' => $ref('ApplicationArticleNavigationItem')],
                    'cate_name' => ['type' => 'string'],
                ],
            ],
            'ApplicationHomeArticle' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'title', 'desc', 'abstract', 'image', 'author', 'create_time', 'click'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'],
                    'desc' => ['type' => 'string', 'nullable' => true],
                    'abstract' => ['type' => 'string', 'nullable' => true],
                    'image' => ['type' => 'string'],
                    'author' => ['type' => 'string', 'nullable' => true],
                    'create_time' => $applicationTime,
                    'click' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ApplicationArticleCollectionRequest' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'ApplicationArticleCollectionItem' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'article_id', 'title', 'image', 'desc', 'is_show',
                    'create_time', 'collect_time', 'click',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'article_id' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'],
                    'image' => ['type' => 'string'],
                    'desc' => ['type' => 'string', 'nullable' => true],
                    'is_show' => ['type' => 'integer', 'enum' => [1]],
                    'create_time' => $applicationTime,
                    'collect_time' => ['type' => 'string'],
                    'click' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ApplicationArticleCollectionPage' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $ref('ApplicationArticleCollectionItem')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
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
            'ApplicationHomeData' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['article', 'decorate'],
                'properties' => [
                    'article' => ['type' => 'array', 'items' => $ref('ApplicationHomeArticle')],
                    'decorate' => $ref('ApplicationDecorationPage'),
                ],
            ],
            'ApplicationPcHomeData' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['all', 'new', 'hot', 'decorate'],
                'properties' => [
                    'all' => ['type' => 'array', 'items' => $ref('ApplicationArticleNavigationItem')],
                    'new' => ['type' => 'array', 'items' => $ref('ApplicationArticleNavigationItem')],
                    'hot' => ['type' => 'array', 'items' => $ref('ApplicationArticleNavigationItem')],
                    'decorate' => $ref('ApplicationDecorationPage'),
                ],
            ],
            'ApplicationInformationArticle' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'cid', 'title', 'desc', 'abstract', 'image', 'author', 'is_show',
                    'sort', 'create_time', 'update_time', 'delete_time', 'click',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'cid' => ['type' => 'integer', 'minimum' => 1],
                    'title' => ['type' => 'string'],
                    'desc' => ['type' => 'string', 'nullable' => true],
                    'abstract' => ['type' => 'string', 'nullable' => true],
                    'image' => ['type' => 'string'],
                    'author' => ['type' => 'string', 'nullable' => true],
                    'is_show' => ['type' => 'integer', 'enum' => [1]],
                    'sort' => ['type' => 'integer'],
                    'create_time' => $applicationTime,
                    'update_time' => $applicationTime,
                    'delete_time' => ['nullable' => true, 'oneOf' => [$applicationTime, ['type' => 'integer', 'enum' => [0]]]],
                    'click' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'ApplicationInformationCategory' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'name', 'article'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string'],
                    'article' => ['type' => 'array', 'items' => $ref('ApplicationInformationArticle')],
                ],
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
            'ApplicationArticleListResponse' => $applicationEnvelope($ref('ApplicationArticleListPage')),
            'ApplicationArticleDetailResponse' => $applicationEnvelope($ref('ApplicationArticleDetail')),
        ],
    ],
];
