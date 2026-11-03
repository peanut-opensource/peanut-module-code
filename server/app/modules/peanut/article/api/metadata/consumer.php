<?php

declare(strict_types=1);

$common = require dirname(__DIR__, 5) . '/api/metadata/application/common.php';
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

$legacyPaths = json_decode(<<<'JSON'
{
    "/api/article/lists": {
        "x-peanut-required-modules": [
            "peanut.article"
        ],
        "get": {
            "tags": [
                "Article"
            ],
            "operationId": "listArticles",
            "parameters": [
                {
                    "$ref": "#/components/parameters/PageNo"
                },
                {
                    "$ref": "#/components/parameters/PageSize"
                },
                {
                    "in": "query",
                    "name": "cid",
                    "schema": {
                        "type": "integer",
                        "minimum": 0
                    }
                },
                {
                    "in": "query",
                    "name": "keyword",
                    "schema": {
                        "type": "string"
                    }
                },
                {
                    "in": "query",
                    "name": "sort",
                    "schema": {
                        "type": "string",
                        "enum": [
                            "default",
                            "new",
                            "hot"
                        ],
                        "default": "default"
                    }
                }
            ],
            "responses": {
                "200": {
                    "description": "公开文章分页",
                    "content": {
                        "application/json": {
                            "schema": {
                                "$ref": "#/components/schemas/ApplicationArticleListResponse"
                            }
                        }
                    }
                },
                "400": {
                    "$ref": "#/components/responses/ErrorResponse"
                },
                "403": {
                    "$ref": "#/components/responses/ErrorResponse"
                }
            }
        }
    },
    "/api/article/detail": {
        "x-peanut-required-modules": [
            "peanut.article"
        ],
        "get": {
            "tags": [
                "Article"
            ],
            "operationId": "articleDetail",
            "parameters": [
                {
                    "in": "query",
                    "name": "id",
                    "required": true,
                    "schema": {
                        "type": "integer",
                        "minimum": 1
                    }
                }
            ],
            "responses": {
                "200": {
                    "description": "公开文章详情",
                    "content": {
                        "application/json": {
                            "schema": {
                                "$ref": "#/components/schemas/ApplicationArticleDetailResponse"
                            }
                        }
                    }
                },
                "403": {
                    "$ref": "#/components/responses/ErrorResponse"
                },
                "404": {
                    "$ref": "#/components/responses/ErrorResponse"
                }
            },
            "x-peanut-errors": [
                "ARTICLE_NOT_FOUND"
            ]
        }
    }
}
JSON, true, 512, JSON_THROW_ON_ERROR);

return [
    'paths' => [
        ...$legacyPaths,
        '/api/index/index' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'getApplicationHome',
            'Application',
            [
                '200' => $success($ref('ApplicationHomeData')),
                '403' => $applicationError,
            ],
        )],
        '/api/article/cate' => ['x-peanut-required-modules' => ['peanut.article'], 'get' => $operation(
            'listPublicArticleCategories',
            'Article',
            [
                '200' => $success(['type' => 'array', 'items' => $ref('ApplicationArticleCategory')]),
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
        'schemas' => [
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
            'ApplicationArticleListResponse' => $applicationEnvelope($ref('ApplicationArticleListPage')),
            'ApplicationArticleDetailResponse' => $applicationEnvelope($ref('ApplicationArticleDetail')),
        ],
    ],
];
