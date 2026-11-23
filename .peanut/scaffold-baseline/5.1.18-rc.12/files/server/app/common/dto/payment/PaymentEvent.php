<?php

declare(strict_types=1);

namespace app\common\dto\payment;

/** 已通过渠道验签的标准支付事件；amount 单位固定为分。 */
final class PaymentEvent
{
    private string $channel;
    private string $orderSn;
    private string $transactionId;
    private int $amount;
    private string $currency;
    private string $status;
    private string $merchantIdentity;
    private string $appIdentity;

    public function __construct(
        string $channel,
        string $orderSn,
        string $transactionId,
        int $amount,
        string $currency,
        string $status,
        string $merchantIdentity,
        string $appIdentity,
    ) {
        if (trim($orderSn) === '' || trim($transactionId) === '' || $amount <= 0) {
            throw new \RuntimeException('支付回调缺少可信交易标识或金额');
        }
        $this->channel = $channel;
        $this->orderSn = trim($orderSn);
        $this->transactionId = trim($transactionId);
        $this->amount = $amount;
        $this->currency = strtoupper(trim($currency));
        $this->status = $status;
        $this->merchantIdentity = trim($merchantIdentity);
        $this->appIdentity = trim($appIdentity);
    }

    public function channel(): string
    {
        return $this->channel;
    }
    public function orderSn(): string
    {
        return $this->orderSn;
    }
    public function transactionId(): string
    {
        return $this->transactionId;
    }
    public function amount(): int
    {
        return $this->amount;
    }
    public function currency(): string
    {
        return $this->currency;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function merchantIdentity(): string
    {
        return $this->merchantIdentity;
    }
    public function appIdentity(): string
    {
        return $this->appIdentity;
    }

    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'order_sn' => $this->orderSn,
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'merchant_identity' => $this->merchantIdentity,
            'app_identity' => $this->appIdentity,
        ];
    }
}
