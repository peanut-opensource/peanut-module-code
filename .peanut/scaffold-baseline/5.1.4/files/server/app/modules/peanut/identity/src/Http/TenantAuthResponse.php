<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Http;

final readonly class TenantAuthResponse
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, string|null> $cookies APP host applies these with the native Cookie API.
     */
    public function __construct(
        public int $status,
        public ?array $body,
        public array $headers = [],
        public array $cookies = [],
    ) {}
}
