<?php

declare(strict_types=1);

namespace app\adminapi\validate\auth;

use think\Validate;

class LoginValidate extends Validate
{
    protected $rule = [
        // Core identity accepts RFC-valid email addresses up to 255 bytes.
        // Keep login and administrator CRUD contracts aligned.
        'account'  => 'require|email|max:255',
        'password' => 'require',
        'terminal' => 'require|integer|in:1,2',
    ];

    protected $message = [
        'account.require'  => '请输入账号',
        'account.email'    => '请输入有效邮箱',
        'account.max'      => '账号长度不能超过 255 个字符',
        'password.require' => '请输入密码',
        'terminal.require' => '请选择登录终端',
        'terminal.integer' => '登录终端参数错误',
        'terminal.in'      => '登录终端参数错误',
    ];
}
