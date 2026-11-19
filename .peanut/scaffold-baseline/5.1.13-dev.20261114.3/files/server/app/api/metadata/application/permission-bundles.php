<?php

declare(strict_types=1);

$m = require __DIR__ . '/common.php';
extract($m, EXTR_SKIP);

$strings = ['type' => 'array', 'items' => ['type' => 'string']];
$bundle = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['key', 'name', 'module_key', 'source', 'audience', 'status', 'revision', 'configured', 'api_ids'],
    'properties' => [
        'key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'module_key' => ['type' => 'string'],
        'source' => ['type' => 'string', 'enum' => ['system', 'module', 'custom']],
        'audience' => ['type' => 'string', 'enum' => ['tenant', 'platform']],
        'status' => ['type' => 'string', 'enum' => ['active', 'retired']],
        'revision' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
        'configured' => ['type' => 'boolean'], 'api_ids' => $strings,
    ]];
$api = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['id', 'method', 'path', 'audience', 'module_key', 'access_mode', 'status', 'default_permission_keys', 'prerequisite_permission_keys'],
    'properties' => [
        'id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
        'method' => ['type' => 'string'], 'path' => ['type' => 'string'],
        'audience' => ['type' => 'string', 'enum' => ['tenant', 'platform', 'member', 'installation']],
        'module_key' => ['type' => 'string'], 'access_mode' => ['type' => 'string'],
        'status' => ['type' => 'string', 'enum' => ['active', 'retired']], 'default_permission_keys' => $strings,
        'prerequisite_permission_keys' => $strings,
    ]];
$page = static fn(array $item): array => ['type' => 'object', 'additionalProperties' => false,
    'required' => ['items', 'total', 'page_no', 'page_size'], 'properties' => [
        'items' => ['type' => 'array', 'items' => $item], 'total' => ['type' => 'integer'],
        'page_no' => ['type' => 'integer'], 'page_size' => ['type' => 'integer'],
    ]];
$params = [
    $query('audience', ['type' => 'string', 'enum' => ['tenant', 'platform']]),
    $query('page_no', ['type' => 'integer', 'minimum' => 1]),
    $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]),
    $query('search', ['type' => 'string', 'maxLength' => 120]),
];
$keyParam = [$query('permission_key', ['type' => 'string', 'minLength' => 1, 'maxLength' => 160], true)];
$responses = static fn(array $data): array => ['200' => $success($data), '401' => $error, '403' => $error, '404' => $error, '422' => $error];
$paths = [];
$menuBinding = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['menu_key', 'parent_key', 'type', 'name', 'page_path', 'action_key', 'permission_key', 'default_permission_key', 'revision'],
    'properties' => ['menu_key' => ['type' => 'string'], 'parent_key' => ['type' => 'string', 'nullable' => true],
        'type' => ['type' => 'string', 'enum' => ['C', 'A']], 'name' => ['type' => 'string'],
        'page_path' => ['type' => 'string'], 'action_key' => ['type' => 'string'], 'permission_key' => ['type' => 'string'],
        'default_permission_key' => ['type' => 'string', 'nullable' => true], 'revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']]];
$add = static function (array &$paths, string $method, string $path, array $operation): void {
    $paths[$path][strtolower($method)] = $operation;
};
$add($paths, 'GET', '/platformapi/permission/bundles', $operation('listPermissionBundles', 'PermissionBundles', $responses($page($ref('PermissionBundle'))), $params));
$add($paths, 'GET', '/platformapi/permission/menus', $operation('listMenuPermissionBindings', 'PermissionBundles', $responses(['type' => 'object', 'additionalProperties' => false,
    'required' => ['items', 'total'], 'properties' => ['items' => ['type' => 'array', 'items' => $menuBinding], 'total' => ['type' => 'integer']]])));
$add($paths, 'PUT', '/platformapi/permission/menus/binding', $operation('replaceMenuPermissionBinding', 'PermissionBundles', $responses($menuBinding) + ['409' => $error, '412' => $error, '428' => $error],
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['menu_key', 'permission_key', 'action_key', 'expected_revision'],
        'properties' => ['menu_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
            'permission_key' => ['type' => 'string', 'nullable' => true, 'maxLength' => 160], 'action_key' => ['type' => 'string', 'nullable' => true, 'maxLength' => 200],
            'expected_revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']]])));
$nameSchema = ['type' => 'string', 'minLength' => 1, 'maxLength' => 160];
$keySchema = ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];
$revisionSchema = ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,17}$']]];
$add($paths, 'POST', '/platformapi/permission/bundles', $operation('createPermissionBundle', 'PermissionBundles', $responses($ref('PermissionBundle')) + ['409' => $error],
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'name', 'audience'], 'properties' => [
        'key' => $keySchema, 'name' => $nameSchema, 'audience' => ['type' => 'string', 'enum' => ['tenant', 'platform']],
    ]])));
$add($paths, 'PUT', '/platformapi/permission/bundles', $operation('updatePermissionBundle', 'PermissionBundles', $responses($ref('PermissionBundle')) + ['409' => $error, '412' => $error, '428' => $error],
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['permission_key', 'name', 'status', 'expected_revision'], 'properties' => [
        'permission_key' => $keySchema, 'name' => $nameSchema, 'status' => ['type' => 'string', 'enum' => ['active', 'retired']], 'expected_revision' => $revisionSchema,
    ]])));
$add($paths, 'GET', '/platformapi/permission/apis', $operation('listRegisteredApis', 'PermissionBundles', $responses($page($ref('RegisteredPermissionApi'))), [...$params, $query('status', ['type' => 'string', 'enum' => ['active', 'retired']])]));
$add($paths, 'GET', '/platformapi/permission/apis/bindings', $operation('getPermissionApiBindings', 'PermissionBundles', $responses($ref('PermissionBundle')), $keyParam));
$add($paths, 'PUT', '/platformapi/permission/apis/bindings', $operation('replacePermissionApiBindings', 'PermissionBundles',
    $responses($ref('PermissionBundle')) + ['409' => $error, '412' => $error, '428' => $error],
    requestBody: $jsonBody(['type' => 'object', 'additionalProperties' => false, 'required' => ['permission_key', 'expected_revision', 'api_ids'],
        'properties' => ['permission_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
            'expected_revision' => ['oneOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,17}$']]],
            'api_ids' => ['type' => 'array', 'maxItems' => 2000, 'items' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']]]]),
    errors: ['API_NOT_BINDABLE', 'PERMISSION_RETIRED', 'API_INPUT_INVALID', 'API_MANAGEMENT_RECOVERY_REQUIRED']));
$add($paths, 'GET', '/adminapi/permission/bundles/apis', $operation('getTenantPermissionBundleApis', 'PermissionBundles', $responses($ref('PermissionBundle')), $keyParam));

return ['paths' => $paths, 'components' => ['schemas' => ['PermissionBundle' => $bundle, 'RegisteredPermissionApi' => $api]]];
