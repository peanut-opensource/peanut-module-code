<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Model;

use app\common\model\TenantOwnedModel;

/**
 * 退款日志模型
 *
 * @property int $handle_id
 * @property int $id
 * @property string|null $refund_msg
 * @property int $refund_status
 * @property string|null $sn
 */
class RefundLog extends TenantOwnedModel
{
    protected $name = 'refund_log';

    /** Provider request identity remains globally unique across Tenants. */
    public static function generateSn(): string
    {
        do {
            $sn = date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('sn', $sn)->count() > 0);

        return $sn;
    }
}
