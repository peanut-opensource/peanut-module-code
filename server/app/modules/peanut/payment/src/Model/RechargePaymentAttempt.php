<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Model;

use app\common\model\TenantOwnedModel;

final class RechargePaymentAttempt extends TenantOwnedModel
{
    protected $name = 'recharge_payment_attempt';
    protected $autoWriteTimestamp = false;

    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const PAID = 'paid';
    public const DUPLICATE = 'duplicate';

    public static function originalPayment(RechargeOrder $order): self
    {
        $attempt = self::where('order_id', (int) $order->id)
            ->where('status', self::PAID)
            ->where('transaction_id', (string) $order->transaction_id)
            ->findOrEmpty();
        if ($attempt->isEmpty()) {
            throw \app\common\exception\BusinessException::conflict(
                'PAYMENT_ORIGIN_UNVERIFIED',
                '原收款商户待核对',
            );
        }

        return $attempt;
    }
}
