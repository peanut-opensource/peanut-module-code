<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Model;

use app\common\model\TenantOwnedModel;

/**
 * @property int|string $amount_cents
 * @property int|string $binding_id
 * @property string $currency
 * @property int|null $expires_at
 * @property int|string $grant_id
 * @property int|string $id
 * @property string $merchant_identity_hash
 * @property string $merchant_order_sn
 * @property int $order_id
 * @property int $pay_way
 * @property string $status
 * @property int|string $tenant_id
 * @property string|null $transaction_id
 */
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
