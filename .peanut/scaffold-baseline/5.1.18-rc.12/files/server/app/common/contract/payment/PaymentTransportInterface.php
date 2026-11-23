<?php

declare(strict_types=1);

namespace app\common\contract\payment;

use app\common\dto\payment\TransportResponse;

/** 支付渠道 HTTP 传输边界；验收可注入内存实现，生产默认使用 cURL。 */
interface PaymentTransportInterface
{
    public function request(string $method, string $url, array $headers, string $body = ''): TransportResponse;
}
