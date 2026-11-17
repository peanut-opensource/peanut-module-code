<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

final readonly class ReservationReleaseInput
{
    public function __construct(public int $tenantId, public int $documentId, public string $captureHash) {}
}
