<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Infrastructure\Channel;

interface PaymentChannelGateway extends PrepayGatewayInterface, CallbackParserInterface, RefundGatewayInterface
{
    /** @return array{status:string,event:?PaymentEvent} */
    public function queryPayment(string $number): array;

    public function close(string $number): void;
}
