<?php

declare(strict_types=1);

namespace app\common\dto\payment;

final class PrepayRequest
{
    public const TERMINAL_MINI_PROGRAM = 1;
    public const TERMINAL_OFFICIAL_ACCOUNT = 2;
    public const TERMINAL_H5 = 3;
    public const TERMINAL_PC = 4;
    public const TERMINAL_IOS = 5;
    public const TERMINAL_ANDROID = 6;

    private string $orderSn;
    private int $amount;
    private string $currency;
    private int $terminal;
    private string $notifyUrl;
    private string $description;
    private string $openid;
    private string $clientIp;

    /** 金额单位固定为分，避免浮点误差。 */
    public function __construct(
        string $orderSn,
        int $amount,
        int $terminal,
        string $notifyUrl,
        string $description = '账户充值',
        string $currency = 'CNY',
        string $openid = '',
        string $clientIp = '',
    ) {
        $orderSn = trim($orderSn);
        $notifyUrl = trim($notifyUrl);
        if ($orderSn === '' || $amount <= 0 || $notifyUrl === '') {
            throw new \InvalidArgumentException('预支付订单号、金额或回调地址无效');
        }
        if (!in_array($terminal, [1, 2, 3, 4, 5, 6], true)) {
            throw new \InvalidArgumentException('支付终端无效');
        }
        if (strtoupper($currency) !== 'CNY') {
            throw new \InvalidArgumentException('当前支付渠道仅支持 CNY');
        }
        $this->orderSn = $orderSn;
        $this->amount = $amount;
        $this->terminal = $terminal;
        $this->notifyUrl = $notifyUrl;
        $this->description = trim($description) ?: '账户充值';
        $this->currency = 'CNY';
        $this->openid = trim($openid);
        $this->clientIp = trim($clientIp);
    }

    public function orderSn(): string
    {
        return $this->orderSn;
    }
    public function amount(): int
    {
        return $this->amount;
    }
    public function terminal(): int
    {
        return $this->terminal;
    }
    public function notifyUrl(): string
    {
        return $this->notifyUrl;
    }
    public function description(): string
    {
        return $this->description;
    }
    public function currency(): string
    {
        return $this->currency;
    }
    public function openid(): string
    {
        return $this->openid;
    }
    public function clientIp(): string
    {
        return $this->clientIp;
    }
}
