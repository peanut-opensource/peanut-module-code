<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Workflow\Integration;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Workflow\Adapter\WorkflowAuthorizationResolver;
use PeanutAdmin\Modules\Workflow\Application\WorkflowException;

final readonly class ScenarioWorkflowAuthorizationResolver implements WorkflowAuthorizationResolver
{
    public function __construct(private WorkflowScenarioRegistry $scenarios) {}

    public function authorize(AuthorizedOperationContext $trustedBasis, string $resourceKey, string $operation, array $permissionKeys, string $subjectKey): AuthorizedOperationContext
    {
        if ($permissionKeys === [] || $subjectKey === '') {
            throw WorkflowException::assignmentDenied();
        }
        $context = $this->scenarios->access($resourceKey)->authorize($trustedBasis, $resourceKey, $operation, $permissionKeys, $subjectKey);
        $target = $context->targets[0] ?? null;
        if ($context->tenantContext !== $trustedBasis->tenantContext || $context->resourceKey !== $resourceKey || $context->operation !== $operation
            || count($context->targets) !== 1 || $target === null || $target->targetRole !== 'primary'
            || $target->targetResourceKey !== $resourceKey || $target->targetIds !== [$subjectKey]) {
            throw WorkflowException::assignmentDenied();
        }
        return $context;
    }

    public function applyVisibleScopes(AuthorizedOperationContext $trustedBasis, \think\db\BaseQuery $query): void
    {
        $scenes = $this->scenarios->registrations();
        if ($scenes === []) { $query->whereRaw('1=0'); return; }
        $query->where(function (\think\db\BaseQuery $group) use ($scenes, $trustedBasis): void {
            foreach ($scenes as $scene) {
                $group->whereOr(function (\think\db\BaseQuery $term) use ($scene, $trustedBasis): void {
                    $term->where('i.module_key', $scene['module_key'])->where('i.workflow_key', $scene['workflow_key']);
                    $scene['access']->applyVisibleSubjectScope($trustedBasis->tenantContext, $scene['resource_key'], $term, 'i.subject_key');
                });
            }
        });
    }
}
