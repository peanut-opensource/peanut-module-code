<?php

declare(strict_types=1);

namespace Custom\Modules\AiAssistant\Service;

use PeanutAdmin\DataPermission\Provider\{ResourceQueryPolicyProvider,ResourceTargetPolicyProvider,ResourceCreatePolicyProvider,StandardResourcePolicyProvider,ProviderColumnMap,DepartmentHierarchyProvider,TargetSetMembershipProvider};
use PeanutAdmin\DataPermission\Constraint\{ColumnReference,QueryConstraint};
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;

final readonly class AiScopeProvider implements ResourceQueryPolicyProvider, ResourceTargetPolicyProvider, ResourceCreatePolicyProvider
{
    private StandardResourcePolicyProvider $native;
    public function __construct(DepartmentHierarchyProvider $departments, TargetSetMembershipProvider $targets)
    {
        $this->native = new StandardResourcePolicyProvider(new ProviderColumnMap(new ColumnReference('tenant_id'), new ColumnReference('created_by')), $departments, $targets);
    }
    public function tenantConstraint(AuthorizationContext $c, ResourceOperation $o): QueryConstraint
    {
        return $this->native->tenantConstraint($c, $o);
    }
    public function requestedTargetConstraint(AuthorizationContext $c, ResourceOperation $o, TypedResourceTargetCollection $t): QueryConstraint
    {
        return $this->native->requestedTargetConstraint($c, $o, $t);
    }
    public function compilePredicate(AuthorizationContext $c, ResourceOperation $o, EffectivePolicySet $p): QueryConstraint
    {
        return $this->native->compilePredicate($c, $o, $p);
    }
    public function assertTargetsAllowed(AuthorizationContext $c, ResourceOperation $o, TypedResourceTargetCollection $t, EffectivePolicySet $p): AuthorizationDecision
    {
        return $this->native->assertTargetsAllowed($c, $o, $t, $p);
    }
    public function assertCreateAllowed(AuthorizationContext $c, ResourceOperation $o, TypedResourceTargetCollection $t, EffectivePolicySet $p): AuthorizationDecision
    {
        $decision = $this->native->assertCreateAllowed($c, $o, $t, $p);
        if ($decision->allowed || $t->sets !== []) {
            return $decision;
        }
        foreach ($p->groups as $g) {
            if (count($g->conditions) === 1 && $g->conditions[0]->key === 'core.self') {
                return AuthorizationDecision::allow([$g->policyId]);
            }
        }
        return $decision;
    }
}
