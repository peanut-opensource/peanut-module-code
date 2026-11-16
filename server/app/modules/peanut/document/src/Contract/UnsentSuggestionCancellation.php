<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Document\Contract;

/** Cancellation-only proof for the confirmed loaded-progress defect; never an accepted-write proof. */
final readonly class UnsentSuggestionCancellation
{
    public const CODE = 'DOCUMENT_SUGGESTION_NOT_APPLIED_PROGRESS_MISMATCH';

    public function __construct(public BusinessBarrierPlan $plan, public int $stateSeq, public string $binarySha256,
        public string $storeId, public int $targetProgress) {}
}
