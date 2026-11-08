<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;

final class TenantEntryBindingValidate extends Validate
{
    protected $rule = [
        'tenant_id' => 'require|positiveInteger',
        'binding_id' => 'require|positiveInteger',
        'host' => 'require|max:253',
        'client_key' => 'require|in:admin-web,member-api',
        'change_reason' => 'require|max:500',
    ];

    protected $scene = [
        'lists' => ['tenant_id'],
        'enable' => ['tenant_id', 'host', 'client_key', 'change_reason'],
        'disable' => ['binding_id', 'change_reason'],
    ];

    protected function positiveInteger(mixed $value): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
