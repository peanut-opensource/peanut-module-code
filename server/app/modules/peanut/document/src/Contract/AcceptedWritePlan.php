<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Provider verifies original batch custody against locked authorization windows; DTO alone grants nothing. */
final readonly class AcceptedWritePlan
{
    public function __construct(public AcceptedStoreInput $input, public array $reservations, public array $originMembers) {}
}
