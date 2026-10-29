<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;
use app\common\validate\PasswordRule;

final class PlatformAccessValidate extends Validate
{
    use PasswordRule;

    // Explicit empty lists revoke assignments; missing lists must still fail validation.
    protected $must = ['role_ids', 'permission_keys'];

    protected $rule = [
        'operator_id' => 'require|integer|gt:0',
        'role_id' => 'require|integer|gt:0',
        'expected_revision' => 'require|integer|gt:0',
        'email' => 'require|email|max:255',
        'display_name' => 'require|max:120',
        'initial_password' => 'passwordPolicy',
        'role_ids' => 'array|max:100',
        'permission_keys' => 'array|max:100',
        'key' => 'require|regex:/^platform\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/|max:96',
        'name' => 'require|max:120',
        'description' => 'max:500',
        'change_reason' => 'require|max:500',
    ];

    protected $scene = [
        'createOperator' => ['email', 'display_name', 'initial_password'],
        'updateOperator' => ['operator_id', 'expected_revision', 'display_name', 'change_reason'],
        'replaceOperatorRoles' => ['operator_id', 'role_ids', 'expected_revision', 'change_reason'],
        'activateOperator' => ['operator_id', 'expected_revision', 'change_reason'],
        'suspendOperator' => ['operator_id', 'expected_revision', 'change_reason'],
        'closeOperator' => ['operator_id', 'expected_revision', 'change_reason'],
        'createRole' => ['key', 'name', 'description'],
        'updateRole' => ['role_id', 'expected_revision', 'name', 'description', 'change_reason'],
        'archiveRole' => ['role_id', 'expected_revision', 'change_reason'],
        'replaceRolePermissions' => ['role_id', 'permission_keys', 'expected_revision', 'change_reason'],
    ];
}
