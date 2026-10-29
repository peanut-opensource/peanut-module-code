<?php

declare(strict_types=1);

namespace app\api\validate;

use app\common\enum\notice\NoticeSceneEnum;
use think\Validate;

class SmsValidate extends Validate
{
    protected $rule = [
        'scene'  => 'require|checkScene',
        'mobile' => 'require|mobile',
    ];

    protected $message = [
        'scene.require'  => '验证码场景不能为空',
        'mobile.require' => '手机号不能为空',
        'mobile.mobile'  => '手机号格式不正确',
    ];

    protected $scene = [
        'send' => ['scene', 'mobile'],
    ];

    protected function checkScene($value): bool|string
    {
        return NoticeSceneEnum::isValid((string) $value)
            ? true
            : '验证码场景不存在';
    }
}
