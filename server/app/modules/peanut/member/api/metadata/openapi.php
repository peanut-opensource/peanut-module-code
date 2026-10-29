<?php

declare(strict_types=1);

/* Contract source for Member routes. Admin and member projections retain the
 * fields emitted by the current application services and ORM serialization. */

$schema = static fn(string $name): array => ['$ref' => '#/components/schemas/' . $name];
$response = static fn(string $name): array => ['$ref' => '#/components/responses/' . $name];
$body = static fn(string $name): array => [
    'required' => true,
    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $name]]],
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
) use ($body, $ok, $response): array {
    $operation = [
        'tags' => ['Member'],
        'operationId' => $id,
        'summary' => $summary,
        'parameters' => $parameters,
        'responses' => ['200' => $ok($responseSchema, $summary . '成功')],
        'x-peanut-audience' => $audience,
    ];
    if ($requestSchema !== null) {
        $operation['requestBody'] = $body($requestSchema);
    }
    if ($audience !== 'public_tenant_module') {
        $operation['responses']['401'] = $response('ErrorResponse');
    }
    foreach ($statuses as $status) {
        $operation['responses'][(string) $status] = $response('ErrorResponse');
    }
    if ($errors !== []) {
        $operation['x-peanut-errors'] = $errors;
    }
    return $operation;
};

$idParameter = [
    'in' => 'query', 'name' => 'id', 'required' => true,
    'schema' => ['$ref' => '#/components/schemas/MemberPositiveIntegerInput'], 'example' => 17,
];
$pageParameters = [
    ['$ref' => '#/components/parameters/PageNo'],
    ['$ref' => '#/components/parameters/PageSize'],
];

return [
    'paths' => [
        '/adminapi/peanut.member.list' => ['get' => $operation(
            'listAdminMembers',
            '查询会员列表或导出结果',
            'MemberAdminListResponse',
            'tenant_admin',
            [
                ...$pageParameters,
                ['in' => 'query', 'name' => 'page', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['in' => 'query', 'name' => 'limit', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ['in' => 'query', 'name' => 'keyword', 'schema' => ['type' => 'string']],
                ['in' => 'query', 'name' => 'channel', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                ['in' => 'query', 'name' => 'create_time_start', 'schema' => ['type' => 'string']],
                ['in' => 'query', 'name' => 'create_time_end', 'schema' => ['type' => 'string']],
                ['in' => 'query', 'name' => 'status', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                ['in' => 'query', 'name' => 'export', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]],
                ['in' => 'query', 'name' => 'page_type', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                ['in' => 'query', 'name' => 'page_start', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['in' => 'query', 'name' => 'page_end', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ['in' => 'query', 'name' => 'file_name', 'schema' => ['type' => 'string']],
            ],
            errors: ['MEMBER_EXPORT_EMPTY', 'MEMBER_EXPORT_LIMIT_EXCEEDED', 'MEMBER_EXPORT_RANGE_EMPTY'],
            statuses: [400, 403],
        )],
        '/adminapi/peanut.member.detail' => ['get' => $operation(
            'getAdminMember',
            '查询会员详情',
            'MemberAdminDetailResponse',
            'tenant_admin',
            [$idParameter],
            errors: ['MEMBER_NOT_FOUND'],
            statuses: [403, 404],
        )],
        '/adminapi/peanut.member.add' => ['post' => $operation(
            'createAdminMember',
            '创建会员',
            'MemberMutationResponse',
            'tenant_admin',
            requestSchema: 'MemberAdminCreateRequest',
            errors: ['MEMBER_TAG_SELECTION_INVALID'],
            statuses: [400, 403],
        )],
        '/adminapi/peanut.member.edit' => ['post' => $operation(
            'updateAdminMemberField',
            '更新会员字段',
            'MemberMutationResponse',
            'tenant_admin',
            requestSchema: 'MemberAdminFieldUpdateRequest',
            errors: ['MEMBER_NOT_FOUND'],
            statuses: [400, 403, 404, 409],
        )],
        '/adminapi/peanut.member.update-status' => ['post' => $operation(
            'updateAdminMemberStatus',
            '更新会员状态',
            'MemberMutationResponse',
            'tenant_admin',
            requestSchema: 'MemberAdminStatusRequest',
            errors: ['MEMBER_NOT_FOUND'],
            statuses: [400, 403, 404],
        )],
        '/adminapi/peanut.member.balance.adjust' => ['post' => $operation(
            'adjustAdminMemberBalance',
            '调整会员余额',
            'MemberMutationResponse',
            'tenant_admin',
            [[
                'in' => 'header', 'name' => 'Idempotency-Key', 'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            ]],
            'MemberBalanceAdjustmentRequest',
            [
                'MEMBER_BALANCE_AMOUNT_INVALID', 'MEMBER_BALANCE_ACTION_INVALID', 'MEMBER_NOT_FOUND',
                'MEMBER_BALANCE_INSUFFICIENT', 'MEMBER_RECHARGE_TOTAL_INSUFFICIENT',
                'MEMBER_BALANCE_ADJUSTMENT_IN_PROGRESS',
            ],
            [400, 403, 404, 409],
        )],
        '/adminapi/peanut.member.tag.add' => ['post' => $operation(
            'createMemberTag',
            '创建会员标签',
            'MemberMutationResponse',
            'tenant_admin',
            requestSchema: 'MemberTagCreateRequest',
            errors: ['MEMBER_TAG_NAME_EXISTS'],
            statuses: [400, 403, 409],
        )],
        '/adminapi/peanut.member.tag.list' => ['get' => $operation(
            'listMemberTags',
            '查询会员标签',
            'MemberTagListResponse',
            'tenant_admin',
            statuses: [403],
        )],
        '/adminapi/peanut.member.tag.edit' => ['post' => $operation(
            'updateMemberTag',
            '更新会员标签',
            'MemberMutationResponse',
            'tenant_admin',
            requestSchema: 'MemberTagUpdateRequest',
            errors: ['MEMBER_TAG_NAME_EXISTS', 'MEMBER_TAG_NOT_FOUND'],
            statuses: [400, 403, 404, 409],
        )],
        '/adminapi/peanut.member.tag.delete' => ['post' => $operation(
            'deleteMemberTag',
            '删除会员标签',
            'MemberMutationResponse',
            'tenant_admin',
            requestSchema: 'MemberIdentifierRequest',
            errors: ['MEMBER_TAG_NOT_FOUND'],
            statuses: [400, 403, 404],
        )],
        '/adminapi/peanut.member.account-log.list' => ['get' => $operation(
            'listAdminMemberBalanceLogs',
            '查询会员余额流水',
            'MemberAdminBalanceLogPageResponse',
            'tenant_admin',
            [
                ...$pageParameters,
                ['in' => 'query', 'name' => 'page_type', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
                ['in' => 'query', 'name' => 'type', 'schema' => ['type' => 'string', 'enum' => ['um']]],
                ['in' => 'query', 'name' => 'change_type', 'schema' => ['type' => 'integer', 'enum' => [100, 101, 200, 201]]],
                ['in' => 'query', 'name' => 'user_info', 'schema' => ['type' => 'string']],
                ['in' => 'query', 'name' => 'start_time', 'schema' => ['type' => 'string']],
                ['in' => 'query', 'name' => 'end_time', 'schema' => ['type' => 'string']],
                ['in' => 'query', 'name' => 'export', 'schema' => ['type' => 'integer', 'enum' => [1, 2]]],
            ],
            errors: ['MEMBER_BALANCE_LOG_EXPORT_UNSUPPORTED'],
            statuses: [400, 403],
        )],
        '/adminapi/peanut.member.account-log.change-types' => ['get' => $operation(
            'getMemberBalanceChangeTypes',
            '查询会员余额变动类型',
            'MemberChangeTypesResponse',
            'tenant_admin',
            statuses: [403],
        )],
        '/api/login/register' => ['post' => $operation(
            'registerMember',
            '注册会员账号',
            'MemberMutationResponse',
            'public_tenant_module',
            requestSchema: 'MemberRegistrationRequest',
            errors: ['MEMBER_CREDENTIALS_REQUIRED', 'MEMBER_LOGIN_WAY_DISABLED'],
            statuses: [400, 403],
        )],
        '/api/login/account' => ['post' => $operation(
            'loginMemberWithAccount',
            '会员账号密码登录',
            'MemberLoginResponse',
            'public_tenant_module',
            requestSchema: 'MemberAccountLoginRequest',
            errors: ['MEMBER_CREDENTIALS_REQUIRED', 'MEMBER_LOGIN_WAY_DISABLED'],
            statuses: [400, 401, 403],
        )],
        '/api/login/mobile' => ['x-peanut-required-modules' => ['peanut.notification'], 'post' => $operation(
            'loginMemberWithMobileCode',
            '会员手机验证码登录',
            'MemberLoginResponse',
            'public_tenant_module',
            requestSchema: 'MemberMobileCodeRequest',
            errors: [
                'MEMBER_MOBILE_LOGIN_INVALID', 'MEMBER_VERIFICATION_REJECTED',
                'MEMBER_VERIFICATION_RATE_LIMITED', 'MEMBER_LOGIN_WAY_DISABLED',
            ],
            statuses: [400, 403, 429],
        )],
        '/api/login/resetPassword' => ['x-peanut-required-modules' => ['peanut.notification'], 'post' => $operation(
            'resetMemberPassword',
            '通过手机验证码重置会员密码',
            'MemberMutationResponse',
            'public_tenant_module',
            requestSchema: 'MemberPasswordResetRequest',
            errors: ['MEMBER_PASSWORD_RESET_INVALID', 'MEMBER_VERIFICATION_REJECTED', 'MEMBER_VERIFICATION_RATE_LIMITED'],
            statuses: [400, 403, 404, 429],
        )],
        '/api/user/center' => ['get' => $operation(
            'getMemberCenter',
            '查询会员中心',
            'MemberCenterResponse',
            'member',
            statuses: [403, 404],
        )],
        '/api/user/info' => ['get' => $operation(
            'getMemberProfile',
            '查询会员资料',
            'MemberProfileResponse',
            'member',
            statuses: [403, 404],
        )],
        '/api/user/setInfo' => ['post' => $operation(
            'updateMemberProfileField',
            '更新会员本人资料字段',
            'MemberMutationResponse',
            'member',
            requestSchema: 'MemberSelfFieldRequest',
            errors: ['MEMBER_PROFILE_FIELD_UNSUPPORTED', 'MEMBER_PROFILE_VALUE_INVALID', 'MEMBER_PROFILE_SELF_FORBIDDEN', 'MEMBER_NOT_FOUND'],
            statuses: [400, 403, 404],
        )],
        '/api/user/changePassword' => ['post' => $operation(
            'changeMemberPassword',
            '修改会员密码',
            'MemberMutationResponse',
            'member',
            requestSchema: 'MemberPasswordChangeRequest',
            errors: ['MEMBER_PASSWORD_CHANGE_INVALID'],
            statuses: [400, 403],
        )],
        '/api/user/bindMobile' => ['x-peanut-required-modules' => ['peanut.notification'], 'post' => $operation(
            'bindMemberMobile',
            '绑定或变更会员手机',
            'MemberMutationResponse',
            'member',
            requestSchema: 'MemberMobileCodeRequest',
            errors: ['MEMBER_MOBILE_INVALID', 'MEMBER_NOT_FOUND', 'MEMBER_VERIFICATION_REJECTED'],
            statuses: [400, 403, 404],
        )],
        '/api/account_log/lists' => ['get' => $operation(
            'listCurrentMemberBalanceLogs',
            '查询当前会员余额流水',
            'MemberSelfBalanceLogPageResponse',
            'member',
            $pageParameters,
            statuses: [403],
        )],
    ],
    'components' => [
        'schemas' => [
            'MemberPositiveIntegerInput' => [
                'oneOf' => [
                    ['type' => 'integer', 'minimum' => 1],
                    ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'],
                ],
            ],
            'MemberIdentifierRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id'],
                'properties' => ['id' => $schema('MemberPositiveIntegerInput')], 'example' => ['id' => 17],
            ],
            'MemberRegistrationRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['account', 'password'],
                'properties' => [
                    'account' => ['type' => 'string', 'minLength' => 1],
                    'password' => ['type' => 'string', 'minLength' => 1, 'format' => 'password'],
                ],
                'example' => ['account' => 'member001', 'password' => 'secret-value'],
            ],
            'MemberAccountLoginRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['account', 'password'],
                'properties' => [
                    'account' => ['type' => 'string', 'minLength' => 1],
                    'password' => ['type' => 'string', 'minLength' => 1, 'format' => 'password'],
                    'terminal' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'MemberMobileCodeRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['mobile', 'code'],
                'properties' => [
                    'mobile' => ['type' => 'string', 'pattern' => '^1[3-9][0-9]{9}$'],
                    'code' => ['type' => 'string', 'minLength' => 1],
                ],
                'example' => ['mobile' => '13800000000', 'code' => '123456'],
            ],
            'MemberPasswordResetRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['mobile', 'code', 'password'],
                'properties' => [
                    'mobile' => ['type' => 'string', 'pattern' => '^1[3-9][0-9]{9}$'],
                    'code' => ['type' => 'string', 'minLength' => 1],
                    'password' => ['type' => 'string', 'minLength' => 6, 'format' => 'password'],
                ],
            ],
            'MemberPasswordChangeRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['old_password', 'password'],
                'properties' => [
                    'old_password' => ['type' => 'string', 'minLength' => 1, 'format' => 'password'],
                    'password' => ['type' => 'string', 'minLength' => 1, 'format' => 'password'],
                ],
            ],
            'MemberSelfFieldRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['field', 'value'],
                'properties' => [
                    'field' => ['type' => 'string', 'enum' => ['nickname', 'avatar', 'sex', 'birthday', 'email']],
                    'value' => [
                        'nullable' => true,
                        'oneOf' => [
                            ['type' => 'string', 'maxLength' => 255],
                            ['type' => 'integer', 'enum' => [0, 1, 2]],
                        ],
                    ],
                ],
                'example' => ['field' => 'birthday', 'value' => '1990-01-01'],
            ],
            'MemberAdminCreateRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['nickname'],
                'properties' => [
                    'nickname' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    'mobile' => ['type' => 'string', 'maxLength' => 20, 'pattern' => '^1[3-9][0-9]{9}$'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'sex' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                    'status' => ['type' => 'integer', 'enum' => [0, 1]],
                ],
            ],
            'MemberAdminFieldUpdateRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'field', 'value'],
                'properties' => [
                    'id' => $schema('MemberPositiveIntegerInput'),
                    'field' => ['type' => 'string', 'enum' => ['account', 'sex', 'mobile', 'real_name']],
                    'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer', 'enum' => [0, 1, 2]]]],
                ],
            ],
            'MemberAdminStatusRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'status'],
                'properties' => [
                    'id' => $schema('MemberPositiveIntegerInput'),
                    'status' => ['type' => 'integer', 'enum' => [0, 1]],
                ],
            ],
            'MemberBalanceAdjustmentRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['user_id', 'action', 'num'],
                'properties' => [
                    'user_id' => $schema('MemberPositiveIntegerInput'),
                    'action' => ['type' => 'integer', 'enum' => [1, 2]],
                    'num' => [
                        'oneOf' => [
                            ['type' => 'number', 'exclusiveMinimum' => true, 'minimum' => 0],
                            ['type' => 'string', 'pattern' => '^(?:0\.[0-9]*[1-9][0-9]*|[1-9][0-9]*(?:\.[0-9]+)?)$'],
                        ],
                    ],
                    'remark' => ['type' => 'string', 'maxLength' => 128],
                ],
            ],
            'MemberTagCreateRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['name'],
                'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50]],
            ],
            'MemberTagUpdateRequest' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'],
                'properties' => [
                    'id' => $schema('MemberPositiveIntegerInput'),
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                ],
            ],
            'MemberAdminListTag' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'name', 'remark', 'create_time', 'update_time', 'delete_time', 'tenant_id'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'name' => ['type' => 'string', 'maxLength' => 50],
                    'remark' => ['type' => 'string', 'maxLength' => 255],
                    'create_time' => ['type' => 'integer', 'minimum' => 0],
                    'update_time' => ['type' => 'integer', 'minimum' => 0],
                    'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                    'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'MemberAdminListItem' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'sn', 'account', 'account_unique', 'nickname', 'avatar', 'real_name', 'mobile',
                    'channel', 'channel_value', 'email', 'sex', 'sex_value', 'birthday', 'status',
                    'login_time', 'login_ip', 'is_new_user', 'user_money', 'balance',
                    'total_recharge_amount', 'points', 'create_time', 'update_time', 'delete_time',
                    'mobile_unique', 'tenant_id', 'is_disable', 'tags', 'tag_ids',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'sn' => ['type' => 'string', 'maxLength' => 20],
                    'account' => ['type' => 'string', 'maxLength' => 50],
                    'account_unique' => ['type' => 'string', 'maxLength' => 50, 'nullable' => true],
                    'nickname' => ['type' => 'string', 'maxLength' => 50],
                    'avatar' => ['type' => 'string'],
                    'real_name' => ['type' => 'string', 'maxLength' => 32],
                    'mobile' => ['type' => 'string', 'maxLength' => 20],
                    'channel' => ['type' => 'string'],
                    'channel_value' => ['type' => 'integer', 'minimum' => 0],
                    'email' => ['type' => 'string', 'maxLength' => 100],
                    'sex' => ['type' => 'string', 'enum' => ['未知', '男', '女']],
                    'sex_value' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                    'birthday' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                    'status' => ['type' => 'integer', 'enum' => [0, 1]],
                    'login_time' => ['type' => 'string'],
                    'login_ip' => ['type' => 'string', 'maxLength' => 45],
                    'is_new_user' => ['type' => 'integer', 'enum' => [0, 1]],
                    'user_money' => ['type' => 'number', 'minimum' => 0],
                    'balance' => ['type' => 'number', 'minimum' => 0],
                    'total_recharge_amount' => ['type' => 'number', 'minimum' => 0],
                    'points' => ['type' => 'integer', 'minimum' => 0],
                    'create_time' => ['type' => 'string'],
                    'update_time' => ['type' => 'string'],
                    'delete_time' => ['type' => 'integer', 'minimum' => 0, 'nullable' => true],
                    'mobile_unique' => ['type' => 'string', 'maxLength' => 20, 'nullable' => true],
                    'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                    'is_disable' => ['type' => 'integer', 'enum' => [0, 1]],
                    'tags' => ['type' => 'array', 'items' => $schema('MemberAdminListTag')],
                    'tag_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                ],
            ],
            'MemberAdminListPage' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schema('MemberAdminListItem')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
            ],
            'MemberExportPageInfo' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['count', 'page_size', 'sum_page', 'max_page', 'all_max_size', 'page_start', 'page_end', 'file_name'],
                'properties' => [
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'sum_page' => ['type' => 'integer', 'minimum' => 1],
                    'max_page' => ['type' => 'integer', 'minimum' => 0],
                    'all_max_size' => ['type' => 'integer', 'enum' => [25000]],
                    'page_start' => ['type' => 'integer', 'enum' => [1]],
                    'page_end' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                    'file_name' => ['type' => 'string'],
                ],
            ],
            'MemberExportFile' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'file_name'],
                'properties' => ['url' => ['type' => 'string'], 'file_name' => ['type' => 'string', 'minLength' => 1]],
            ],
            'MemberAdminListResponse' => [
                'oneOf' => [
                    [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                        'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('MemberAdminListPage')],
                    ],
                    [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                        'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('MemberExportPageInfo')],
                    ],
                    [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                        'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('MemberExportFile')],
                    ],
                ],
            ],
            'MemberTagListResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]],
                    'msg' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'items' => $schema('MemberAdminListTag')],
                ],
            ],
            'MemberLoginData' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['token', 'id', 'sn', 'nickname', 'avatar', 'mobile'],
                'properties' => [
                    'token' => ['type' => 'string', 'minLength' => 1],
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'sn' => ['type' => 'string'], 'nickname' => ['type' => 'string'],
                    'avatar' => ['type' => 'string'], 'mobile' => ['type' => 'string'],
                ],
            ],
            'MemberAdminDetail' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'sn', 'account', 'nickname', 'avatar', 'real_name', 'sex', 'mobile',
                    'create_time', 'login_time', 'channel', 'user_money', 'balance',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'sn' => ['type' => 'string'], 'account' => ['type' => 'string'],
                    'nickname' => ['type' => 'string'], 'avatar' => ['type' => 'string'],
                    'real_name' => ['type' => 'string'], 'sex' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                    'mobile' => ['type' => 'string'], 'create_time' => ['type' => 'string'],
                    'login_time' => ['type' => 'string'], 'channel' => ['type' => 'string'],
                    'user_money' => ['type' => 'number'], 'balance' => ['type' => 'number'],
                ],
            ],
            'MemberCenterData' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['id', 'sn', 'nickname', 'avatar', 'mobile', 'balance', 'points', 'create_time', 'collect_num'],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1], 'sn' => ['type' => 'string'],
                    'nickname' => ['type' => 'string'], 'avatar' => ['type' => 'string'],
                    'mobile' => ['type' => 'string'], 'balance' => ['type' => 'number'],
                    'points' => ['type' => 'number'], 'create_time' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
                    'collect_num' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            'MemberProfileData' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'sn', 'account', 'nickname', 'avatar', 'sex', 'birthday', 'mobile',
                    'email', 'balance', 'points', 'create_time', 'has_password',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1], 'sn' => ['type' => 'string'],
                    'account' => ['type' => 'string'], 'nickname' => ['type' => 'string'],
                    'avatar' => ['type' => 'string'], 'sex' => ['type' => 'integer', 'enum' => [0, 1, 2]],
                    'birthday' => ['type' => 'string', 'nullable' => true], 'mobile' => ['type' => 'string'],
                    'email' => ['type' => 'string'], 'balance' => ['type' => 'number'],
                    'points' => ['type' => 'number'], 'create_time' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
                    'has_password' => ['type' => 'boolean'],
                ],
            ],
            'MemberAdminBalanceLog' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'nickname', 'account', 'sn', 'avatar', 'mobile', 'action', 'change_amount',
                    'left_amount', 'change_type', 'change_type_desc', 'source_sn', 'create_time',
                ],
                'properties' => [
                    'nickname' => ['type' => 'string'], 'account' => ['type' => 'string'],
                    'sn' => ['type' => 'string'], 'avatar' => ['type' => 'string'], 'mobile' => ['type' => 'string'],
                    'action' => ['type' => 'integer', 'enum' => [1, 2]],
                    'change_amount' => ['type' => 'string', 'pattern' => '^[+-][0-9]+\.[0-9]{2}$'],
                    'left_amount' => ['oneOf' => [['type' => 'number'], ['type' => 'string']]],
                    'change_type' => ['type' => 'integer', 'enum' => [100, 101, 200, 201]],
                    'change_type_desc' => ['type' => 'string'], 'source_sn' => ['type' => 'string'],
                    'create_time' => ['type' => 'string'],
                ],
            ],
            'MemberAdminBalanceLogPage' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schema('MemberAdminBalanceLog')],
                    'count' => ['type' => 'integer', 'minimum' => 0], 'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'MemberSelfBalanceLog' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => [
                    'id', 'sn', 'member_id', 'change_object', 'change_type', 'action',
                    'left_amount', 'source_type', 'extra', 'admin_id', 'create_time',
                    'update_time', 'delete_time', 'change_amount', 'source_sn', 'remark', 'tenant_id',
                ],
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'sn' => ['type' => 'string'],
                    'member_id' => ['type' => 'integer', 'minimum' => 1],
                    'change_object' => ['type' => 'integer', 'enum' => [1]],
                    'change_type' => ['type' => 'integer', 'enum' => [100, 101, 200, 201]],
                    'action' => ['type' => 'integer', 'enum' => [1, 2]],
                    'left_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number', 'minimum' => 0]]],
                    'source_type' => ['type' => 'integer', 'minimum' => 0],
                    'extra' => ['type' => 'string', 'nullable' => true],
                    'admin_id' => ['type' => 'integer', 'minimum' => 0],
                    'create_time' => ['oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                    'update_time' => ['nullable' => true, 'oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                    'delete_time' => ['nullable' => true, 'oneOf' => [['type' => 'integer', 'minimum' => 0], ['type' => 'string']]],
                    'change_amount' => ['oneOf' => [['type' => 'string'], ['type' => 'number', 'minimum' => 0]]],
                    'source_sn' => ['type' => 'string', 'nullable' => true],
                    'remark' => ['type' => 'string', 'nullable' => true],
                    'tenant_id' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'MemberSelfBalanceLogPage' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['lists', 'count', 'pageNo', 'pageSize'],
                'properties' => [
                    'lists' => ['type' => 'array', 'items' => $schema('MemberSelfBalanceLog')],
                    'count' => ['type' => 'integer', 'minimum' => 0],
                    'pageNo' => ['type' => 'integer', 'minimum' => 1],
                    'pageSize' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
            ],
            'MemberMutationResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                    'data' => ['type' => 'array', 'maxItems' => 0],
                ],
            ],
            'MemberLoginResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('MemberLoginData')],
            ],
            'MemberAdminDetailResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                    'data' => ['oneOf' => [$schema('MemberAdminDetail'), ['type' => 'array', 'maxItems' => 0]]],
                ],
            ],
            'MemberCenterResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['oneOf' => [$schema('MemberCenterData'), ['type' => 'array', 'maxItems' => 0]]]],
            ],
            'MemberProfileResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => ['oneOf' => [$schema('MemberProfileData'), ['type' => 'array', 'maxItems' => 0]]]],
            ],
            'MemberAdminBalanceLogPageResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('MemberAdminBalanceLogPage')],
            ],
            'MemberSelfBalanceLogPageResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => ['code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'], 'data' => $schema('MemberSelfBalanceLogPage')],
            ],
            'MemberChangeTypesResponse' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['code', 'msg', 'data'],
                'properties' => [
                    'code' => ['type' => 'integer', 'enum' => [20000]], 'msg' => ['type' => 'string'],
                    'data' => [
                        'type' => 'object',
                        'description' => '键为稳定的 change_type 数字代码，值为中文说明。',
                        'additionalProperties' => ['type' => 'string'],
                        'example' => ['100' => '平台减少余额', '200' => '平台增加余额'],
                    ],
                ],
            ],
        ],
    ],
];
