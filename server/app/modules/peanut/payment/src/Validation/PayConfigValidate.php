<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Validation;

use think\Validate;

class PayConfigValidate extends Validate
{
    protected $rule = [
        'wx_pay_status' => 'require|in:0,1',
        'wx_pay_appid' => 'max:128',
        'wx_pay_mch_id' => 'max:64',
        'wx_pay_secret' => 'max:1000',
        'wx_pay_cert_path' => 'max:500',
        'wx_pay_cert_key_path' => 'max:500',
        'wx_pay_platform_cert_path' => 'max:500',
        'ali_pay_status' => 'require|in:0,1',
        'ali_pay_app_id' => 'max:128',
        'ali_pay_private_key' => 'max:10000',
        'ali_pay_public_key' => 'max:10000',
        'ali_pay_seller_id' => 'max:64',
    ];
    protected $message = [
        'wx_pay_status.require' => '微信支付状态不能为空',
        'wx_pay_status.in' => '微信支付状态无效',
        'ali_pay_status.require' => '支付宝状态不能为空',
        'ali_pay_status.in' => '支付宝状态无效',
    ];
}
