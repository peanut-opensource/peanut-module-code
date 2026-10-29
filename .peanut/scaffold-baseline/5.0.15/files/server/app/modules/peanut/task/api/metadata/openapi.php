<?php

declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$json = static fn(string $schema): array => [
    'description' => 'Successful task-job response.',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$jobKey = ['name' => 'jobKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'pattern' => '^job_[0-9a-f]{32}$',
]];
$revisionBody = [
    'required' => true,
    'content' => ['application/json' => ['schema' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['revision'],
        'properties' => ['revision' => ['type' => 'integer', 'minimum' => 1]],
    ]]],
];
$taskMutation = [
    'description' => '操作成功',
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TaskMutationResponse']]],
];
$taskError = $error;

return [
    'paths' => [
        '/adminapi/api/v1/tasks/jobs' => [
            'get' => [
                'tags' => ['Task'], 'operationId' => 'listTaskJobs',
                'parameters' => [
                    ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'queued', 'enum' => ['queued', 'running', 'succeeded', 'dead', 'cancelled']]],
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
                ],
                'responses' => ['200' => $json('TaskJobListResponse'), '401' => $error, '403' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/api/v1/tasks/jobs/{jobKey}/cancel' => [
            'post' => [
                'tags' => ['Task'], 'operationId' => 'cancelTaskJob', 'parameters' => [$jobKey],
                'requestBody' => $revisionBody,
                'responses' => ['200' => $json('TaskJobResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
            ],
        ],
        '/adminapi/api/v1/tasks/jobs/{jobKey}/retry' => [
            'post' => [
                'tags' => ['Task'], 'operationId' => 'retryTaskJob', 'parameters' => [$jobKey],
                'requestBody' => $revisionBody,
                'responses' => ['200' => $json('TaskJobResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error, '503' => $error],
            ],
        ],
        '/adminapi/peanut.task.expression' => ['get' => [
            'tags' => ['Task'], 'operationId' => 'previewCrontabExpression',
            'parameters' => [[
                'name' => 'expression', 'in' => 'query', 'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'example' => '0 * * * *',
            ]],
            'responses' => [
                '200' => ['description' => '未来五次执行时间', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TaskExpressionResponse']]]],
                '400' => $taskError, '401' => $taskError, '403' => $taskError,
            ],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.task.list' => ['get' => [
            'tags' => ['Task'], 'operationId' => 'listCrontabs',
            'parameters' => [
                ['name' => 'name', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [1, 2, 3]]],
                ['name' => 'page_no', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ],
            'responses' => [
                '200' => ['description' => '当前租户的定时任务分页列表。', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CrontabListResponse']]]],
                '400' => $taskError, '401' => $taskError, '403' => $taskError,
            ],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.task.detail' => ['get' => [
            'tags' => ['Task'], 'operationId' => 'getCrontab',
            'parameters' => [[
                'name' => 'id', 'in' => 'query', 'required' => true,
                'schema' => ['$ref' => '#/components/schemas/CrontabPositiveIntegerInput'],
            ]],
            'responses' => [
                '200' => ['description' => '当前租户的定时任务详情。', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CrontabDetailResponse']]]],
                '400' => $taskError, '401' => $taskError, '403' => $taskError, '404' => $taskError,
            ],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED', 'TASK_CRONTAB_NOT_FOUND'],
        ]],
        '/adminapi/peanut.task.add' => ['post' => [
            'tags' => ['Task'], 'operationId' => 'createCrontab',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CrontabCreateRequest']]]],
            'responses' => ['200' => $taskMutation, '400' => $taskError, '401' => $taskError, '403' => $taskError],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.task.edit' => ['post' => [
            'tags' => ['Task'], 'operationId' => 'updateCrontab',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CrontabUpdateRequest']]]],
            'responses' => ['200' => $taskMutation, '400' => $taskError, '401' => $taskError, '403' => $taskError],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.task.delete' => ['post' => [
            'tags' => ['Task'], 'operationId' => 'deleteCrontab',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CrontabIdentifierRequest']]]],
            'responses' => ['200' => $taskMutation, '400' => $taskError, '401' => $taskError, '403' => $taskError],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED'],
        ]],
        '/adminapi/peanut.task.operate' => ['post' => [
            'tags' => ['Task'], 'operationId' => 'operateCrontab',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CrontabOperateRequest']]]],
            'responses' => ['200' => $taskMutation, '400' => $taskError, '401' => $taskError, '403' => $taskError],
            'x-peanut-errors' => ['TASK_PERMISSION_DENIED'],
        ]],
    ],
    'components' => ['schemas' => [
        'TaskJob' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['job_key', 'task_type', 'status', 'attempt_count', 'max_attempts', 'revision', 'last_error_code', 'available_at', 'created_at', 'updated_at', 'completed_at'],
            'properties' => [
                'job_key' => ['type' => 'string', 'pattern' => '^job_[0-9a-f]{32}$'],
                'task_type' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'],
                'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'succeeded', 'dead', 'cancelled']],
                'attempt_count' => ['type' => 'integer', 'minimum' => 0],
                'max_attempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'last_error_code' => ['type' => 'string', 'nullable' => true, 'pattern' => '^[A-Z][A-Z0-9_]{2,63}$'],
                'available_at' => ['type' => 'string', 'format' => 'date-time'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                'completed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'TaskJobResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['$ref' => '#/components/schemas/TaskJob'],
                'meta' => ['$ref' => '#/components/schemas/TaskRequestMeta'],
            ],
        ],
        'TaskJobListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TaskJob']],
                ]],
                'meta' => ['allOf' => [
                    ['$ref' => '#/components/schemas/TaskRequestMeta'],
                    ['type' => 'object', 'required' => ['page', 'page_size', 'total'], 'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1],
                        'page_size' => ['type' => 'integer', 'minimum' => 1],
                        'total' => ['type' => 'integer', 'minimum' => 0],
                    ]],
                ]],
            ],
        ],
        'TaskRequestMeta' => [
            'type' => 'object', 'required' => ['request_id'],
            'properties' => ['request_id' => ['type' => 'string', 'minLength' => 1]],
        ],
        'CrontabPositiveIntegerInput' => [
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
            ],
        ],
        'CrontabCreateRequest' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['name', 'type', 'command', 'status', 'expression'],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'type' => ['type' => 'integer', 'enum' => [1]],
                'command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'status' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'expression' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'params' => ['type' => 'string'], 'sort' => ['type' => 'integer', 'minimum' => 0],
                'remark' => ['type' => 'string'],
            ],
        ],
        'CrontabUpdateRequest' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['id', 'name', 'type', 'command', 'status', 'expression'],
            'properties' => [
                'id' => ['$ref' => '#/components/schemas/CrontabPositiveIntegerInput'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'type' => ['type' => 'integer', 'enum' => [1]],
                'command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'status' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'expression' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'params' => ['type' => 'string'], 'sort' => ['type' => 'integer', 'minimum' => 0],
                'remark' => ['type' => 'string'],
            ],
        ],
        'CrontabIdentifierRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
            'properties' => ['id' => ['$ref' => '#/components/schemas/CrontabPositiveIntegerInput']],
        ],
        'CrontabOperateRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'operate'],
            'properties' => [
                'id' => ['$ref' => '#/components/schemas/CrontabPositiveIntegerInput'],
                'operate' => ['type' => 'string', 'enum' => ['start', 'stop']],
            ],
        ],
        'TaskExpressionItem' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['time', 'date'],
            'properties' => ['time' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5], 'date' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$']],
        ],
        'CrontabDecimalValue' => [
            'oneOf' => [
                ['type' => 'number', 'minimum' => 0],
                ['type' => 'string', 'pattern' => '^[0-9]+(?:\.[0-9]+)?$'],
            ],
        ],
        'CrontabRecord' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'id', 'name', 'type', 'command', 'params', 'status', 'expression', 'error',
                'last_time', 'time', 'max_time', 'sort', 'remark', 'create_time', 'update_time',
                'delete_time', 'tenant_id', 'type_desc', 'status_desc',
            ],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 100],
                'type' => ['type' => 'integer', 'enum' => [1]],
                'command' => ['type' => 'string', 'maxLength' => 100],
                'params' => ['type' => 'string', 'maxLength' => 255],
                'status' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                'expression' => ['type' => 'string', 'maxLength' => 100],
                'error' => ['type' => 'string', 'maxLength' => 255],
                'last_time' => ['type' => 'string', 'description' => '空字符串或 Y-m-d H:i:s。'],
                'time' => ['$ref' => '#/components/schemas/CrontabDecimalValue'],
                'max_time' => ['$ref' => '#/components/schemas/CrontabDecimalValue'],
                'sort' => ['type' => 'integer', 'minimum' => 0],
                'remark' => ['type' => 'string', 'maxLength' => 255],
                'create_time' => ['type' => 'integer', 'minimum' => 0],
                'update_time' => ['type' => 'integer', 'minimum' => 0],
                'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                'type_desc' => ['type' => 'string'],
                'status_desc' => ['type' => 'string'],
            ],
        ],
        'CrontabPage' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['lists', 'count', 'pageNo', 'pageSize'],
            'properties' => [
                'lists' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CrontabRecord']],
                'count' => ['type' => 'integer', 'minimum' => 0],
                'pageNo' => ['type' => 'integer', 'minimum' => 1],
                'pageSize' => ['type' => 'integer', 'minimum' => 1],
            ],
        ],
        'CrontabListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => ['$ref' => '#/components/schemas/CrontabPage'],
            ],
        ],
        'CrontabDetailResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                'data' => ['$ref' => '#/components/schemas/CrontabRecord'],
            ],
        ],
        'TaskMutationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'maxItems' => 0]],
        ],
        'TaskExpressionResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['type' => 'array', 'minItems' => 5, 'maxItems' => 5, 'items' => ['$ref' => '#/components/schemas/TaskExpressionItem']]],
        ],
    ]],
];
