<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Service;

final class NotificationBootstrapDefaults
{
    /** @return list<array{0:string,1:string,2:string,3:string}> */
    public static function scenes(): array
    {
        return [
            ['login_code', '登录验证码', '用户使用手机号验证码登录', '您的登录验证码是${code}，五分钟内有效。'],
            ['bind_mobile', '绑定手机验证码', '用户首次绑定手机号', '您的绑定手机验证码是${code}，五分钟内有效。'],
            ['change_mobile', '变更手机验证码', '用户更换已绑定手机号', '您的变更手机验证码是${code}，五分钟内有效。'],
            ['reset_password', '找回密码验证码', '用户通过手机号重置密码', '您的找回密码验证码是${code}，五分钟内有效。'],
        ];
    }

    private function __construct() {}
}
