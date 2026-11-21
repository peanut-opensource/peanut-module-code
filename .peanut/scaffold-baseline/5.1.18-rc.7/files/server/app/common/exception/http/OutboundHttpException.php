<?php

declare(strict_types=1);

namespace app\common\exception\http;

final class OutboundHttpException extends \RuntimeException
{
    public const ERROR_CODE = 'OUTBOUND_HTTP_UNAVAILABLE';
    public const HTTP_STATUS = 503;

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('外部服务当前不可达', 0, $previous);
    }
}
