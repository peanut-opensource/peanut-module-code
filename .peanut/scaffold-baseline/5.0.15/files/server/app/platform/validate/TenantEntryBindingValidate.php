<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;

final class TenantEntryBindingValidate extends Validate
{
    protected $rule = [
        'tenant_id' => 'require|integer|gt:0',
        'binding_id' => 'require|integer|gt:0',
        'host' => 'require|max:253',
        'client_key' => 'require|in:admin-web,member-api',
        'change_reason' => 'require|max:500',
    ];

    protected $scene = [
        'enable' => ['tenant_id', 'host', 'client_key', 'change_reason'],
        'disable' => ['binding_id', 'change_reason'],
    ];
}
