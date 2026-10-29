<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\ArtifactRevision\Contract\ArtifactRevisionQuery;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowSubjectRevisionResolver;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

/** Optional composition: the revision base can be installed without Workflow. */
final readonly class ArtifactWorkflowSubjectRevisionResolver implements WorkflowSubjectRevisionResolver
{
    public function __construct(private ArtifactRevisionQuery $revisions) {}

    public function resolve(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $expectedRevisionKey): array
    {
        $target = $context->targets[0] ?? null;
        if ($context->resourceKey !== $subjectType || count($context->targets) !== 1 || $target === null
            || $target->targetRole !== 'primary' || $target->targetResourceKey !== $subjectType || $target->targetIds !== [$subjectKey]) {
            throw WorkflowException::subjectNotFound();
        }
        if (preg_match('/^revision_[0-9a-f]{32}$/D', $expectedRevisionKey) !== 1) {
            throw WorkflowException::subjectRevisionConflict();
        }
        try {
            $revision = $this->revisions->snapshot($context->tenantContext->tenantId, $subjectType, $subjectKey, $expectedRevisionKey);
            if ($revision === null || $revision->revisionKey !== $expectedRevisionKey) {
                throw WorkflowException::subjectRevisionConflict();
            }
            return ['revision_key' => $revision->revisionKey, 'sha256' => $revision->sha256];
        } catch (WorkflowException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw WorkflowException::internal();
        }
    }
}
