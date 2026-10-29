<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Application;

use RuntimeException;

/** Stable public workflow failure contract shared with Modules that implement workflow SPIs. */
final class WorkflowException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly ?string $conflictInstanceKey = null,
        public readonly ?string $validationPath = null,
    ) {
        parent::__construct($message);
    }

    public static function definitionInvalid(string $message = 'The workflow definition is invalid.', ?string $path = null): self
    {
        return new self('WORKFLOW_DEFINITION_INVALID', 422, $message, validationPath: $path);
    }
    public static function upgradeRequiresReview(): self
    {
        return new self('WORKFLOW_LEGACY_DATA_REQUIRES_REVIEW', 409, 'Workflow V2 migration requires an explicit legacy-data review; preserve the original deployment, immutable versions, opinions and responsibility semantics.');
    }

    public static function unsupported(string $capability, ?string $path = null): self
    {
        return new self('CAPABILITY_UNSUPPORTED', 422, 'Unsupported capability: ' . $capability, validationPath: $path);
    }

    /** @return array{code:string,path:string,message:string} */
    public function validationIssue(): array
    {
        return ['code' => $this->errorCode, 'path' => $this->validationPath ?? '/graph', 'message' => $this->getMessage()];
    }

    public static function unresolved(string $message = 'Required assignees could not be resolved.'): self
    {
        return new self('ASSIGNEE_UNRESOLVED', 422, $message);
    }

    public static function blocked(string $message = 'The workflow action is blocked.'): self
    {
        return new self('ACTION_BLOCKED', 409, $message);
    }

    public static function alreadyCompleted(): self
    {
        return new self('TASK_ALREADY_COMPLETED', 409, 'This task has already been processed.');
    }

    public static function preconditionRequired(): self
    {
        return new self('WORKFLOW_PRECONDITION_REQUIRED', 428, 'A workflow revision precondition is required.');
    }

    public static function definitionConflict(): self
    {
        return new self('WORKFLOW_DEFINITION_CONFLICT', 409, 'The workflow definition revision has changed.');
    }

    public static function definitionRetired(): self
    {
        return new self('WORKFLOW_DEFINITION_RETIRED', 409, 'The workflow definition is retired.');
    }

    public static function instanceConflict(?string $instanceKey = null): self
    {
        return new self('WORKFLOW_INSTANCE_CONFLICT', 409, 'The workflow instance revision has changed.', conflictInstanceKey: $instanceKey);
    }

    public static function transitionUnavailable(): self
    {
        return new self('WORKFLOW_TRANSITION_UNAVAILABLE', 409, 'The workflow transition is unavailable.');
    }

    public static function assignmentDenied(): self
    {
        return new self('WORKFLOW_ASSIGNMENT_DENIED', 403, 'The workflow assignment does not permit this decision.');
    }

    public static function subjectNotFound(): self
    {
        return new self('WORKFLOW_SUBJECT_NOT_FOUND', 404, 'The workflow subject is unavailable.');
    }

    public static function subjectRevisionConflict(): self
    {
        return new self('WORKFLOW_SUBJECT_REVISION_CONFLICT', 409, 'The workflow subject revision has changed.');
    }

    public static function attachmentUnavailable(): self
    {
        return new self('WORKFLOW_ATTACHMENT_UNAVAILABLE', 404, 'A workflow attachment is unavailable.');
    }

    public static function providerUnavailable(): self
    {
        return new self('WORKFLOW_PROVIDER_UNAVAILABLE', 503, 'A required workflow provider is unavailable.');
    }

    public static function internal(): self
    {
        return new self('INTERNAL_ERROR', 500, 'The workflow operation could not be completed.');
    }
}
