<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Contract;

final class PaymentMethod
{
    public const WECHAT = 2;
    public const ALIPAY = 3;

    public static function isProvider(int $value): bool
    {
        return in_array($value, [self::WECHAT, self::ALIPAY], true);
    }

    private function __construct() {}
}
