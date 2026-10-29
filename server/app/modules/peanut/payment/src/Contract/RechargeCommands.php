<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Contract;

use app\common\dto\payment\CallbackRequest;
use app\common\dto\payment\PaymentEvent;

interface RechargeCommands
{
    public function create(object $context, int $memberId, array $params): array;

    public function prepay(
        object $context,
        int $memberId,
        int $orderId,
        int $payWay,
        string $notifyUrl,
        string $clientIp = '',
        string $openid = '',
    ): array;

    public function parseCallback(string $channel, array $config, CallbackRequest $request): PaymentEvent;

    public function settleVerifiedCallback(int $paymentBindingId, PaymentEvent $event, int $payWay): bool;
}
