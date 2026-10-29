<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;
use app\common\validate\PasswordRule;

final class PlatformTenantLifecycleValidate extends Validate
{
    use PasswordRule;
    protected $rule = [
        'tenant_code' => 'require|regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/|max:64',
        'tenant_name' => 'require|max:190',
        'owner_email' => 'require|email|max:255',
        'initial_password' => 'passwordPolicy',
        'owner_display_name' => 'require|max:190',
        'tenant_id' => 'require|integer|gt:0',
        'expected_revision' => 'require|integer|gt:0',
        'change_reason' => 'require|max:500',
    ];

    protected $scene = [
        'provision' => [
            'tenant_code',
            'tenant_name',
            'owner_email',
            'initial_password',
            'owner_display_name',
        ],
        'activate' => ['tenant_id', 'expected_revision', 'change_reason'],
        'suspend' => ['tenant_id', 'expected_revision', 'change_reason'],
        'close' => ['tenant_id', 'expected_revision', 'change_reason'],
    ];
}
