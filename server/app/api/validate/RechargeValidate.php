<?php

declare(strict_types=1);

namespace app\api\validate;

use app\common\enum\UserTerminalEnum;
use PeanutAdmin\Modules\Payment\Contract\PaymentMethod;
use think\Validate;

/** 用户充值创建、查询和预支付参数验证。 */
class RechargeValidate extends Validate
{
    protected $rule = [
        'amount' => [
            'require',
            'regex' => '/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/',
        ],
        'terminal' => 'require|integer|checkTerminal',
        'order_id' => 'require|integer|gt:0',
        'pay_way' => 'require|integer|checkPayWay',
        'page_no' => 'integer|gt:0',
        'page_size' => 'integer|gt:0|elt:100',
    ];

    protected $message = [
        'amount.require' => '请输入充值金额',
        'amount.regex' => '充值金额格式错误',
        'terminal.require' => '请选择支付终端',
        'order_id.require' => '充值订单参数缺失',
        'pay_way.require' => '请选择支付方式',
    ];

    public function sceneCreate(): self
    {
        return $this->only(['amount', 'terminal']);
    }

    public function sceneConfig(): self
    {
        return $this->only(['terminal']);
    }

    public function scenePrepay(): self
    {
        return $this->only(['order_id', 'pay_way']);
    }

    public function sceneDetail(): self
    {
        return $this->only(['order_id']);
    }

    public function sceneLists(): self
    {
        return $this->only(['page_no', 'page_size']);
    }

    protected function checkTerminal($value): bool|string
    {
        return UserTerminalEnum::isValid((int) $value) ? true : '支付终端不支持';
    }

    protected function checkPayWay($value): bool|string
    {
        return PaymentMethod::isProvider((int) $value)
            ? true
            : '支付方式不支持';
    }
}
