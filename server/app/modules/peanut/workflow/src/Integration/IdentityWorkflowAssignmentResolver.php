<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Identity\Contract\WorkflowOrganizationQuery;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAssignmentResolver;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowScenarioProvider;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

final readonly class IdentityWorkflowAssignmentResolver implements WorkflowAssignmentResolver
{
    public function __construct(private WorkflowOrganizationQuery $organization, private WorkflowScenarioProvider $scenarios) {}

    public function resolve(AuthorizedOperationContext $context, array $rule, array $basis): array
    {
        $kind = $rule['kind'];
        $key = $rule['key'];
        if (in_array($kind, ['initiator', 'previous_actor', 'subject_owner'], true)) {
            $memberId = match ($kind) {
                'initiator' => $basis['initiator_member_id'],
                'previous_actor' => $basis['previous_actor_member_id'],
                'subject_owner' => $basis['subject_owner_member_id'],
            };
            if ($memberId === null) {
                return [];
            }
            $candidates = $this->organization->resolve($context->tenantContext, 'member', (string) $memberId);
            return array_map(static fn(array $candidate): array => array_replace($candidate, ['source_kind' => $kind]), $candidates);
        }
        if (in_array($kind, ['department_members', 'department_leaders'], true) && $key === null) {
            $key = $basis['business_department_key'];
        }
        if ($key === null) {
            return [];
        }
        try {
            return $this->organization->resolve($context->tenantContext, $kind, $key, $rule['department_scope']);
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException) {
            throw WorkflowException::assignmentDenied();
        }
    }

    public function eligible(AuthorizedOperationContext $context, int $memberId, array $sources): bool
    {
        try {
            $normalized = [];
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    return false;
                }
                $kind = $source['source_kind'] ?? null;
                if ($kind === 'subject_owner') {
                    $target = $context->targets[0] ?? null;
                    if ($target === null || count($context->targets) !== 1 || count($target->targetIds) !== 1
                        || $this->scenarios->subjectOwner($context, $context->resourceKey, $target->targetIds[0]) !== $memberId) {
                        continue;
                    }
                }
                if (in_array($kind, ['initiator', 'previous_actor', 'subject_owner'], true)) {
                    $source['source_kind'] = 'member';
                }
                $normalized[] = $source;
            }
            return $this->organization->eligible($context->tenantContext, $memberId, $normalized);
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException) {
            return false;
        }
    }
}
