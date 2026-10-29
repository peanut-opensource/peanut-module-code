<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Collaboration\Service;

use PeanutAdmin\Modules\Collaboration\Model\CollaborationRoom;

/** Application room identity and lease policy; process startup has no database phase. */
final readonly class RuntimeLifecycle
{
    public function __construct(private array $configuration, private string $edition) {}

    public function ownsRoom(CollaborationRoom $room): bool
    {
        return $room->instance_id === $this->configuration['instance_id'] && $room->edition === $this->edition;
    }

    public function roomLeaseExpiresAt(): string
    {
        return gmdate('Y-m-d H:i:s', time() + $this->configuration['room_lease_seconds']);
    }
}
