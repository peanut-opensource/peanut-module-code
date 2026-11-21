<?php

declare(strict_types=1);

/**
 * 宿主应用 OpenAPI 元数据的构造器；这里只组合已核实的请求、投影和错误响应，
 * 不维护路由清单，也不从 Controller 名称猜测业务字段。
 */
$ref = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$parameterRef = static fn(string $name): array => ['$ref' => '#/components/parameters/' . $name];
$responseRef = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];
$error = $responseRef('ErrorResponse');
$jsonBody = static fn(array $schema, bool $required = true): array => [
    'required' => $required,
    'content' => ['application/json' => ['schema' => $schema]],
];
$multipartBody = static fn(array $schema): array => [
    'required' => true,
    'content' => ['multipart/form-data' => ['schema' => $schema]],
];
$success = static fn(array $data, string $description = '成功'): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['code', 'msg', 'data'],
        'properties' => [
            'code' => ['type' => 'integer', 'enum' => [20000]],
            'msg' => ['type' => 'string'],
            'data' => $data,
        ],
    ]]],
];
$directJson = static fn(array $schema, string $description = '成功'): array => [
    'description' => $description,
    'content' => ['application/json' => ['schema' => $schema]],
];
$emptySuccess = $success([
    'type' => 'array', 'maxItems' => 0,
    'items' => $ref('ApplicationDynamicValue'),
]);
$query = static function (
    string $name,
    array $schema,
    bool $required = false,
    ?string $description = null,
): array {
    $parameter = ['in' => 'query', 'name' => $name, 'required' => $required, 'schema' => $schema];
    if ($description !== null) {
        $parameter['description'] = $description;
    }
    return $parameter;
};
$header = static function (string $name, array $schema, bool $required = true): array {
    return ['in' => 'header', 'name' => $name, 'required' => $required, 'schema' => $schema];
};
$path = static function (string $name, array $schema): array {
    return ['in' => 'path', 'name' => $name, 'required' => true, 'schema' => $schema];
};
$operation = static function (
    string $operationId,
    string $tag,
    array $responses,
    array $parameters = [],
    ?array $requestBody = null,
    array $errors = [],
    ?string $description = null,
): array {
    $value = [
        'tags' => [$tag],
        'operationId' => $operationId,
        'parameters' => $parameters,
        'responses' => $responses,
    ];
    if ($requestBody !== null) {
        $value['requestBody'] = $requestBody;
    }
    if ($errors !== []) {
        $value['x-peanut-errors'] = $errors;
    }
    if ($description !== null) {
        $value['description'] = $description;
    }
    return $value;
};

return compact(
    'ref',
    'parameterRef',
    'responseRef',
    'error',
    'jsonBody',
    'multipartBody',
    'success',
    'directJson',
    'emptySuccess',
    'query',
    'header',
    'path',
    'operation',
);
