<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Contract;

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
}
