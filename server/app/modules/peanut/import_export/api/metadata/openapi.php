<?php

declare(strict_types=1);

$error = ['$ref' => '#/components/responses/ErrorResponse'];
$success = static fn(string $schema, string $description = 'Successful import/export response.'): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]],
];
$idempotency = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => [
    'type' => 'string', 'minLength' => 8, 'maxLength' => 160, 'pattern' => '^[!-~]+$',
]];
$operationKey = ['name' => 'operationKey', 'in' => 'path', 'required' => true, 'schema' => [
    'type' => 'string', 'pattern' => '^iox_[0-9a-f]{32}$',
]];

return [
    'paths' => [
        '/adminapi/peanut.import-export.operation-log.export' => ['post' => [
            'tags' => ['ImportExport'], 'operationId' => 'submitOperationLogExport',
            'parameters' => [$idempotency],
            'responses' => [
                '200' => $success('ImportExportLegacyOperationResponse', 'Operation-log export accepted.'),
                '401' => $error, '403' => $error, '409' => $error, '422' => $error, '503' => $error,
            ],
            'x-peanut-errors' => [
                'IMPORT_EXPORT_INVALID', 'IMPORT_EXPORT_PERMISSION_DENIED',
                'IMPORT_EXPORT_IDEMPOTENCY_CONFLICT', 'IMPORT_EXPORT_PROVIDER_UNAVAILABLE',
            ],
        ]],
        '/adminapi/peanut.import-export.operation.status' => ['get' => [
            'tags' => ['ImportExport'], 'operationId' => 'getLegacyImportExportOperation',
            'parameters' => [[
                'name' => 'operation_key', 'in' => 'query', 'required' => true,
                'schema' => ['type' => 'string', 'pattern' => '^iox_[0-9a-f]{32}$'],
            ]],
            'responses' => [
                '200' => $success('ImportExportLegacyOperationResponse'),
                '401' => $error, '403' => $error, '404' => $error, '422' => $error,
            ],
            'x-peanut-errors' => ['IMPORT_EXPORT_INVALID', 'IMPORT_EXPORT_PERMISSION_DENIED', 'IMPORT_EXPORT_NOT_FOUND'],
        ]],
        '/adminapi/peanut.import-export.result.download' => ['get' => [
            'tags' => ['ImportExport'], 'operationId' => 'downloadLegacyImportExportResult',
            'parameters' => [[
                'name' => 'file_key', 'in' => 'query', 'required' => true,
                'schema' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
            ]],
            'responses' => [
                '302' => ['description' => 'Redirect to the tenant-scoped result URL returned by the file gateway.', 'headers' => [
                    'Location' => ['required' => true, 'schema' => ['type' => 'string', 'format' => 'uri']],
                ]],
                '401' => $error, '403' => $error, '404' => $error, '422' => $error,
            ],
            'x-peanut-errors' => ['IMPORT_EXPORT_INVALID', 'IMPORT_EXPORT_PERMISSION_DENIED', 'IMPORT_EXPORT_FILE_UNAVAILABLE'],
        ]],
        '/adminapi/api/v1/import-export/operations' => ['get' => [
            'tags' => ['ImportExport'], 'operationId' => 'listImportExportOperations',
            'parameters' => [
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => 'queued', 'enum' => ['queued', 'running', 'cancel_requested', 'succeeded', 'failed', 'cancelled', 'expired']]],
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 20]],
            ],
            'responses' => ['200' => $success('ImportExportOperationListResponse'), '401' => $error, '403' => $error, '422' => $error, '503' => $error],
        ]],
        '/adminapi/api/v1/import-export/imports' => ['post' => [
            'tags' => ['ImportExport'], 'operationId' => 'submitImportOperation', 'parameters' => [$idempotency],
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ImportOperationRequest']]]],
            'responses' => ['201' => $success('ImportExportOperationResponse', 'Import operation accepted.'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error, '503' => $error],
        ]],
        '/adminapi/api/v1/import-export/exports' => ['post' => [
            'tags' => ['ImportExport'], 'operationId' => 'submitExportOperation', 'parameters' => [$idempotency],
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ExportOperationRequest']]]],
            'responses' => ['201' => $success('ImportExportOperationResponse', 'Export operation accepted.'), '401' => $error, '403' => $error, '409' => $error, '422' => $error, '503' => $error],
        ]],
        '/adminapi/api/v1/import-export/operations/{operationKey}/cancel' => ['post' => [
            'tags' => ['ImportExport'], 'operationId' => 'cancelImportExportOperation', 'parameters' => [$operationKey],
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['revision'],
                'properties' => ['revision' => ['type' => 'integer', 'minimum' => 1]],
            ]]]],
            'responses' => ['200' => $success('ImportExportOperationResponse'), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
        ]],
        '/adminapi/api/v1/files/{fileKey}/content' => ['get' => [
            'tags' => ['ImportExport'], 'operationId' => 'downloadImportExportResult',
            'parameters' => [[
                'name' => 'fileKey', 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
            ]],
            'responses' => [
                '302' => ['description' => 'Redirect to the short-lived tenant-scoped download URL.', 'headers' => [
                    'Location' => ['required' => true, 'schema' => ['type' => 'string', 'format' => 'uri']],
                    'Cache-Control' => ['required' => true, 'schema' => ['type' => 'string', 'enum' => ['no-store']]],
                ]],
                '401' => $error, '403' => $error, '404' => $error, '422' => $error, '503' => $error,
            ],
        ]],
    ],
    'components' => ['schemas' => [
        'ImportExportOperation' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['operation_key', 'provider_key', 'direction', 'format', 'status', 'input_file_key', 'result_file_key', 'error_file_key', 'task_job_key', 'schema_revision', 'mapping', 'processed_rows', 'accepted_rows', 'rejected_rows', 'total_rows', 'revision', 'last_error_code', 'retention_until', 'created_at', 'updated_at', 'completed_at'],
            'properties' => [
                'operation_key' => ['type' => 'string', 'pattern' => '^iox_[0-9a-f]{32}$'],
                'provider_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'],
                'direction' => ['type' => 'string', 'enum' => ['import', 'export']],
                'format' => ['type' => 'string', 'enum' => ['csv']],
                'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'cancel_requested', 'succeeded', 'failed', 'cancelled', 'expired']],
                'input_file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$', 'nullable' => true],
                'result_file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$', 'nullable' => true],
                'error_file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$', 'nullable' => true],
                'task_job_key' => ['type' => 'string', 'pattern' => '^job_[0-9a-f]{32}$', 'nullable' => true],
                'schema_revision' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9._-]{0,63}$'],
                'mapping' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$']],
                'processed_rows' => ['type' => 'integer', 'minimum' => 0], 'accepted_rows' => ['type' => 'integer', 'minimum' => 0],
                'rejected_rows' => ['type' => 'integer', 'minimum' => 0], 'total_rows' => ['type' => 'integer', 'minimum' => 0],
                'revision' => ['type' => 'integer', 'minimum' => 1],
                'last_error_code' => ['type' => 'string', 'pattern' => '^[A-Z][A-Z0-9_]{2,63}$', 'nullable' => true],
                'retention_until' => ['type' => 'string', 'format' => 'date-time'], 'created_at' => ['type' => 'string', 'format' => 'date-time'],
                'updated_at' => ['type' => 'string', 'format' => 'date-time'], 'completed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
            ],
        ],
        'ImportOperationRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['provider_key', 'file_key', 'mapping'],
            'properties' => [
                'provider_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$'],
                'file_key' => ['type' => 'string', 'pattern' => '^file_[0-9a-f]{32}$'],
                'mapping' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$']],
            ],
        ],
        'ExportOperationRequest' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['provider_key'],
            'properties' => ['provider_key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$']],
        ],
        'ImportExportMeta' => ['type' => 'object', 'required' => ['request_id'], 'properties' => ['request_id' => ['type' => 'string', 'minLength' => 1]]],
        'ImportExportOperationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => ['data' => ['$ref' => '#/components/schemas/ImportExportOperation'], 'meta' => ['$ref' => '#/components/schemas/ImportExportMeta']],
        ],
        'ImportExportLegacyOperationResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
            'properties' => [
                'code' => ['type' => 'integer', 'enum' => [20000]],
                'msg' => ['type' => 'string'],
                'data' => ['$ref' => '#/components/schemas/ImportExportOperation'],
            ],
        ],
        'ImportExportOperationListResponse' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => [
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ImportExportOperation']],
                ]],
                'meta' => ['allOf' => [
                    ['$ref' => '#/components/schemas/ImportExportMeta'],
                    ['type' => 'object', 'required' => ['page', 'page_size', 'total'], 'properties' => [
                        'page' => ['type' => 'integer', 'minimum' => 1], 'page_size' => ['type' => 'integer', 'minimum' => 1], 'total' => ['type' => 'integer', 'minimum' => 0],
                    ]],
                ]],
            ],
        ],
    ]],
];
