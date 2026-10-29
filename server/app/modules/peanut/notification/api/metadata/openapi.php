<?php

declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$success = static fn(string $schema): array => [
    'description' => 'Successful notification-inbox response.',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$messageKey = ['name' => 'messageKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'pattern' => '^notice_[0-9a-f]{32}$',
]];

return [
    'paths' => [
        '/adminapi/peanut.notification.channel.detail' => ['get' => [
            'tags' => ['Notice'], 'operationId' => 'getNotificationChannelConfiguration',
            'responses' => [
                '200' => $success('NotificationChannelDetailResponse'),
                '401' => $error, '403' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.notification.channel.save' => ['post' => [
            'tags' => ['Notice'], 'operationId' => 'saveNotificationChannelConfiguration',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NotificationChannelSaveRequest']]]],
            'responses' => [
                '200' => $success('NotificationMutationResponse'),
                '400' => $error, '401' => $error, '403' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.notification.scene.list' => ['get' => [
            'tags' => ['Notice'], 'operationId' => 'listNotificationScenes',
            'responses' => [
                '200' => $success('NotificationSceneListResponse'),
                '401' => $error, '403' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.notification.scene.detail' => ['get' => [
            'tags' => ['Notice'], 'operationId' => 'getNotificationScene',
            'parameters' => [[
                'name' => 'id', 'in' => 'query', 'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ]],
            'responses' => [
                '200' => $success('NotificationSceneDetailResponse'),
                '400' => $error, '401' => $error, '403' => $error, '404' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED', 'NOTIFICATION_SCENE_NOT_FOUND'],
        ]],
        '/adminapi/peanut.notification.scene.save' => ['post' => [
            'tags' => ['Notice'], 'operationId' => 'saveNotificationScene',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NotificationSceneSaveRequest']]]],
            'responses' => [
                '200' => $success('NotificationMutationResponse'),
                '400' => $error, '401' => $error, '403' => $error, '404' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED', 'NOTIFICATION_SCENE_NOT_FOUND'],
        ]],
        '/adminapi/peanut.notification.log.list' => ['get' => [
            'tags' => ['Notice'], 'operationId' => 'listNotificationLogs',
            'parameters' => [
                ['name' => 'receiver', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'channel', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [1, 2, 3]]],
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1, 2, 3]]],
                ['name' => 'scene_id', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'start_time', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                ['name' => 'end_time', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                ['name' => 'page_no', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'responses' => [
                '200' => $success('NotificationLogListResponse'),
                '400' => $error, '401' => $error, '403' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.notification.log.detail' => ['get' => [
            'tags' => ['Notice'], 'operationId' => 'getNotificationLog',
            'parameters' => [[
                'name' => 'id', 'in' => 'query', 'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            ]],
            'responses' => [
                '200' => $success('NotificationLogDetailResponse'),
                '401' => $error, '403' => $error,
            ],
            'x-peanut-errors' => ['NOTIFICATION_PERMISSION_DENIED'],
        ]],
        '/adminapi/api/v1/notifications' => [
            'get' => [
                'tags' => ['Notice'], 'operationId' => 'listInboxNotifications',
                'parameters' => [
                    ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'all', 'enum' => ['all', 'unread', 'read', 'archived']]],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
                ],
                'responses' => ['200' => $success('NotificationListResponse'), '401' => $error, '403' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/notifications/{messageKey}/read' => [
            'post' => [
                'tags' => ['Notice'], 'operationId' => 'markInboxNotificationRead',
                'parameters' => [$messageKey, [
                    'name' => 'If-Match', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'pattern' => '^"rev-[1-9][0-9]*"$'],
                ]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'additionalProperties' => false,
                ]]]],
                'responses' => ['200' => $success('NotificationResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/notifications/bulk' => [
            'post' => [
                'tags' => ['Notice'], 'operationId' => 'bulkUpdateInboxNotifications',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['message_keys', 'action'],
                    'properties' => [
                        'message_keys' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'pattern' => '^notice_[0-9a-f]{32}$']],
                        'action' => ['type' => 'string', 'enum' => ['read', 'archive']],
                    ],
                ]]]],
                'responses' => ['200' => $success('NotificationBulkResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'NotificationChannelAliyunConfiguration' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['access_key_id', 'access_key_secret', 'sign_name', 'status'],
            'properties' => [
                'access_key_id' => ['type' => 'string'],
                'access_key_secret' => ['type' => 'string', 'description' => '空字符串或已配置密钥的脱敏哨兵 ******。'],
                'sign_name' => ['type' => 'string'],
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
            ],
        ],
        'NotificationChannelTencentConfiguration' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['secret_id', 'secret_key', 'sdk_app_id', 'sign_name', 'region', 'status'],
            'properties' => [
                'secret_id' => ['type' => 'string'],
                'secret_key' => ['type' => 'string', 'description' => '空字符串或已配置密钥的脱敏哨兵 ******。'],
                'sdk_app_id' => ['type' => 'string'],
                'sign_name' => ['type' => 'string'],
                'region' => ['type' => 'string'],
                'status' => ['type' => 'integer', 'enum' => [0, 1]],
            ],
        ],
        'NotificationChannelDetail' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['sms_default', 'sms_aliyun', 'sms_tencent', 'status'],
            'properties' => [
                'sms_default' => ['type' => 'string', 'enum' => ['', 'aliyun', 'tencent']],
                'sms_aliyun' => ['$ref' => '#/components/schemas/NotificationChannelAliyunConfiguration'],
                'sms_tencent' => ['$ref' => '#/components/schemas/NotificationChannelTencentConfiguration'],
                'status' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['sms'],
                    'properties' => ['sms' => ['type' => 'boolean']],
                ],
            ],
        ],
        'NotificationChannelSaveRequest' => [
            'oneOf' => [
                [
                    'type' => 'object',
                    'description' => 'sms_default 分支只读取 value；当前控制器会忽略其余字段。',
                    'required' => ['section', 'value'],
                    'properties' => [
                        'section' => ['type' => 'string', 'enum' => ['sms_default']],
                        'value' => ['type' => 'string', 'enum' => ['aliyun', 'tencent']],
                    ],
                ],
                [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['section'],
                    'properties' => [
                        'section' => ['type' => 'string', 'enum' => ['sms_aliyun']],
                        'access_key_id' => ['type' => 'string'],
                        'access_key_secret' => ['type' => 'string', 'description' => '****** 表示保留现有密钥。'],
                        'sign_name' => ['type' => 'string'],
                        'status' => ['type' => 'integer', 'enum' => [0, 1]],
                    ],
                ],
                [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['section'],
                    'properties' => [
                        'section' => ['type' => 'string', 'enum' => ['sms_tencent']],
                        'secret_id' => ['type' => 'string'],
                        'secret_key' => ['type' => 'string', 'description' => '****** 表示保留现有密钥。'],
                        'sdk_app_id' => ['type' => 'string'],
                        'sign_name' => ['type' => 'string'],
                        'region' => ['type' => 'string'],
                        'status' => ['type' => 'integer', 'enum' => [0, 1]],
                    ],
                ],
            ],
        ],
        'NotificationSceneVariables' => [
            'description' => 'NoticeScene 未声明 JSON cast；按实际 ORM/驱动边界可能是 JSON 字符串、已解码字符串数组或 null。',
            'nullable' => true,
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'NotificationSceneListItem' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'code', 'name', 'description', 'recipient', 'variables', 'sms_template_id', 'sms_content', 'sms_status', 'update_time'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'maxLength' => 50],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'description' => ['type' => 'string', 'maxLength' => 255],
                'recipient' => ['type' => 'string', 'maxLength' => 50],
                'variables' => ['$ref' => '#/components/schemas/NotificationSceneVariables'],
                'sms_template_id' => ['type' => 'string', 'maxLength' => 100],
                'sms_content' => ['type' => 'string', 'maxLength' => 500],
                'sms_status' => ['type' => 'integer', 'enum' => [0, 1]],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
            ],
        ],
        'NotificationSceneDetail' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'code', 'name', 'description', 'recipient', 'variables', 'sms_template_id', 'sms_content', 'sms_status', 'create_time', 'update_time', 'tenant_id'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'maxLength' => 50],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'description' => ['type' => 'string', 'maxLength' => 255],
                'recipient' => ['type' => 'string', 'maxLength' => 50],
                'variables' => ['$ref' => '#/components/schemas/NotificationSceneVariables'],
                'sms_template_id' => ['type' => 'string', 'maxLength' => 100],
                'sms_content' => ['type' => 'string', 'maxLength' => 500],
                'sms_status' => ['type' => 'integer', 'enum' => [0, 1]],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'NotificationSceneSaveRequest' => [
            'type' => 'object',
            'description' => '服务只读取已列出的四个字段；当前旧控制器会忽略其他字段。',
            'required' => ['id', 'sms_status'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'sms_template_id' => ['type' => 'string', 'maxLength' => 100],
                'sms_content' => ['type' => 'string', 'maxLength' => 500],
                'sms_status' => ['type' => 'integer', 'enum' => [0, 1]],
            ],
        ],
        'NotificationLogRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'id', 'template_id', 'scene_id', 'channel', 'provider', 'receiver', 'title',
                'content', 'is_verified', 'check_count', 'verified_time', 'status', 'error',
                'send_time', 'create_time', 'template_name', 'template_code', 'scene_name', 'scene_code',
            ],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'template_id' => ['type' => 'integer', 'minimum' => 0],
                'scene_id' => ['type' => 'integer', 'minimum' => 0],
                'channel' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'provider' => ['type' => 'string', 'maxLength' => 20],
                'receiver' => ['type' => 'string', 'maxLength' => 200],
                'title' => ['type' => 'string', 'maxLength' => 200],
                'content' => ['type' => 'string', 'nullable' => true],
                'is_verified' => ['type' => 'integer', 'enum' => [0, 1]],
                'check_count' => ['type' => 'integer', 'minimum' => 0],
                'verified_time' => ['type' => 'integer', 'minimum' => 0],
                'status' => ['type' => 'integer', 'enum' => [0, 1, 2, 3]],
                'error' => ['type' => 'string', 'maxLength' => 500],
                'send_time' => ['type' => 'integer', 'minimum' => 0],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'template_name' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                'template_code' => ['type' => 'string', 'maxLength' => 50, 'nullable' => true],
                'scene_name' => ['type' => 'string', 'maxLength' => 100, 'nullable' => true],
                'scene_code' => ['type' => 'string', 'maxLength' => 50, 'nullable' => true],
            ],
        ],
        'NotificationLogPage' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NotificationLogRecord']],
                'count' => ['type' => 'integer', 'minimum' => 0],
                'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'NotificationMutationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'maxItems' => 0]],
        ],
        'NotificationChannelDetailResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/NotificationChannelDetail']],
        ],
        'NotificationSceneListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['list', 'total'],
                    'properties' => [
                        'list' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NotificationSceneListItem']],
                        'total' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ],
            ],
        ],
        'NotificationSceneDetailResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/NotificationSceneDetail']],
        ],
        'NotificationLogListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['$ref' => '#/components/schemas/NotificationLogPage']],
        ],
        'NotificationLogDetailResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/NotificationLogRecord'],
                    ['type' => 'array', 'maxItems' => 0],
                ]],
            ],
        ],
        'NotificationAttachment' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['file_key', 'original_name', 'media_type', 'size_bytes', 'sha256'],
            'properties' => [
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'original_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'media_type' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$'],
                'size_bytes' => ['type' => 'integer', 'minimum' => 0],
                'sha256' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
            ],
        ],
        'NotificationMessage' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['message_key', 'template_key', 'template_revision', 'subject', 'body', 'status', 'revision', 'created_at', 'read_at', 'archived_at', 'attachments'],
            'properties' => [
                'message_key' => ['type' => 'string', 'pattern' => '^notice_[0-9a-f]{32}$'],
                'template_key' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'],
                'template_revision' => ['type' => 'integer', 'minimum' => 1],
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10000],
                'status' => ['type' => 'string', 'enum' => ['unread', 'read', 'archived']],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'read_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'archived_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'attachments' => ['type' => 'array', 'maxItems' => 10, 'items' => ['$ref' => '#/components/schemas/NotificationAttachment']],
            ],
        ],
        'NotificationMeta' => ['type' => 'object', 'required' => ['request_id'], 'properties' => [
            'request_id' => ['type' => 'string', 'minLength' => 1],
        ]],
        'NotificationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/NotificationMessage'], 'meta' => ['$ref' => '#/components/schemas/NotificationMeta']],
        ],
        'NotificationListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/NotificationMessage']],
                ]],
                'meta' => ['allOf' => [
                    ['$ref' => '#/components/schemas/NotificationMeta'],
                    ['type' => 'object', 'required' => ['page', 'page_size', 'total'], 'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1], 'page_size' => ['type' => 'integer', 'minimum' => 1], 'total' => ['type' => 'integer', 'minimum' => 0],
                    ]],
                ]],
            ],
        ],
        'NotificationBulkResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['changed'], 'properties' => ['changed' => ['type' => 'integer', 'minimum' => 0]]],
                'meta' => ['$ref' => '#/components/schemas/NotificationMeta'],
            ],
        ],
    ]],
];
