<?php

declare(strict_types=1);

namespace app\common\dto\payment;

final class CallbackRequest
{
    private string $body;
    private array $headers;
    private array $params;

    public function __construct(string $body = '', array $headers = [], array $params = [])
    {
        $this->body = $body;
        $this->params = $params;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value)
                ? (string) reset($value)
                : (string) $value;
        }
    }

    public function body(): string
    {
        return $this->body;
    }
    public function params(): array
    {
        return $this->params;
    }
    public function header(string $name): string
    {
        return trim((string) ($this->headers[strtolower($name)] ?? ''));
    }
}
