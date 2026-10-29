<?php

declare(strict_types=1);

namespace app\common\dto\payment;

final class TransportResponse
{
    private int $statusCode;
    private string $body;
    private array $headers;

    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower(trim((string) $name))] = trim(is_array($value)
                ? (string) reset($value)
                : (string) $value);
        }
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
    public function body(): string
    {
        return $this->body;
    }
    public function headers(): array
    {
        return $this->headers;
    }
    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower(trim($name))] ?? '');
    }
}
