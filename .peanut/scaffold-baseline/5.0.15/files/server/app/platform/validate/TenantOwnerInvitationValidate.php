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
        'tenant_id' => 'require|integer|gt:0',
        'invitation_id' => 'require|integer|gt:0',
        'token' => 'require|regex:/^[A-Za-z0-9_-]{43}$/',
        'new_account_password' => 'passwordPolicy',
    ];

    protected $scene = [
        'provision' => ['tenant_code', 'tenant_name', 'owner_email', 'owner_display_name', 'expires_in_hours'],
        'invite' => ['tenant_id', 'owner_email', 'owner_display_name', 'expires_in_hours'],
        'lists' => ['tenant_id'],
        'resend' => ['invitation_id', 'expires_in_hours'],
        'revoke' => ['invitation_id'],
        'inspect' => ['token'],
        'accept' => ['token', 'new_account_password'],
    ];
}
