<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

final class NotificationProducerRegistry
{
    private array $producers = [];
    public function register(NotificationProducer $producer): void
    {
        if (isset($this->producers[$producer->key()])) { throw new \LogicException('NOTIFICATION_PRODUCER_CONFLICT'); }
        $this->producers[$producer->key()] = $producer;
    }
    public function require(string $key): NotificationProducer
    {
        return $this->producers[$key] ?? throw \PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException::denied();
    }
}
