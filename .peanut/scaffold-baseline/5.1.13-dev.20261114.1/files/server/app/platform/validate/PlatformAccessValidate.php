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
        'operator_id' => 'require|positiveInteger',
        'role_id' => 'require|positiveInteger',
        'expected_revision' => 'require|positiveInteger',
        'email' => 'require|email|max:255',
        'display_name' => 'require|max:120',
        'initial_password' => 'passwordPolicy',
        'role_ids' => 'positiveRoleIds',
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

    protected function positiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    protected function positiveRoleIds(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            return false;
        }
        foreach ($value as $id) {
            if (!$this->positiveInteger($id)) {
                return false;
            }
        }
        return true;
    }
}
