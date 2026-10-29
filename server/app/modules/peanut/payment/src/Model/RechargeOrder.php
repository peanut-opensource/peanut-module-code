<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Model;

use app\common\model\TenantOwnedModel;
use think\model\concern\SoftDelete;

/**
 * 充值订单 Model
 *
 * 对应表 pa_recharge_order
 */
class RechargeOrder extends TenantOwnedModel
{
    use SoftDelete;

    protected $name = 'recharge_order';
    protected $deleteTime = 'delete_time';

    /** 支付方式 */
    public const PAY_WAY_BALANCE = 1;
    public const PAY_WAY_WECHAT = 2;
    public const PAY_WAY_ALIPAY = 3;

    /** 支付状态 */
    public const PAY_STATUS_UNPAID = 0;
    public const PAY_STATUS_PAID = 1;

    /** 是否已经发起退款。 */
    public const REFUND_STATUS_NONE = 0;
    public const REFUND_STATUS_STARTED = 1;

    /** Peanut 旧调用方兼容常量。 */
    public const STATUS_PENDING = self::PAY_STATUS_UNPAID;
    public const STATUS_PAID = self::PAY_STATUS_PAID;
    public const STATUS_FAILED = 2;

    /** 生成不可预测且全局唯一的充值订单号。 */
    public static function generateSn(): string
    {
        do {
            $sn = 'RC' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
        } while ((new self())->withTrashed()->where('sn', $sn)->count() > 0);

        return $sn;
    }

    /** 每次预支付使用独立支付请求号，避免短时间碰撞。 */
    public static function generatePaySn(): string
    {
        return 'PY' . date('YmdHis') . strtoupper(bin2hex(random_bytes(8)));
    }
}
