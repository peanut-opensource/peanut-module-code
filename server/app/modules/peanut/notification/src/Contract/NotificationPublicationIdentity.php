<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

/** Stable business fact identity, independent of the actor used to deliver/recover it. */
final readonly class NotificationPublicationIdentity
{
    public function __construct(public string $producerKey, public string $eventKey, public string $businessKey)
    {
        foreach ([$producerKey, $eventKey, $businessKey] as $key) {
            if ($key === '' || strlen($key) > 160 || str_contains($key, "\0")) { throw new \InvalidArgumentException('NOTIFICATION_PUBLICATION_IDENTITY_INVALID'); }
        }
    }

    public function digest(int $tenantId): string
    {
        return hash('sha256', json_encode([$tenantId, $this->producerKey, $this->eventKey, $this->businessKey], JSON_THROW_ON_ERROR));
    }
}
