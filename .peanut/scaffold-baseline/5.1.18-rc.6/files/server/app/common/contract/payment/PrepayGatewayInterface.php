<?php

declare(strict_types=1);

namespace app\common\contract\payment;

use app\common\dto\payment\PrepayRequest;
use app\common\dto\payment\PrepayResult;

interface PrepayGatewayInterface
{
    public function prepay(PrepayRequest $request): PrepayResult;
}
