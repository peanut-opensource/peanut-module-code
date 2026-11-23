<?php

declare(strict_types=1);

namespace app\common\contract\http;

use app\common\value\http\OutboundHttpRequest;
use app\common\value\http\OutboundHttpResponse;

interface OutboundHttpTransport
{
    public function send(OutboundHttpRequest $request): OutboundHttpResponse;
}
