<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;
use app\common\validate\PasswordRule;

final class TenantOwnerInvitationValidate extends Validate
{
    use PasswordRule;
    protected $rule = [
        'tenant_code' => 'require|regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/|max:64',
        'tenant_name' => 'require|max:160',
        'owner_email' => 'require|email|max:255',
        'owner_display_name' => 'require|max:120',
        'expires_in_hours' => 'integer|between:1,720',
        'tenant_id' => 'require|positiveInteger',
        'invitation_id' => 'require|positiveInteger',
        'page' => 'positiveInteger',
        'page_size' => 'positiveInteger',
        'token' => 'require|regex:/^[A-Za-z0-9_-]{43}$/',
        'new_account_password' => 'passwordPolicy',
    ];

    protected $scene = [
        'provision' => ['tenant_code', 'tenant_name', 'owner_email', 'owner_display_name', 'expires_in_hours'],
        'invite' => ['tenant_id', 'owner_email', 'owner_display_name', 'expires_in_hours'],
        'lists' => ['tenant_id', 'page', 'page_size'],
        'resend' => ['invitation_id', 'expires_in_hours'],
        'revoke' => ['invitation_id'],
        'inspect' => ['token'],
        'accept' => ['token', 'new_account_password'],
    ];

    protected function positiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
