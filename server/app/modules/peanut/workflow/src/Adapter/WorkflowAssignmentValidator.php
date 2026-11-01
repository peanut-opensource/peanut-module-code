<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

/**
 * Optional business veto after native assignment resolution, before responsibility is written.
 * Implement alongside WorkflowScenarioProvider; never filter or replace the resolved members.
 */
interface WorkflowAssignmentValidator
{
    /**
     * Every key is supplied by Workflow. enter contains the complete resolved pool; claim and
     * reassign contain only the proposed assignee with the frozen sources checked for that seat.
     * Throw WorkflowException::unresolved with a safe reason to reject a business-invalid pool.
     * The callback runs inside the command transaction and must have no irreversible effects.
     *
     * @throws WorkflowException
     * @param array{
     *     phase: 'enter'|'claim'|'reassign',
     *     subject_type: string,
     *     subject_key: string,
     *     subject_revision_key: string,
     *     instance_key: string,
     *     definition_version: int,
     *     cycle_no: int,
     *     node_key: string,
     *     node_execution_key: string,
     *     node_type: 'approval'|'human_task',
     *     allocation_mode: 'direct'|'claim'|'multi',
     *     aggregation_policy: 'all_approve'|'any_approve'|null,
     *     allow_self_approval: bool,
     *     initiator_member_id: int,
     *     business_department_key: string|null,
     *     previous_actor_member_id: int|null,
     *     work_item_key: string|null,
     *     seat_no: int|null,
     *     previous_assignee_member_id: int|null,
     *     members: list<array{
     *         member_id: int,
     *         sources: list<array{source_kind: string, source_key: string, binding_revision: string}>
     *     }>
     * } $assignment
     */
    public function validateAssignments(
        AuthorizedOperationContext $context,
        string $moduleKey,
        string $workflowKey,
        array $assignment,
    ): void;
}
