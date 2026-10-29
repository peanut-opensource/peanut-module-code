<?php

declare(strict_types=1);

namespace app\common\contract\payment;

use app\common\dto\payment\CallbackRequest;
use app\common\dto\payment\PaymentEvent;

/** 只负责验签和标准化；实现不得更新订单、余额或用户数据。 */
interface CallbackParserInterface
{
    public function parse(CallbackRequest $request): PaymentEvent;
}
