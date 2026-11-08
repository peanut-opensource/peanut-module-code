<?php

declare(strict_types=1);

namespace app\platform\validate;

use think\Validate;

final class PlatformLoginValidate extends Validate
{
    protected $rule = [
        'email' => 'require|email|max:255',
        'password' => 'require|max:4096',
    ];
}
