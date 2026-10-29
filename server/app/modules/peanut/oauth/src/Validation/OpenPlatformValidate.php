<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Validation;

use think\Validate;

class OpenPlatformValidate extends Validate
{
    protected $rule = [
        'app_id' => 'require|max:128|checkNotBlank',
        'app_secret' => 'require|max:255|checkNotBlank',
    ];

    protected $message = [
        'app_id.require' => 'AppID 不能为空',
        'app_id.max' => 'AppID 不能超过 128 个字符',
        'app_secret.require' => 'AppSecret 不能为空',
        'app_secret.max' => 'AppSecret 不能超过 255 个字符',
    ];

    protected function checkNotBlank(mixed $value): bool|string
    {
        return trim((string) $value) !== '' ? true : '应用凭证不能为空';
    }
}
