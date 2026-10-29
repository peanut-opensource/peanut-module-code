<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Protocol;

/** Data only. ServiceAuthentication retains the original authenticated object as proof. */
final readonly class ServiceRequest
{
    public function __construct(
        public string $serviceInstanceId,
        public string $keyId,
        public string $holderKey,
        public string $nonce,
        public int $timestamp,
        public string $method,
        public string $path,
        public string $bodySha256,
    ) {}
}
