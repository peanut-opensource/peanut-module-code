<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Contract;

use Custom\Modules\AiAssistant\Service\AiCandidates;
use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AiCandidateQueries
{
    public function __construct(private AiCandidates $candidates) {}
    /** Actual Admin only, outside caller transaction. Verify returned original native snapshot in the consumer's CAS transaction; never auto-approves its business. */
    /** The returned accepted_binding is stable metadata; snapshot remains the original native proof object. */
    public function prepareAcceptedCandidate(TenantContext $context, int $taskId): array
    {
        return $this->candidates->prepareAccepted($context, $taskId);
    }
    /** Existing consumer transaction, current actual Admin and complete original accepted_binding required. */
    public function assertAcceptedCandidate(TenantContext $context, array $acceptedBinding): void
    {
        $this->candidates->assertAccepted($context, $acceptedBinding);
    }

}
