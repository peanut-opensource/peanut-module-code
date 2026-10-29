<?php

declare(strict_types=1);

$m = require __DIR__ . '/common.php';
extract($m, EXTR_SKIP);

$schemas = [
    'AdminLoginRequest' => [
        'type' => 'object',
        'required' => ['password', 'terminal'],
        'anyOf' => [['required' => ['account']], ['required' => ['username']]],
        'properties' => [
            'account' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
            'username' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
            'password' => ['type' => 'string', 'format' => 'password', 'minLength' => 1],
            'terminal' => ['type' => 'integer', 'enum' => [1, 2]],
            'tenant_code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,63}$'],
        ],
    ],
    'TenantChoice' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['tenant_id', 'tenant_code', 'tenant_name', 'tenant_member_id', 'member_display_name'],
        'properties' => [
            'tenant_id' => $ref('NumericStringId'),
            'tenant_code' => ['type' => 'string'],
            'tenant_name' => ['type' => 'string'],
            'tenant_member_id' => $ref('NumericStringId'),
            'member_display_name' => ['type' => 'string'],
        ],
    ],
    'TenantSelectionData' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'challenge_token', 'expires_at', 'tenants'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['tenant_selection_required']],
            'challenge_token' => ['type' => 'string'],
            'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            'tenants' => ['type' => 'array', 'minItems' => 1, 'items' => $ref('TenantChoice')],
        ],
    ],
    'TenantContextData' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['audience', 'account_id', 'tenant_id', 'tenant_member_id'],
        'properties' => [
            'audience' => ['type' => 'string', 'enum' => ['tenant']],
            'account_id' => $ref('NumericStringId'),
            'tenant_id' => $ref('NumericStringId'),
            'tenant_member_id' => $ref('NumericStringId'),
            'authorization_revision' => ['type' => 'string', 'pattern' => '^[0-9]+$'],
        ],
    ],
    'TenantAuthenticationData' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'access_token', 'token_type', 'expires_in', 'context'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['authenticated']],
            'access_token' => ['type' => 'string'],
            'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
            'expires_in' => ['type' => 'integer', 'minimum' => 1],
            'context' => $ref('TenantContextData'),
        ],
    ],
    'AdminAuthenticatedLoginData' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['state', 'token', 'access_token', 'token_type', 'expires_in', 'admin_id', 'account', 'username', 'name', 'avatar', 'role_name', 'terminal', 'context'],
        'properties' => [
            'state' => ['type' => 'string', 'enum' => ['authenticated']],
            'token' => ['type' => 'string'],
            'access_token' => ['type' => 'string'],
            'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
            'expires_in' => ['type' => 'integer', 'minimum' => 1],
            'admin_id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
            'account' => ['type' => 'string'],
            'username' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'avatar' => ['type' => 'string'],
            'role_name' => ['type' => 'string'],
            'terminal' => ['type' => 'integer', 'enum' => [1, 2]],
            'context' => $ref('TenantContextData'),
        ],
    ],
    'AdminLoginData' => ['oneOf' => [$ref('AdminAuthenticatedLoginData'), $ref('TenantSelectionData')]],
    'TenantSessionLoginRequest' => [
        'type' => 'object', 'required' => ['email', 'password'],
        'properties' => [
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
            'password' => ['type' => 'string', 'format' => 'password'],
            'tenant_code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,63}$'],
        ],
    ],
    'TenantSessionSelectRequest' => [
        'type' => 'object', 'required' => ['challenge_token', 'tenant_id'],
        'properties' => [
            'challenge_token' => ['type' => 'string', 'minLength' => 1],
            'tenant_id' => ['type' => 'integer', 'minimum' => 1],
        ],
    ],
    'TenantAuthHttpResponse' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['data', 'meta'],
        'properties' => [
            'data' => ['oneOf' => [$ref('TenantAuthenticationData'), $ref('TenantSelectionData')]],
            'meta' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['request_id'],
                'properties' => ['request_id' => ['type' => 'string']],
            ],
        ],
    ],
    'AdminInfo' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'username', 'nickname', 'name', 'avatar', 'role', 'root', 'roles', 'menu', 'permissions', 'tenantName', 'website', 'canSwitchTenant', 'demoMode'],
        'properties' => [
            'id' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
            'username' => ['type' => 'string'], 'nickname' => ['type' => 'string'],
            'name' => ['type' => 'string'], 'avatar' => ['type' => 'string'],
            'role' => ['type' => 'string', 'enum' => ['admin', 'user']],
            'root' => ['type' => 'boolean'],
            'roles' => $ref('StringList'),
            'menu' => ['type' => 'array', 'items' => $ref('AdminMenuNode')],
            'permissions' => $ref('StringList'),
            'tenantName' => ['type' => 'string'],
            'website' => $ref('WebsiteConfig'),
            'canSwitchTenant' => ['type' => 'boolean'],
            'demoMode' => ['type' => 'boolean'],
        ],
    ],
    'AdminRecord' => [
        'type' => 'object',
        'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'account', 'username', 'name', 'nickname', 'avatar', 'avatar_uri', 'root', 'disable', 'disable_desc', 'multipoint_login', 'login_time', 'login_ip', 'create_time', 'update_time', 'role_id', 'role_ids', 'dept_id', 'jobs_id', 'role_name', 'dept_name', 'jobs_name', 'roles'],
        'properties' => [
            'id' => ['type' => 'integer'], 'account' => ['type' => 'string'], 'username' => ['type' => 'string'],
            'name' => ['type' => 'string'], 'nickname' => ['type' => 'string'], 'avatar' => ['type' => 'string'], 'avatar_uri' => ['type' => 'string'],
            'root' => ['type' => 'integer'], 'disable' => ['type' => 'integer', 'enum' => [0, 1]],
            'disable_desc' => ['type' => 'string'], 'multipoint_login' => ['type' => 'integer', 'enum' => [0, 1]],
            'login_time' => ['type' => 'string'], 'login_ip' => ['type' => 'string'],
            'create_time' => ['type' => 'string'], 'update_time' => ['type' => 'string'],
            'role_id' => $ref('IntegerList'), 'role_ids' => $ref('IntegerList'), 'dept_id' => $ref('IntegerList'),
            'jobs_id' => $ref('IntegerList'), 'role_name' => ['type' => 'string'], 'dept_name' => ['type' => 'string'],
            'jobs_name' => ['type' => 'string'], 'roles' => ['type' => 'array', 'items' => $ref('ApplicationDynamicValue')],
        ],
    ],
    'AdminListData' => [
        'oneOf' => [
            [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $ref('AdminRecord')],
                    'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            $ref('ExportPageInfo'), $ref('ExportFile'),
        ],
    ],
    'AdminWriteRequest' => [
        'type' => 'object',
        'required' => ['account', 'name', 'role_id', 'disable', 'multipoint_login'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1], 'account' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
            'username' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120], 'nickname' => ['type' => 'string'],
            'avatar' => ['type' => 'string', 'maxLength' => 512], 'password' => ['type' => 'string', 'description' => 'Effective UTF-8 byte bounds from getPasswordPolicy.'],
            'password_confirm' => ['type' => 'string'], 'role_id' => $ref('IntegerList'), 'role_ids' => $ref('IntegerList'),
            'dept_id' => $ref('IntegerList'), 'jobs_id' => $ref('IntegerList'),
            'disable' => ['type' => 'integer', 'enum' => [0, 1]], 'multipoint_login' => ['type' => 'integer', 'enum' => [0, 1]],
        ],
    ],
    'AdminSelfEditRequest' => [
        'type' => 'object', 'required' => ['nickname'],
        'properties' => [
            'nickname' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
            'avatar' => ['type' => 'string', 'maxLength' => 255],
            'password_old' => ['type' => 'string'], 'password' => ['type' => 'string', 'description' => 'Effective UTF-8 byte bounds from getPasswordPolicy.'],
            'password_confirm' => ['type' => 'string'],
        ],
    ],
    'AdminMenuNode' => [
        'type' => 'object',
        'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'menu_key', 'parent_key', 'name'],
        'properties' => [
            'id' => ['type' => 'integer'], 'menu_key' => ['type' => 'string', 'maxLength' => 160], 'parent_key' => ['type' => 'string', 'nullable' => true, 'maxLength' => 160], 'name' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['M', 'C', 'A']], 'icon' => ['type' => 'string'],
            'sort' => ['type' => 'integer'], 'perms' => ['type' => 'string'], 'paths' => ['type' => 'string'],
            'component' => ['type' => 'string'], 'is_cache' => ['type' => 'integer', 'enum' => [0, 1]],
            'is_show' => ['type' => 'integer', 'enum' => [0, 1]], 'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
            'module_key' => ['type' => 'string'], 'managed' => ['type' => 'boolean'],
            'children' => ['type' => 'array', 'items' => $ref('AdminMenuNode')],
        ],
    ],
    'AdminMenuWriteRequest' => [
        'type' => 'object', 'required' => ['name', 'type'],
        'properties' => [
            'menu_key' => ['type' => 'string', 'maxLength' => 160], 'name' => ['type' => 'string', 'maxLength' => 50],
            'type' => ['type' => 'string', 'enum' => ['M', 'C', 'A']], 'parent_key' => ['type' => 'string', 'nullable' => true, 'maxLength' => 160],
            'icon' => ['type' => 'string', 'maxLength' => 100], 'sort' => ['type' => 'integer'],
            'perms' => ['type' => 'string', 'maxLength' => 100], 'paths' => ['type' => 'string', 'maxLength' => 200],
            'component' => ['type' => 'string', 'maxLength' => 200], 'is_cache' => ['type' => 'integer', 'enum' => [0, 1]],
            'is_show' => ['type' => 'integer', 'enum' => [0, 1]], 'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
        ],
    ],
    'AdminRole' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'name', 'desc', 'sort', 'create_time', 'num', 'menu_keys', 'status', 'revision'],
        'properties' => [
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'desc' => ['type' => 'string'],
            'sort' => ['type' => 'integer'], 'create_time' => ['type' => 'string'], 'num' => ['type' => 'integer', 'minimum' => 0],
            'menu_keys' => $ref('StringList'), 'status' => ['type' => 'string'],
            'revision' => ['type' => 'integer', 'minimum' => 1],
        ],
    ],
    'AdminRoleWriteRequest' => [
        'type' => 'object', 'required' => ['name'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            'desc' => ['type' => 'string'], 'menu_keys' => $ref('StringList'),
        ],
    ],
    'Department' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['id', 'pid', 'code', 'name', 'leader', 'mobile', 'sort', 'status', 'is_disable', 'status_desc', 'revision'],
        'properties' => [
            'id' => ['type' => 'integer'], 'pid' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'],
            'leader' => ['type' => 'string'], 'mobile' => ['type' => 'string'], 'sort' => ['type' => 'integer'],
            'status' => ['type' => 'integer', 'enum' => [0, 1]], 'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
            'status_desc' => ['type' => 'string'], 'revision' => ['type' => 'integer', 'minimum' => 1],
            'level' => ['type' => 'integer', 'minimum' => 0], 'children' => ['type' => 'array', 'items' => $ref('Department')],
        ],
    ],
    'DepartmentWriteRequest' => [
        'type' => 'object', 'required' => ['name', 'pid', 'status'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            'pid' => ['type' => 'integer', 'minimum' => 0], 'leader' => ['type' => 'string', 'maxLength' => 50],
            'mobile' => ['type' => 'string', 'maxLength' => 20], 'sort' => ['type' => 'integer', 'minimum' => 0],
            'status' => ['type' => 'integer', 'enum' => [0, 1]], 'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
        ],
    ],
    'Job' => [
        'type' => 'object',
        'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'name', 'code', 'sort', 'status', 'is_disable', 'status_desc', 'create_time', 'update_time'],
        'properties' => [
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'code' => ['type' => 'string'],
            'sort' => ['type' => 'integer'], 'remark' => ['type' => 'string'], 'status' => ['type' => 'integer', 'enum' => [0, 1]],
            'is_disable' => ['type' => 'integer', 'enum' => [0, 1]], 'status_desc' => ['type' => 'string'],
            'create_time' => ['type' => 'string'], 'update_time' => ['type' => 'string'],
        ],
    ],
    'JobWriteRequest' => [
        'type' => 'object', 'required' => ['name', 'code', 'status'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
            'code' => ['type' => 'string', 'maxLength' => 64], 'sort' => ['type' => 'integer', 'minimum' => 0],
            'remark' => ['type' => 'string', 'maxLength' => 200], 'status' => ['type' => 'integer', 'enum' => [0, 1]],
            'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
        ],
    ],
    'WebsiteConfig' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo', 'pc_logo', 'pc_title', 'pc_ico', 'pc_desc', 'pc_keywords', 'h5_favicon', 'slogan', 'copyright', 'official_url', 'github_url'],
        'properties' => array_fill_keys([
            'name', 'web_favicon', 'web_logo', 'login_image', 'shop_name', 'shop_logo', 'pc_logo', 'pc_title',
            'pc_ico', 'pc_desc', 'pc_keywords', 'h5_favicon', 'slogan', 'copyright', 'official_url', 'github_url',
        ], ['type' => 'string']),
    ],
    'AdminBrandConfig' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['website', 'tenantName', 'demo'],
        'properties' => [
            'website' => $ref('WebsiteConfig'),
            'tenantName' => ['type' => 'string'],
            'demo' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['enabled', 'email', 'password'],
                'properties' => ['enabled' => ['type' => 'boolean'], 'email' => ['type' => 'string'], 'password' => ['type' => 'string']],
            ],
        ],
    ],
    'CopyrightItemList' => [
        'type' => 'array', 'maxItems' => 20, 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'value'],
            'properties' => ['key' => ['type' => 'string', 'maxLength' => 60], 'value' => ['type' => 'string', 'maxLength' => 500]],
        ],
    ],
    'CopyrightConfig' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['config'],
        'properties' => ['config' => $ref('CopyrightItemList')],
    ],
    'AgreementConfig' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['service_title', 'service_content', 'privacy_title', 'privacy_content'],
        'properties' => [
            'service_title' => ['type' => 'string', 'maxLength' => 100], 'service_content' => ['type' => 'string', 'maxLength' => 200000],
            'privacy_title' => ['type' => 'string', 'maxLength' => 100], 'privacy_content' => ['type' => 'string', 'maxLength' => 200000],
        ],
    ],
    'StatisticsConfig' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['clarity_code'],
        'properties' => ['clarity_code' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]*$', 'maxLength' => 64]],
    ],
    'MemberProfileConfig' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['default_avatar'],
        'properties' => ['default_avatar' => ['type' => 'string', 'maxLength' => 500]],
    ],
    'LoginConfig' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['login_way', 'coerce_mobile', 'login_agreement', 'third_auth', 'wechat_auth'],
        'properties' => [
            'login_way' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'integer', 'enum' => [1, 2]]],
            'coerce_mobile' => ['type' => 'integer', 'enum' => [0, 1]], 'login_agreement' => ['type' => 'integer', 'enum' => [0, 1]],
            'third_auth' => ['type' => 'integer', 'enum' => [0, 1]], 'wechat_auth' => ['type' => 'integer', 'enum' => [0, 1]],
        ],
    ],
    'DecorationPageSummary' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'type', 'name', 'update_time'],
        'properties' => [
            'id' => ['type' => 'integer'], 'type' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
            'name' => ['type' => 'string'], 'update_time' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
        ],
    ],
    'DecorationPage' => [
        'type' => 'object',
        'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'type', 'name', 'data', 'meta'],
        'properties' => [
            'id' => ['type' => 'integer'], 'type' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]], 'name' => ['type' => 'string'],
            'data' => ['type' => 'array', 'items' => $ref('ApplicationDynamicValue')],
            'meta' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
        ],
    ],
    'DecorationPageSaveRequest' => [
        'type' => 'object', 'required' => ['id', 'type', 'data'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1], 'type' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
            'data' => ['type' => 'array', 'items' => $ref('ApplicationDynamicValue')],
            'meta' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
        ],
    ],
    'DecorationTabbar' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['style', 'list'],
        'properties' => [
            'style' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
            'list' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
                'required' => ['name', 'selected', 'unselected', 'link', 'is_show'],
                'properties' => [
                    'name' => ['type' => 'string'], 'selected' => ['type' => 'string'], 'unselected' => ['type' => 'string'],
                    'link' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
                    'is_show' => ['type' => 'integer', 'enum' => [0, 1]],
                ],
            ]],
        ],
    ],
    'ArticleOption' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'title'],
        'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'image' => ['type' => 'string']],
    ],
    'GeneratorTable' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['table_name'],
        'properties' => [
            'id' => ['type' => 'integer'], 'table_name' => ['type' => 'string'], 'table_comment' => ['type' => 'string'],
            'module_name' => ['type' => 'string'], 'entity_name' => ['type' => 'string'], 'template_type' => ['type' => 'string', 'enum' => ['crud', 'tree']],
            'data_owner' => ['type' => 'string', 'enum' => ['tenant-orm', 'platform', 'instance', 'shared']],
            'target_edition' => ['type' => 'string', 'enum' => ['standalone', 'multi-tenant']],
            'columns' => ['type' => 'array', 'items' => $ref('GeneratorColumn')],
            'relations' => ['type' => 'array', 'items' => $ref('GeneratorRelation')],
        ],
    ],
    'GeneratorColumn' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'column_name'],
        'properties' => [
            'id' => ['type' => 'integer'], 'column_name' => ['type' => 'string'], 'column_comment' => ['type' => 'string'],
            'column_type' => ['type' => 'string'], 'php_type' => ['type' => 'string'],
            'is_required' => ['type' => 'integer', 'enum' => [0, 1]], 'is_pk' => ['type' => 'integer', 'enum' => [0, 1]],
            'is_insert' => ['type' => 'integer', 'enum' => [0, 1]], 'is_update' => ['type' => 'integer', 'enum' => [0, 1]],
            'is_lists' => ['type' => 'integer', 'enum' => [0, 1]], 'is_query' => ['type' => 'integer', 'enum' => [0, 1]],
            'query_type' => ['type' => 'string'], 'view_type' => ['type' => 'string'], 'dict_type' => ['type' => 'string'],
        ],
    ],
    'GeneratorRelation' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['target_table_id', 'name', 'type', 'local_key', 'foreign_key'],
        'properties' => [
            'target_table_id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['belongsTo', 'hasOne', 'hasMany']],
            'local_key' => ['type' => 'string'], 'foreign_key' => ['type' => 'string'],
            'module' => ['type' => 'string'], 'model' => ['type' => 'string'], 'data_owner' => ['type' => 'string'], 'target_edition' => ['type' => 'string'],
        ],
    ],
    'GeneratorUpdateRequest' => [
        'type' => 'object', 'required' => ['id', 'table_comment', 'module_name', 'entity_name', 'template_type', 'data_owner', 'target_edition', 'columns'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1], 'table_comment' => ['type' => 'string', 'maxLength' => 300],
            'module_name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,31}$'],
            'entity_name' => ['type' => 'string', 'pattern' => '^[A-Z][A-Za-z0-9]{0,63}$'],
            'template_type' => ['type' => 'string', 'enum' => ['crud', 'tree']],
            'data_owner' => ['type' => 'string', 'enum' => ['tenant-orm', 'platform', 'instance', 'shared']],
            'target_edition' => ['type' => 'string', 'enum' => ['standalone', 'multi-tenant']],
            'author' => ['type' => 'string', 'maxLength' => 100],
            'tree_config' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'id_field' => ['type' => 'string'], 'parent_field' => ['type' => 'string'], 'name_field' => ['type' => 'string'],
            ]],
            'relations' => ['type' => 'array', 'maxItems' => 20, 'items' => $ref('GeneratorRelation')],
            'columns' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 300, 'items' => $ref('GeneratorColumn')],
        ],
    ],
    'GeneratorPreviewFile' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['path', 'content'],
        'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']],
    ],
    'GeneratorDownload' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['download_token', 'file_name', 'expires_in'],
        'properties' => [
            'download_token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], 'file_name' => ['type' => 'string'],
            'expires_in' => ['type' => 'integer', 'enum' => [600]],
        ],
    ],
    'OperationLog' => [
        'type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue'),
        'required' => ['id', 'username', 'ip', 'uri', 'method', 'params', 'create_time'],
        'properties' => [
            'id' => ['type' => 'integer'], 'username' => ['type' => 'string'], 'ip' => ['type' => 'string'],
            'uri' => ['type' => 'string'], 'method' => ['type' => 'string'], 'params' => ['type' => 'string'],
            'create_time' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
        ],
    ],
    'HotSearchConfig' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['status', 'data'],
        'properties' => [
            'status' => ['type' => 'integer', 'enum' => [0, 1]],
            'data' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'sort'],
                'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'sort' => ['type' => 'integer']],
            ]],
        ],
    ],
    'TransactionConfig' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['cancel_unpaid_orders', 'cancel_unpaid_orders_times', 'verification_orders', 'verification_orders_times'],
        'properties' => [
            'cancel_unpaid_orders' => ['type' => 'integer', 'enum' => [0, 1]], 'cancel_unpaid_orders_times' => ['type' => 'integer', 'minimum' => 1],
            'verification_orders' => ['type' => 'integer', 'enum' => [0, 1]], 'verification_orders_times' => ['type' => 'integer', 'minimum' => 1],
        ],
    ],
    'SystemInfo' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['server', 'env', 'auth'],
        'properties' => [
            'server' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['param', 'value'],
                'properties' => ['param' => ['type' => 'string'], 'value' => ['type' => 'string']],
            ]],
            'env' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['option', 'require', 'status', 'remark'],
                'properties' => ['option' => ['type' => 'string'], 'require' => ['type' => 'string'], 'status' => ['type' => 'integer', 'enum' => [0, 1]], 'remark' => ['type' => 'string']],
            ]],
            'auth' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['dir', 'require', 'status', 'remark'],
                'properties' => ['dir' => ['type' => 'string'], 'require' => ['type' => 'string'], 'status' => ['type' => 'integer', 'enum' => [0, 1]], 'remark' => ['type' => 'string']],
            ]],
        ],
    ],
    'Workbench' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['version', 'today', 'menu', 'visitor', 'support', 'sale'],
        'properties' => [
            'version' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
            'today' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            'menu' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'image', 'url'], 'properties' => [
                'name' => ['type' => 'string'], 'image' => ['type' => 'string'], 'url' => ['type' => 'string'],
            ]]],
            'visitor' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
            'support' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['image', 'title', 'desc', 'url'], 'properties' => [
                'image' => ['type' => 'string'], 'title' => ['type' => 'string'], 'desc' => ['type' => 'string'], 'url' => ['type' => 'string'],
            ]]],
            'sale' => ['type' => 'object', 'additionalProperties' => $ref('ApplicationDynamicValue')],
        ],
    ],
    'InvitationInspectRequest' => [
        'type' => 'object', 'required' => ['token'],
        'properties' => ['token' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43}$']],
    ],
    'InvitationInspection' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['tenant_name', 'display_name', 'email_hint', 'status', 'delivery_status', 'expires_at', 'requires_password'],
        'properties' => [
            'tenant_name' => ['type' => 'string'], 'display_name' => ['type' => 'string'], 'email_hint' => ['type' => 'string'],
            'status' => ['type' => 'string'], 'delivery_status' => ['type' => 'string'], 'expires_at' => ['type' => 'string', 'format' => 'date-time'],
            'requires_password' => ['type' => 'boolean'],
        ],
    ],
    'InvitationAcceptRequest' => [
        'type' => 'object', 'required' => ['token'],
        'properties' => [
            'token' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43}$'],
            'new_account_password' => ['type' => 'string', 'description' => 'Effective UTF-8 byte bounds from getPasswordPolicy.'],
        ],
    ],
    'InvitationAcceptance' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['invitation_id', 'tenant_id', 'account_id', 'member_id', 'role_id', 'status', 'tenant_status'],
        'properties' => [
            'invitation_id' => ['type' => 'integer'], 'tenant_id' => ['type' => 'integer'], 'account_id' => ['type' => 'integer'],
            'member_id' => ['type' => 'integer'], 'role_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['accepted']],
            'tenant_status' => ['type' => 'string'],
        ],
    ],
];

$policyId = ['anyOf' => [['type' => 'integer', 'minimum' => 1], ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,17}$']]];
$permissionKeys = ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160]];
$schemas['AdminRolePermissions'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['role_id', 'key', 'revision', 'permission_keys'], 'properties' => [
        'role_id' => $ref('NumericStringId'), 'key' => ['type' => 'string'],
        'revision' => $ref('NumericStringId'), 'permission_keys' => $permissionKeys,
    ]];
$schemas['AdminRolePermissionsReplaceRequest'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['role_id', 'expected_revision', 'permission_keys'], 'properties' => [
        'role_id' => $policyId, 'expected_revision' => $policyId,
        'permission_keys' => $permissionKeys + ['maxItems' => 1000],
    ]];
$schemas['AdminRolePermissionCatalogPage'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['items', 'total', 'page_no', 'page_size'], 'properties' => [
        'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['key', 'name', 'module_key'], 'properties' => [
                'key' => ['type' => 'string'], 'name' => ['type' => 'string'], 'module_key' => ['type' => 'string'],
            ]]],
        'total' => ['type' => 'integer', 'minimum' => 0], 'page_no' => ['type' => 'integer', 'minimum' => 1],
        'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
    ]];
$policyConfig = ['anyOf' => [['type' => 'object', 'nullable' => true, 'maxProperties' => 0, 'additionalProperties' => false], ['type' => 'array', 'nullable' => true, 'maxItems' => 0, 'items' => ['type' => 'string']]]];
$policyTarget = ['type' => 'object', 'additionalProperties' => false, 'required' => ['target_id'],
    'properties' => ['target_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160]]];
$schemas['AdminDataPolicyTargetSetWrite'] = ['type' => 'object', 'additionalProperties' => false, 'nullable' => true,
    'required' => ['name', 'target_resource_key', 'targets'], 'properties' => [
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
        'target_resource_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
        'targets' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 500, 'items' => $policyTarget],
    ]];
$schemas['AdminDataPolicyConditionWrite'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['condition_key'], 'properties' => [
        'condition_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
        'target_set' => $ref('AdminDataPolicyTargetSetWrite'), 'config' => $policyConfig,
    ]];
$schemas['AdminDataPolicyGroupWrite'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['name', 'conditions'], 'properties' => [
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
        'conditions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $ref('AdminDataPolicyConditionWrite')],
    ]];
$schemas['AdminDataPolicyWrite'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['status', 'groups'], 'properties' => [
        'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
        'reason' => ['type' => 'string', 'maxLength' => 300, 'nullable' => true],
        'valid_from' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'valid_until' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
        'groups' => ['type' => 'array', 'maxItems' => 50, 'items' => $ref('AdminDataPolicyGroupWrite')],
    ]];
$schemas['AdminDataPolicyReplaceRequest'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['role_id', 'resource_key', 'operation', 'policy'], 'properties' => [
        'role_id' => $policyId, 'resource_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
        'operation' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 96],
        'expected_revision' => ['anyOf' => [['type' => 'integer', 'minimum' => 1, 'nullable' => true], ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,17}$', 'nullable' => true]]], 'policy' => $ref('AdminDataPolicyWrite'),
    ]];
$schemas['AdminDataPolicyTargetSet'] = ['type' => 'object', 'additionalProperties' => false, 'nullable' => true,
    'required' => ['id', 'name', 'target_mode', 'target_resource_key', 'status', 'revision', 'targets'], 'properties' => [
        'id' => $ref('NumericStringId'), 'name' => ['type' => 'string'], 'target_mode' => ['type' => 'string'],
        'target_resource_key' => ['type' => 'string'], 'status' => ['type' => 'string'],
        'revision' => $ref('NumericStringId'), 'targets' => ['type' => 'array', 'items' => $policyTarget],
    ]];
$schemas['AdminDataPolicyCondition'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['id', 'condition_key', 'status', 'revision', 'target_set', 'config'], 'properties' => [
        'id' => $ref('NumericStringId'), 'condition_key' => ['type' => 'string'], 'status' => ['type' => 'string'],
        'revision' => $ref('NumericStringId'), 'target_set' => $ref('AdminDataPolicyTargetSet'), 'config' => $policyConfig,
    ]];
$schemas['AdminDataPolicyGroup'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['id', 'name', 'match_mode', 'sort_order', 'status', 'revision', 'conditions'], 'properties' => [
        'id' => $ref('NumericStringId'), 'name' => ['type' => 'string'], 'match_mode' => ['type' => 'string'],
        'sort_order' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'revision' => $ref('NumericStringId'),
        'conditions' => ['type' => 'array', 'items' => $ref('AdminDataPolicyCondition')],
    ]];
$schemas['AdminDataPolicy'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['id', 'tenant_id', 'role_id', 'resource_key', 'operation', 'status', 'valid_from', 'valid_until', 'revision', 'reason', 'created_at', 'updated_at', 'groups'], 'properties' => [
        'id' => $ref('NumericStringId'), 'tenant_id' => $ref('NumericStringId'), 'role_id' => $ref('NumericStringId'),
        'resource_key' => ['type' => 'string'], 'operation' => ['type' => 'string'],
        'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
        'valid_from' => ['type' => 'string', 'nullable' => true], 'valid_until' => ['type' => 'string', 'nullable' => true],
        'revision' => $ref('NumericStringId'), 'reason' => ['type' => 'string', 'nullable' => true],
        'created_at' => ['type' => 'string'], 'updated_at' => ['type' => 'string'],
        'groups' => ['type' => 'array', 'items' => $ref('AdminDataPolicyGroup')],
    ]];
$schemas['AdminDataPolicyOperation'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['resource_key', 'module_key', 'operation', 'access_mode', 'target_cardinality'], 'properties' => [
        'resource_key' => ['type' => 'string'], 'module_key' => ['type' => 'string'], 'operation' => ['type' => 'string'],
        'access_mode' => ['type' => 'string'], 'target_cardinality' => ['type' => 'string'],
    ]];
$schemas['AdminDataPolicyCatalogPage'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['items', 'total', 'page_no', 'page_size'], 'properties' => [
        'items' => ['type' => 'array', 'items' => $ref('AdminDataPolicyOperation')], 'total' => ['type' => 'integer'],
        'page_no' => ['type' => 'integer'], 'page_size' => ['type' => 'integer'],
    ]];
$schemas['AdminDataPolicyOperationConditions'] = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['resource_key', 'operation', 'target_cardinality', 'conditions'], 'properties' => [
        'resource_key' => ['type' => 'string'], 'operation' => ['type' => 'string'], 'target_cardinality' => ['type' => 'string'],
        'conditions' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['condition_key', 'target_mode', 'selector_resource_key'], 'properties' => [
                'condition_key' => ['type' => 'string'], 'target_mode' => ['type' => 'string'],
                'selector_resource_key' => ['type' => 'string', 'nullable' => true],
            ]]],
    ]];

$ok = static fn(string $schema): array => ['200' => $success($ref($schema)), '401' => $error, '403' => $error];
$mutation = ['200' => $emptySuccess, '401' => $error, '403' => $error, '422' => $error];
$menuKeySchema = ['type' => 'string', 'minLength' => 1, 'maxLength' => 160];
$menuKeyQuery = [$query('menu_key', $menuKeySchema, true)];
$menuKeyBody = $jsonBody(['type' => 'object', 'required' => ['menu_key'], 'properties' => ['menu_key' => $menuKeySchema]]);
$menuStatusBody = $jsonBody(['type' => 'object', 'required' => ['menu_key', 'is_disable'], 'properties' => ['menu_key' => $menuKeySchema, 'is_disable' => ['type' => 'integer', 'enum' => [0, 1]]]]);
$idQuery = [$query('id', ['type' => 'integer', 'minimum' => 1], true)];
$idBody = $jsonBody(['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]]]);
$statusBody = static fn(string $field): array => $jsonBody([
    'type' => 'object', 'required' => ['id', $field],
    'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], $field => ['type' => 'integer', 'enum' => [0, 1]]],
]);
$pageParams = [$parameterRef('PageNo'), $parameterRef('PageSize')];
$paths = [];
$add = static function (array &$paths, string $method, string $pathName, array $contract): void {
    $paths[$pathName][strtolower($method)] = $contract;
};

$add($paths, 'GET', '/adminapi/config/brand', $operation('getAdminEntryBrand', 'AdminAuth', [
    '200' => $success($ref('AdminBrandConfig')), '403' => $error, '503' => $error,
], description: 'Anonymous Admin Host branding. Shared unbound entries return application defaults; bound entries use only their admin-web Tenant.'));

// 会话、当前身份与公开 Tenant owner 邀请。
$add($paths, 'POST', '/adminapi/user/logout', $operation('adminLogout', 'AdminAuth', ['200' => $emptySuccess]));
$add($paths, 'POST', '/adminapi/user/info', $operation('getAdminSessionInfo', 'AdminAuth', $ok('AdminInfo')));
$adminMenuList = ['type' => 'array', 'items' => $ref('AdminMenuNode')];
$add($paths, 'POST', '/adminapi/user/menu', $operation('getAdminSessionMenu', 'AdminAuth', ['200' => $success($adminMenuList), '401' => $error, '403' => $error]));
$add($paths, 'GET', '/adminapi/login/info', $operation('getAdminLoginInfo', 'AdminAuth', $ok('AdminInfo')));
$add($paths, 'POST', '/adminapi/tenant/session/login', $operation('tenantSessionLogin', 'TenantSession', ['200' => $directJson($ref('TenantAuthHttpResponse')), '401' => $error, '403' => $error], requestBody: $jsonBody($ref('TenantSessionLoginRequest')), errors: ['TENANT_AUTHENTICATION_REJECTED']));
$add($paths, 'POST', '/adminapi/tenant/session/switch', $operation('createTenantSwitchChallenge', 'TenantSession', ['200' => $directJson($ref('TenantAuthHttpResponse')), '401' => $error, '403' => $error], errors: ['TENANT_SWITCH_REJECTED']));
$add($paths, 'POST', '/adminapi/tenant/session/refresh', $operation('refreshTenantSession', 'TenantSession', ['200' => $directJson($ref('TenantAuthHttpResponse')), '401' => $error, '403' => $error], parameters: [
    ['in' => 'header', 'name' => 'Authorization', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^Bearer pa_tat_\\S+$'], 'description' => '发起刷新会话的 access token，允许过期或已轮换；必须与受保护刷新 cookie 属于同一会话。'],
], errors: ['TENANT_REFRESH_CREDENTIAL_INVALID']));
$add($paths, 'POST', '/adminapi/tenant/session/logout', $operation('logoutTenantSession', 'TenantSession', ['204' => ['description' => '会话已撤销，响应无 body。'], '401' => $error, '403' => $error], errors: ['TENANT_SESSION_INVALID']));
$add($paths, 'GET', '/adminapi/tenant/owner-invitations/inspect', $operation('inspectTenantOwnerInvitation', 'TenantInvitation', ['200' => $success($ref('InvitationInspection')), '403' => $error, '404' => $error, '410' => $error, '422' => $error], [$query('token', ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43}$'], true)]));
$add($paths, 'POST', '/adminapi/tenant/owner-invitations/accept', $operation('acceptTenantOwnerInvitation', 'TenantInvitation', ['200' => $success($ref('InvitationAcceptance')), '403' => $error, '404' => $error, '409' => $error, '410' => $error, '422' => $error], requestBody: $jsonBody($ref('InvitationAcceptRequest'))));

// 管理员。
$adminListParams = [...$pageParams, $query('account', ['type' => 'string']), $query('name', ['type' => 'string']), $query('role_id', ['type' => 'integer', 'minimum' => 1]), $query('export', ['type' => 'integer', 'enum' => [1, 2]])];
$add($paths, 'GET', '/adminapi/admin/lists', $operation('listAdministrators', 'Administrators', $ok('AdminListData'), $adminListParams));
$add($paths, 'GET', '/adminapi/admin/detail', $operation('getAdministrator', 'Administrators', $ok('AdminRecord'), $idQuery));
$add($paths, 'GET', '/adminapi/admin/self', $operation('getCurrentAdministrator', 'Administrators', $ok('AdminRecord')));
$add($paths, 'POST', '/adminapi/admin/editSelf', $operation('updateCurrentAdministrator', 'Administrators', $mutation, requestBody: $jsonBody($ref('AdminSelfEditRequest'))));
$add($paths, 'POST', '/adminapi/admin/add', $operation('createAdministrator', 'Administrators', $mutation + ['409' => $error], requestBody: $jsonBody($ref('AdminWriteRequest')), errors: ['ADMIN_ROLE_REQUIRED']));
$add($paths, 'POST', '/adminapi/admin/edit', $operation('updateAdministrator', 'Administrators', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody($ref('AdminWriteRequest')), errors: ['ADMIN_PASSWORD_SELF_SERVICE_REQUIRED', 'ADMIN_ROLE_REQUIRED']));
$add($paths, 'POST', '/adminapi/admin/delete', $operation('deleteAdministrator', 'Administrators', $mutation + ['404' => $error, '409' => $error], requestBody: $idBody, errors: ['ADMIN_SELF_OPERATION_FORBIDDEN']));
$add($paths, 'POST', '/adminapi/admin/status', $operation('setAdministratorStatus', 'Administrators', $mutation + ['404' => $error, '409' => $error], requestBody: $statusBody('disable'), errors: ['ADMIN_SELF_OPERATION_FORBIDDEN']));

// 菜单与角色。
$add($paths, 'GET', '/adminapi/menu/route', $operation('getAdministratorMenuRoute', 'AdminMenus', ['200' => $success($adminMenuList), '401' => $error, '403' => $error]));
$add($paths, 'GET', '/adminapi/menu/lists', $operation('listAdminMenus', 'AdminMenus', ['200' => $success($adminMenuList), '401' => $error, '403' => $error]));
$add($paths, 'GET', '/adminapi/menu/all', $operation('listAssignableAdminMenus', 'AdminMenus', ['200' => $success($adminMenuList), '401' => $error, '403' => $error]));
$add($paths, 'GET', '/adminapi/menu/detail', $operation('getAdminMenu', 'AdminMenus', $ok('AdminMenuNode'), $menuKeyQuery));
$add($paths, 'POST', '/adminapi/menu/add', $operation('createAdminMenu', 'AdminMenus', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody($ref('AdminMenuWriteRequest')), errors: ['ADMIN_MENU_PARENT_INVALID', 'ADMIN_MENU_PARENT_NOT_FOUND', 'ADMIN_MENU_HIERARCHY_INVALID']));
$add($paths, 'POST', '/adminapi/menu/edit', $operation('updateAdminMenu', 'AdminMenus', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody(['allOf' => [$ref('AdminMenuWriteRequest'), ['type' => 'object', 'required' => ['menu_key']]]]), errors: ['ADMIN_MENU_NOT_FOUND', 'ADMIN_MENU_PARENT_INVALID', 'ADMIN_MENU_PARENT_NOT_FOUND', 'ADMIN_MENU_HIERARCHY_INVALID']));
$add($paths, 'POST', '/adminapi/menu/delete', $operation('deleteAdminMenu', 'AdminMenus', $mutation + ['404' => $error, '409' => $error], requestBody: $menuKeyBody, errors: ['ADMIN_MENU_NOT_FOUND', 'ADMIN_MENU_HAS_CHILDREN', 'ADMIN_MENU_IN_USE', 'MANAGED_MENU_DELETE_FORBIDDEN']));
$add($paths, 'POST', '/adminapi/menu/status', $operation('setAdminMenuStatus', 'AdminMenus', $mutation + ['404' => $error], requestBody: $menuStatusBody, errors: ['ADMIN_MENU_NOT_FOUND']));
$rolePage = [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
    'properties' => ['lists' => ['type' => 'array', 'items' => $ref('AdminRole')], 'count' => ['type' => 'integer'], 'pageNo' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer']],
];
$add($paths, 'GET', '/adminapi/role/lists', $operation('listAdminRoles', 'AdminRoles', ['200' => $success($rolePage), '401' => $error, '403' => $error], $pageParams));
$add($paths, 'GET', '/adminapi/role/all', $operation('listAllAdminRoles', 'AdminRoles', ['200' => $success(['type' => 'array', 'items' => $ref('AdminRole')]), '401' => $error, '403' => $error]));
$add($paths, 'GET', '/adminapi/role/detail', $operation('getAdminRole', 'AdminRoles', $ok('AdminRole'), $idQuery));
$add($paths, 'POST', '/adminapi/role/add', $operation('createAdminRole', 'AdminRoles', $mutation + ['409' => $error], requestBody: $jsonBody($ref('AdminRoleWriteRequest'))));
$add($paths, 'POST', '/adminapi/role/edit', $operation('updateAdminRole', 'AdminRoles', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody($ref('AdminRoleWriteRequest'))));
$add($paths, 'POST', '/adminapi/role/delete', $operation('archiveAdminRole', 'AdminRoles', $mutation + ['404' => $error, '409' => $error], requestBody: $idBody));
$add($paths, 'GET', '/adminapi/role/permissions', $operation('getAdminRolePermissions', 'AdminRoles', $ok('AdminRolePermissions') + ['404' => $error, '422' => $error], [
    ['name' => 'role_id', 'in' => 'query', 'required' => true, 'schema' => $policyId],
]));
$add($paths, 'PUT', '/adminapi/role/permissions', $operation('replaceAdminRolePermissions', 'AdminRoles', $ok('AdminRolePermissions') + ['404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error], requestBody: $jsonBody($ref('AdminRolePermissionsReplaceRequest'))));
$add($paths, 'GET', '/adminapi/role/permissions/catalog', $operation('getAdminRolePermissionCatalog', 'AdminRoles', $ok('AdminRolePermissionCatalogPage') + ['422' => $error], [
    ['name' => 'page_no', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
    ['name' => 'page_size', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
    ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 120]],
]));
$policyQuery = [$query('role_id', $policyId, true), $query('resource_key', ['type' => 'string', 'minLength' => 1, 'maxLength' => 160], true), $query('operation', ['type' => 'string', 'minLength' => 1, 'maxLength' => 96], true)];
$add($paths, 'GET', '/adminapi/role/data-policy', $operation('getAdminRoleDataPolicy', 'AdminRoles', $ok('AdminDataPolicy') + ['404' => $error, '422' => $error], $policyQuery));
$add($paths, 'PUT', '/adminapi/role/data-policy', $operation('replaceAdminRoleDataPolicy', 'AdminRoles', $ok('AdminDataPolicy') + ['404' => $error, '409' => $error, '412' => $error, '422' => $error, '428' => $error], requestBody: $jsonBody($ref('AdminDataPolicyReplaceRequest'))));
$add($paths, 'GET', '/adminapi/role/data-policy/catalog', $operation('getAdminRoleDataPolicyCatalog', 'AdminRoles', ['200' => $success(['oneOf' => [$ref('AdminDataPolicyCatalogPage'), $ref('AdminDataPolicyOperationConditions')]]), '401' => $error, '403' => $error, '404' => $error, '422' => $error], [
    $query('resource_key', ['type' => 'string', 'minLength' => 1, 'maxLength' => 160]), $query('operation', ['type' => 'string', 'minLength' => 1, 'maxLength' => 96]),
    $query('page_no', ['type' => 'integer', 'minimum' => 1]), $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]),
]));

// 部门与岗位。
$directoryNode = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['id', 'pid', 'name', 'sort', 'status', 'status_desc', 'realStatus', 'structuralOnly', 'selectable', 'hasChildren'],
    'properties' => [
        'id' => ['type' => 'integer'], 'pid' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'sort' => ['type' => 'integer'],
        'status' => ['type' => 'integer', 'enum' => [-1, 0, 1]], 'status_desc' => ['type' => 'string'],
        'realStatus' => ['type' => 'string', 'enum' => ['active', 'disabled', 'archived']],
        'structuralOnly' => ['type' => 'boolean'], 'selectable' => ['type' => 'boolean'], 'hasChildren' => ['type' => 'boolean'],
        'code' => ['type' => 'string'], 'leader' => ['type' => 'string'], 'mobile' => ['type' => 'string'],
        'revision' => ['type' => 'integer'], 'create_time' => ['type' => 'string', 'format' => 'date-time'], 'update_time' => ['type' => 'string', 'format' => 'date-time'],
    ]];
$departmentList = ['type' => 'object', 'additionalProperties' => false,
    'required' => ['lists', 'ancestors', 'count', 'pageNo', 'pageSize', 'hasMore'],
    'properties' => ['lists' => ['type' => 'array', 'maxItems' => 100, 'items' => $directoryNode],
        'ancestors' => ['type' => 'array', 'items' => $directoryNode], 'count' => ['type' => 'integer'],
        'pageNo' => ['type' => 'integer', 'minimum' => 1], 'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        'hasMore' => ['type' => 'boolean']]];
$directoryParams = [
    $query('mode', ['type' => 'string', 'enum' => ['children', 'search', 'resolve']], true),
    $query('pageNo', ['type' => 'integer', 'minimum' => 1], true),
    $query('pageSize', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], true),
    $query('parent_id', ['type' => 'integer', 'minimum' => 0]), $query('name', ['type' => 'string', 'maxLength' => 120]),
    $query('status', ['type' => 'integer', 'enum' => [0, 1]]), $query('ids', ['type' => 'string', 'description' => 'Resolve only: comma-separated positive ids, at most pageSize.']),
];
$directoryResponses = ['200' => $success($departmentList), '400' => $error, '401' => $error, '403' => $error, '404' => $error, '409' => $error];
$add($paths, 'GET', '/adminapi/dept/lists', $operation(
    'listDepartments',
    'Departments',
    $directoryResponses,
    $directoryParams,
));
$add($paths, 'GET', '/adminapi/dept/all', $operation('listActiveDepartments', 'Departments', $directoryResponses, $directoryParams));
$flatDirectoryParams = $directoryParams;
$flatDirectoryParams[0] = $query('mode', ['type' => 'string', 'enum' => ['search', 'resolve']], true);
$add($paths, 'GET', '/adminapi/dept/leaderDept', $operation('listDepartmentLeaderOptions', 'Departments', $directoryResponses, $flatDirectoryParams));
$add($paths, 'GET', '/adminapi/dept/leader-candidates', $operation(
    'listDepartmentLeaderCandidates', 'Departments',
    ['200' => $success(['type' => 'object', 'additionalProperties' => false,
        'required' => ['items', 'total', 'page_no', 'page_size'], 'properties' => [
            'items' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object',
                'additionalProperties' => false, 'required' => ['id', 'name'],
                'properties' => ['id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string']]]],
            'total' => ['type' => 'integer', 'minimum' => 0], 'page_no' => ['type' => 'integer', 'minimum' => 1],
            'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
        ]]), '401' => $error, '403' => $error, '404' => $error, '409' => $error, '422' => $error],
    [$query('department_id', ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,17}$'], true),
        $query('page_no', ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,17}$', 'default' => '1']),
        $query('page_size', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]),
        $query('search', ['type' => 'string', 'maxLength' => 120, 'default' => ''])],
    errors: ['DEPARTMENT_LEADER_INPUT_INVALID', 'DEPARTMENT_INACTIVE'],
    description: 'Requires department update permission and an active department in the current tenant. Returns active tenant members whose accounts are active, ordered by member id; search is a literal display-name substring. Only the four declared query keys are accepted; page offset must fit PHP_INT_MAX.',
));
$add($paths, 'GET', '/adminapi/dept/detail', $operation('getDepartment', 'Departments', $ok('Department'), $idQuery));
foreach (['add' => 'createDepartment', 'edit' => 'updateDepartment'] as $route => $operationId) {
    $add($paths, 'POST', '/adminapi/dept/' . $route, $operation($operationId, 'Departments', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody($ref('DepartmentWriteRequest'))));
}
$add($paths, 'POST', '/adminapi/dept/delete', $operation('archiveDepartment', 'Departments', $mutation + ['404' => $error, '409' => $error], requestBody: $idBody));
$add($paths, 'POST', '/adminapi/dept/status', $operation('setDepartmentStatus', 'Departments', $mutation + ['404' => $error, '409' => $error], requestBody: $statusBody('status')));
$jobPage = [
    'oneOf' => [[
        'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
        'properties' => ['lists' => ['type' => 'array', 'items' => $ref('Job')], 'count' => ['type' => 'integer'], 'pageNo' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer']],
    ], $ref('ExportPageInfo'), $ref('ExportFile')],
];
$jobListParams = [...$pageParams, $query('code', ['type' => 'string']), $query('name', ['type' => 'string']), $query('status', ['type' => 'integer', 'enum' => [0, 1]]), $query('export', ['type' => 'integer', 'enum' => [1, 2]])];
$add($paths, 'GET', '/adminapi/jobs/lists', $operation('listJobs', 'Jobs', ['200' => $success($jobPage), '401' => $error, '403' => $error], $jobListParams));
$add($paths, 'GET', '/adminapi/jobs/all', $operation('listActiveJobs', 'Jobs', ['200' => $success(['type' => 'array', 'items' => $ref('Job')]), '401' => $error, '403' => $error]));
$add($paths, 'GET', '/adminapi/jobs/detail', $operation('getJob', 'Jobs', $ok('Job'), $idQuery));
foreach (['add' => 'createJob', 'edit' => 'updateJob'] as $route => $operationId) {
    $add($paths, 'POST', '/adminapi/jobs/' . $route, $operation($operationId, 'Jobs', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody($ref('JobWriteRequest')), errors: ['ADMIN_JOB_NAME_EXISTS', 'ADMIN_JOB_CODE_EXISTS']));
}
$add($paths, 'POST', '/adminapi/jobs/delete', $operation('deleteJob', 'Jobs', $mutation + ['404' => $error], requestBody: $idBody, errors: ['ADMIN_JOB_NOT_FOUND']));
$add($paths, 'POST', '/adminapi/jobs/status', $operation('setJobStatus', 'Jobs', $mutation + ['404' => $error], requestBody: $statusBody('status'), errors: ['ADMIN_JOB_NOT_FOUND']));

// Tenant 配置与装修。
$configContracts = [
    'website' => ['WebsiteConfig', 'getWebsiteConfig', 'saveWebsiteConfig'],
    'agreement' => ['AgreementConfig', 'getAgreementConfig', 'saveAgreementConfig'],
    'statistics' => ['StatisticsConfig', 'getStatisticsConfig', 'saveStatisticsConfig'],
    'user' => ['MemberProfileConfig', 'getMemberProfileConfig', 'saveMemberProfileConfig'],
    'login' => ['LoginConfig', 'getLoginConfig', 'saveLoginConfig'],
];
foreach ($configContracts as $route => [$schema, $getId, $saveId]) {
    $add($paths, 'GET', '/adminapi/config/' . $route, $operation($getId, 'TenantConfiguration', $ok($schema)));
    $saveResponse = $route === 'website' ? $ok($schema) : $mutation;
    $add($paths, 'POST', '/adminapi/config/' . $route . '/save', $operation($saveId, 'TenantConfiguration', $saveResponse, requestBody: $jsonBody($ref($schema))));
}
$add($paths, 'GET', '/adminapi/config/copyright', $operation('getCopyrightConfig', 'TenantConfiguration', $ok('CopyrightItemList')));
$add($paths, 'POST', '/adminapi/config/copyright/save', $operation('saveCopyrightConfig', 'TenantConfiguration', $mutation, requestBody: $jsonBody($ref('CopyrightConfig'))));
$pageListResponse = ['200' => $success(['type' => 'array', 'items' => $ref('DecorationPageSummary')]), '401' => $error, '403' => $error];
foreach (['mobile' => 'Mobile', 'pc' => 'Pc'] as $kind => $label) {
    $add($paths, 'GET', "/adminapi/decoration/{$kind}/page/lists", $operation("list{$label}DecorationPages", 'Decoration', $pageListResponse));
    $add($paths, 'GET', "/adminapi/decoration/{$kind}/page/detail", $operation("get{$label}DecorationPage", 'Decoration', $ok('DecorationPage') + ['404' => $error], $idQuery, errors: ['DECORATION_PAGE_NOT_FOUND']));
    $add($paths, 'POST', "/adminapi/decoration/{$kind}/page/save", $operation("save{$label}DecorationPage", 'Decoration', $mutation + ['404' => $error, '409' => $error], requestBody: $jsonBody($ref('DecorationPageSaveRequest')), errors: ['DECORATION_PAGE_WRITE_FORBIDDEN', 'DECORATION_PAGE_INVALID', 'DECORATION_PAGE_NOT_FOUND', 'DECORATION_PAGE_TYPE_IMMUTABLE']));
}
$add($paths, 'GET', '/adminapi/decoration/mobile/article', $operation(
    'listDecorationArticleOptions',
    'Decoration',
    ['200' => $success(['type' => 'array', 'items' => $ref('ArticleOption')]), '401' => $error, '403' => $error],
    [$query('limit', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20])],
));
$add($paths, 'GET', '/adminapi/decoration/tabbar/detail', $operation('getDecorationTabbar', 'Decoration', $ok('DecorationTabbar')));
$add($paths, 'POST', '/adminapi/decoration/tabbar/save', $operation('saveDecorationTabbar', 'Decoration', $mutation, requestBody: $jsonBody($ref('DecorationTabbar')), errors: ['DECORATION_TABBAR_INVALID']));

// 生成器；下载返回二进制文件而不是 JSON envelope。
$generatorPage = static fn(string $item): array => [
    'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
    'properties' => ['lists' => ['type' => 'array', 'items' => $ref($item)], 'count' => ['type' => 'integer'], 'pageNo' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer']],
];
$generatorParams = [...$pageParams, $query('keyword', ['type' => 'string', 'maxLength' => 100])];
$add($paths, 'GET', '/adminapi/generator/source-tables', $operation('listGeneratorSourceTables', 'Generator', ['200' => $success($generatorPage('GeneratorTable')), '401' => $error, '403' => $error], $generatorParams));
$add($paths, 'GET', '/adminapi/generator/lists', $operation('listGeneratorImports', 'Generator', ['200' => $success($generatorPage('GeneratorTable')), '401' => $error, '403' => $error], $generatorParams));
$add($paths, 'GET', '/adminapi/generator/detail', $operation('getGeneratorImport', 'Generator', $ok('GeneratorTable'), $idQuery));
$add($paths, 'POST', '/adminapi/generator/import', $operation('importGeneratorTables', 'Generator', $mutation, requestBody: $jsonBody(['type' => 'object', 'required' => ['table_names'], 'properties' => ['table_names' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]{0,63}$']]]])));
$add($paths, 'POST', '/adminapi/generator/sync', $operation('syncGeneratorImport', 'Generator', $mutation, requestBody: $idBody));
$add($paths, 'POST', '/adminapi/generator/update', $operation('updateGeneratorImport', 'Generator', $mutation, requestBody: $jsonBody($ref('GeneratorUpdateRequest'))));
$idsBody = $jsonBody(['type' => 'object', 'required' => ['ids'], 'properties' => ['ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => ['type' => 'integer', 'minimum' => 1]]]]);
$add($paths, 'POST', '/adminapi/generator/delete', $operation('deleteGeneratorImports', 'Generator', $mutation + ['409' => $error], requestBody: $idsBody));
$add($paths, 'POST', '/adminapi/generator/preview', $operation('previewGeneratorImport', 'Generator', ['200' => $success(['type' => 'array', 'items' => $ref('GeneratorPreviewFile')]), '401' => $error, '403' => $error, '422' => $error], requestBody: $idBody));
$add($paths, 'POST', '/adminapi/generator/generate', $operation('generateApplicationCode', 'Generator', ['200' => $success($ref('GeneratorDownload')), '401' => $error, '403' => $error, '409' => $error, '422' => $error], requestBody: $idsBody));
$add($paths, 'GET', '/adminapi/generator/download', $operation('downloadGeneratedApplicationCode', 'Generator', [
    '200' => ['description' => '一次性 ZIP 下载', 'headers' => ['Content-Disposition' => ['schema' => ['type' => 'string']]], 'content' => ['application/zip' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
    '401' => $error, '403' => $error, '422' => $error,
], [$query('token', ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], true)]));
$add($paths, 'GET', '/adminapi/generator/models', $operation('listGeneratorModels', 'Generator', ['200' => $success(['type' => 'array', 'items' => $ref('GeneratorTable')]), '401' => $error, '403' => $error]));

// 运维信息、日志、应用设置与工作台。
$logPage = [
    'oneOf' => [[
        'type' => 'object', 'additionalProperties' => false, 'required' => ['lists', 'count', 'pageNo', 'pageSize'],
        'properties' => ['lists' => ['type' => 'array', 'items' => $ref('OperationLog')], 'count' => ['type' => 'integer'], 'pageNo' => ['type' => 'integer'], 'pageSize' => ['type' => 'integer']],
    ], $ref('ExportPageInfo'), $ref('ExportFile')],
];
$logParams = [...$pageParams, $query('username', ['type' => 'string']), $query('uri', ['type' => 'string']), $query('method', ['type' => 'string']), $query('ip', ['type' => 'string']), $query('start_time', ['type' => 'string']), $query('end_time', ['type' => 'string']), $query('export', ['type' => 'integer', 'enum' => [1, 2]])];
$add($paths, 'GET', '/adminapi/log/lists', $operation('listOperationLogs', 'OperationLogs', ['200' => $success($logPage), '401' => $error, '403' => $error], $logParams));
$add($paths, 'POST', '/adminapi/log/clear', $operation('clearOperationLogs', 'OperationLogs', $mutation));
$add($paths, 'GET', '/adminapi/setting/hot-search/config', $operation('getHotSearchConfig', 'TenantSettings', $ok('HotSearchConfig')));
$add($paths, 'POST', '/adminapi/setting/hot-search/save', $operation('saveHotSearchConfig', 'TenantSettings', $mutation, requestBody: $jsonBody($ref('HotSearchConfig'))));
$add($paths, 'GET', '/adminapi/setting/transaction/config', $operation('getTransactionConfig', 'TenantSettings', $ok('TransactionConfig')));
$add($paths, 'POST', '/adminapi/setting/transaction/save', $operation('saveTransactionConfig', 'TenantSettings', $mutation, requestBody: $jsonBody($ref('TransactionConfig')), errors: ['TRANSACTION_CANCEL_MODE_INVALID', 'TRANSACTION_VERIFY_MODE_INVALID', 'TRANSACTION_CANCEL_DELAY_INVALID', 'TRANSACTION_VERIFY_DELAY_INVALID']));
$add($paths, 'GET', '/adminapi/system/info', $operation('getSystemInformation', 'System', $ok('SystemInfo')));
$add($paths, 'POST', '/adminapi/system/clearCache', $operation('clearSystemCache', 'System', $mutation));
$add($paths, 'GET', '/adminapi/workbench/index', $operation('getAdminWorkbench', 'Workbench', $ok('Workbench')));

$overrides = [
    '/adminapi/user/login' => ['post' => $operation(
        'userLogin',
        'AdminAuth',
        ['200' => $success($ref('AdminLoginData')), '401' => $error, '403' => $error, '422' => $error],
        requestBody: $jsonBody($ref('AdminLoginRequest')),
        errors: ['ADMIN_LOGIN_REJECTED'],
    )],
    '/adminapi/tenant/session/select' => ['post' => $operation(
        'selectTenant',
        'TenantSession',
        ['200' => $directJson($ref('TenantAuthHttpResponse')), '403' => $error, '422' => $error],
        requestBody: $jsonBody($ref('TenantSessionSelectRequest')),
        errors: ['TENANT_SELECTION_REJECTED'],
    )],
    '/adminapi/readiness/checklist' => ['get' => $operation(
        'getFirstRunReadinessChecklist',
        'Readiness',
        ['200' => $success($ref('ReadinessChecklist')), '401' => $error, '403' => $error],
        description: '只读返回当前 Tenant 的首次运行清单；配置值不等同外部连通或生产资格。',
    )],
];

$paths['/adminapi/decoration/mobile/article']['x-peanut-required-modules'] = ['peanut.article'];
return ['paths' => $paths, 'overrides' => $overrides, 'components' => ['schemas' => $schemas]];
