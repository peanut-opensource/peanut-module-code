<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Validation;

use think\Validate;

class OfficialAccountValidate extends Validate
{
    protected $rule = [
        'name' => 'max:100',
        'original_id' => 'max:100',
        'qr_code' => 'max:255',
        'app_id' => 'require|max:128|checkNotBlank',
        'app_secret' => 'require|max:255|checkNotBlank',
        'token' => 'max:255',
    ];

    protected $message = [
        'name.max' => '公众号名称不能超过 100 个字符',
        'original_id.max' => '原始 ID 不能超过 100 个字符',
        'qr_code.max' => '二维码地址不能超过 255 个字符',
        'app_id.require' => 'AppID 不能为空',
        'app_id.max' => 'AppID 不能超过 128 个字符',
        'app_secret.require' => 'AppSecret 不能为空',
        'app_secret.max' => 'AppSecret 不能超过 255 个字符',
        'token.max' => 'Token 不能超过 255 个字符',
    ];

    protected function checkNotBlank(mixed $value): bool|string
    {
        return trim((string) $value) !== '' ? true : '应用凭证不能为空';
    }
}
