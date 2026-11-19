<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Policy;

use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Policy\PolicyRevision;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;

final readonly class ThinkPhpPolicyRepository implements \PeanutAdmin\DataPermission\Policy\PolicyRepository
{
    public function revision(int $tenantId, int $memberId, int $operationId): PolicyRevision
    {
        return $this->revisions($tenantId, $memberId, [$operationId])[$operationId];
    }

    /** @param list<int> $operationIds @return array<int, PolicyRevision> */
    public function revisions(int $tenantId, int $memberId, array $operationIds): array
    {
        $revisionHash = hash('sha256', "standard:{$tenantId}:{$memberId}");
        $revisions = [];
        foreach (array_values(array_unique($operationIds)) as $id) {
            $revisions[(int) $id] = new PolicyRevision($revisionHash, null);
        }
        return $revisions;
    }

    public function load(int $tenantId, int $memberId, int $operationId): EffectivePolicySet
    {
        return $this->loadMany($tenantId, $memberId, [$operationId])[$operationId];
    }

    /** @param list<int> $operationIds @return array<int, EffectivePolicySet> */
    public function loadMany(int $tenantId, int $memberId, array $operationIds): array
    {
        $dept = TenantMember::where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->value('primary_department_id');
        $deptId = $dept === null ? null : (int) $dept;
        $result = [];
        foreach (array_values(array_unique($operationIds)) as $id) {
            $result[(int) $id] = new EffectivePolicySet([], $deptId);
        }
        return $result;
    }
}
