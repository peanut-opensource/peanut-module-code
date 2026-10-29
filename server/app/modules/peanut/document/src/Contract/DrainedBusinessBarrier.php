<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Provider-issued same-transaction proof; constructing this value does not make it consumable. */
final readonly class DrainedBusinessBarrier
{
    public function __construct(public BusinessBarrierPlan $plan, public int $stateSeq, public string $binarySha256,
        public ?string $storeId, public int $targetProgress) {}
}
