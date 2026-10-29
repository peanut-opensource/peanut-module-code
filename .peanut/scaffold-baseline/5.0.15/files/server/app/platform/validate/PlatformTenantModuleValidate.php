<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;

final class PlatformTenantModuleValidate extends Validate
{
    protected $rule = [
        'tenant_id' => 'require|integer|gt:0',
        'module_key' => 'require|regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/|max:96',
        'config' => 'array',
        'effective_at' => 'max:64',
        'expires_at' => 'max:64',
        'change_reason' => 'require|max:500',
    ];

    protected $scene = [
        'enable' => ['tenant_id', 'module_key', 'config', 'effective_at', 'expires_at', 'change_reason'],
        'disable' => ['tenant_id', 'module_key', 'change_reason'],
    ];
}
