<?php

declare(strict_types=1);

namespace app\common\value\http;

final readonly class OutboundHttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}
}
