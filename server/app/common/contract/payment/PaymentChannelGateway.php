<?php

declare(strict_types=1);

namespace app\common\contract\payment;

use app\common\dto\payment\PaymentEvent;

interface PaymentChannelGateway extends PrepayGatewayInterface, CallbackParserInterface, RefundGatewayInterface
{
    /** @return array{status:string,event:?PaymentEvent} */
    public function queryPayment(string $number): array;

    public function close(string $number): void;
}
