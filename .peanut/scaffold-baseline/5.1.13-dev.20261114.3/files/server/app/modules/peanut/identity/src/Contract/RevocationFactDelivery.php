<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

/** Optional registered consumer; enqueue joins the producer PDO, reconcile never sends Node RPC. */
interface RevocationFactDelivery
{
    public function enqueueRevocation(array $fact): void;
    public function reconcileRevocation(array $fact, bool $repair = false): array;
}
