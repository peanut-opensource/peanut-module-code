<?php

declare(strict_types=1);

namespace app\adminapi\validate\auth;

use think\Validate;
use app\common\validate\PasswordRule;

/**
 * 编辑个人信息校验
 * Class EditSelfValidate
 * @package app\adminapi\validate\auth
 */
class EditSelfValidate extends Validate
{
    use PasswordRule;
    protected $rule = [
        'nickname'         => 'require|length:1,50',
        'avatar'           => 'max:255',
        'password'         => 'passwordPolicy',
        'password_confirm' => 'requireWith:password|confirm',
        'password_old'     => 'requireWith:password',
    ];

    protected $message = [
        'nickname.require'            => '请填写昵称',
        'nickname.length'            => '昵称须在 1~50 位字符',
        'avatar.max'                 => '头像地址过长',
        'password_confirm.requireWith' => '确认密码不能为空',
        'password_confirm.confirm'   => '两次输入的密码不一致',
        'password_old.requireWith'   => '请填写当前密码',
    ];
}
