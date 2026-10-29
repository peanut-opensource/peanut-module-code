<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

use PeanutAdmin\Modules\Payment\Model\RechargeOrder;

class RefundEnum
{
    // 退款类型
    public const TYPE_ADMIN = 1;        // 后台退款

    // 退款状态
    public const REFUND_ING     = 0;    // 退款中
    public const REFUND_SUCCESS = 1;    // 退款成功
    public const REFUND_ERROR   = 2;    // 退款失败

    // 退款方式
    public const REFUND_ONLINE  = 1;    // 线上退款
    public const REFUND_OFFLINE = 2;    // 线下退款

    // 退款订单来源类型
    public const ORDER_TYPE_ORDER    = 'order';    // 普通订单
    public const ORDER_TYPE_RECHARGE = 'recharge'; // 充值订单

    /** 退款类型描述 */
    public static function getTypeDesc($value = true): string|array
    {
        $data = [self::TYPE_ADMIN => '后台退款'];
        return $value === true ? $data : ($data[$value] ?? '');
    }

    /** 退款状态描述 */
    public static function getStatusDesc($value = true): string|array
    {
        $data = [
            self::REFUND_ING     => '退款中',
            self::REFUND_SUCCESS => '退款成功',
            self::REFUND_ERROR   => '退款失败',
        ];
        return $value === true ? $data : ($data[$value] ?? '');
    }

    /** 退款方式描述 */
    public static function getWayDesc($value = true): string|array
    {
        $data = [
            self::REFUND_ONLINE  => '线上退款',
            self::REFUND_OFFLINE => '线下退款',
        ];
        return $value === true ? $data : ($data[$value] ?? '');
    }

    /** 线上渠道原路退回；余额等非线上渠道归为线下退款。 */
    public static function getRefundWayByPayWay(int $payWay): int
    {
        return in_array($payWay, [
            RechargeOrder::PAY_WAY_WECHAT,
            RechargeOrder::PAY_WAY_ALIPAY,
        ], true) ? self::REFUND_ONLINE : self::REFUND_OFFLINE;
    }
}
