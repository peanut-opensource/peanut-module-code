<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** Owned by the business Module. The workflow never writes its private tables. */
interface WorkflowScenarioProvider
{
    /** actions are trusted registrations, never HTTP-selected PHP classes.
     * @return array{contract_version:int,fields:array,result_codes:list<string>,capabilities:list<string>,actions:array<string,array{contract_version:int,operation:string,permission_keys:list<string>,task_type:string,input_schema:array}>}
     */
    public function scenario(AuthorizedOperationContext $context, string $moduleKey, string $workflowKey): array;

    /** Resolve/validate a canonical department from the actor's legitimate business relations. */
    public function businessDepartment(AuthorizedOperationContext $context, ?string $requestedKey): ?string;

    /** Current business responsibility, never inferred from an arbitrary object column. */
    public function subjectOwner(AuthorizedOperationContext $context, string $subjectType, string $subjectKey): ?int;

    /** Fresh owner-native immutable value prepared after business authorization, before the intent transaction. */
    public function prepareSubjectSnapshot(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $revisionKey): object;

    /** Must revalidate the prepared value and lock the subject in the same connection when forUpdate is true.
     * @return array{revision_key:string,sha256:string,fields:array,reference:array}
     */
    public function snapshot(AuthorizedOperationContext $context, string $subjectType, string $subjectKey, string $revisionKey, bool $forUpdate, object $prepared): array;

    /** Validate current business state and produce registered typed outputs; no irreversible side effect. */
    public function validateCompletion(AuthorizedOperationContext $context, array $instance, array $input): array;

    /** Local same-database business write with durable idempotent receipt in the outer transaction. */
    public function executeAction(AuthorizedOperationContext $context, string $actionKey, int $contractVersion, array $input, string $effectKey): array;
}
