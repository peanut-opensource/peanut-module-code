<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAssignmentResolver
{
    /** One rule at a time so an empty required rule cannot be hidden by another.
     * @param array{kind:string,key:?string,required:bool,department_scope:?string} $rule
     * @param array{initiator_member_id:int,business_department_key:?string,previous_actor_member_id:?int,subject_owner_member_id:?int,subject_type:string,subject_key:string} $basis
     * @return list<array{member_id:int,source_kind:string,source_key:string,binding_revision:string}>
     */
    public function resolve(AuthorizedOperationContext $context, array $rule, array $basis): array;

    /** Recheck current active account, source membership and scope; frozen sources remain unchanged. */
    public function eligible(AuthorizedOperationContext $context, int $memberId, array $sources): bool;
}
