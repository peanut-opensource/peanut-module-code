<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Batch counterpart of authorize() for scenario-owned read projections. */
interface WorkflowScenarioBatchAccess extends WorkflowScenarioAccess
{
    /**
     * Apply the same permission, operation and subject rules as authorize() across the batch.
     * Return only the requested keys for which the operation is allowed.
     *
     * @param non-empty-list<string> $permissionKeys
     * @param non-empty-list<string> $subjectKeys
     * @return list<string>
     */
    public function authorizedSubjectKeys(
        AuthorizedOperationContext $basis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        array $subjectKeys,
    ): array;
}
