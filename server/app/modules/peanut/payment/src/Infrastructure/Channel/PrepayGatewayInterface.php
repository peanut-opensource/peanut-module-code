<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Infrastructure\Channel;

interface PrepayGatewayInterface
{
    public function prepay(PrepayRequest $request): PrepayResult;
}
