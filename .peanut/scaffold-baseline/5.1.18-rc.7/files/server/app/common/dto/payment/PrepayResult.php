<?php

declare(strict_types=1);

namespace app\common\dto\payment;

final class PrepayResult
{
    private string $channel;
    private string $scene;
    private array $payload;

    public function __construct(string $channel, string $scene, array $payload)
    {
        $this->channel = $channel;
        $this->scene = $scene;
        $this->payload = $payload;
    }

    public function channel(): string
    {
        return $this->channel;
    }
    public function scene(): string
    {
        return $this->scene;
    }
    public function payload(): array
    {
        return $this->payload;
    }

    public function toArray(): array
    {
        return ['channel' => $this->channel, 'scene' => $this->scene, 'payload' => $this->payload];
    }
}
