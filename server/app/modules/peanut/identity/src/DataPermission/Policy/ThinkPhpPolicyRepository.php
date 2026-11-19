<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\DataPermission\Policy;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\DataPermission\Policy\EffectiveCondition;
use PeanutAdmin\DataPermission\Policy\EffectiveConditionGroup;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Policy\PolicyRepository;
use PeanutAdmin\DataPermission\Policy\PolicyRevision;
use PeanutAdmin\Modules\Identity\DataPermission\Catalog\ThinkPhpResourceOperationCatalog;
use PeanutAdmin\Modules\Identity\DataPermission\Provider\ThinkPhpTargetSetMembershipProvider;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use think\facade\Db;

final readonly class ThinkPhpPolicyRepository implements PolicyRepository
{
    public const CONDITION_KEYS = [
        'all' => 'core.tenant_all',
        'dept_and_sub' => 'core.department_tree',
        'dept' => 'core.own_department',
        'custom_dept' => 'core.specified_departments',
        'self' => 'core.self',
    ];

    public function __construct(
        private ThinkPhpResourceOperationCatalog $catalog,
        private ThinkPhpTargetSetMembershipProvider $targetSets,
    ) {}

    public function revision(int $tenantId, int $memberId, int $operationId): PolicyRevision
    {
        return $this->revisions($tenantId, $memberId, [$operationId])[$operationId];
    }

    /** @param list<int> $operationIds @return array<int, PolicyRevision> */
    public function revisions(int $tenantId, int $memberId, array $operationIds): array
    {
        [$department, $rows] = $this->policySnapshot($tenantId, $memberId, $operationIds);
        $now = self::now();
        $revisions = [];
        foreach ($rows as $id => $policies) {
            $next = null;
            foreach ($policies as $policy) {
                foreach ([$policy['valid_from'], $policy['valid_until']] as $transition) {
                    if ($transition !== null && $transition > $now && ($next === null || $transition < $next)) {
                        $next = $transition;
                    }
                }
            }
            $revision = hash('sha256', json_encode(
                [$tenantId, $memberId, $id, $department, $policies],
                JSON_THROW_ON_ERROR,
            ));
            $revisions[$id] = new PolicyRevision(
                $revision,
                $next === null ? null : new DateTimeImmutable($next, new DateTimeZone('UTC')),
            );
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
        [$department, $rows] = $this->policySnapshot($tenantId, $memberId, $operationIds);
        $now = self::now();
        $result = [];
        foreach ($rows as $id => $policies) {
            $groups = [];
            $conditions = $this->catalog->allowedConditions($id);
            foreach ($policies as $policy) {
                if (($policy['valid_from'] !== null && $policy['valid_from'] > $now)
                    || ($policy['valid_until'] !== null && $policy['valid_until'] <= $now)) {
                    continue;
                }
                array_push($groups, ...$this->effectiveGroups($tenantId, $policy, $conditions));
            }
            $result[$id] = new EffectivePolicySet($groups, $department);
        }
        return $result;
    }

    /** @param list<int> $operationIds @return array{int|null, array<int, list<array<string, mixed>>>} */
    private function policySnapshot(int $tenantId, int $memberId, array $operationIds): array
    {
        $rows = array_fill_keys($operationIds, []);
        $idsByKey = [];
        foreach ($operationIds as $id) {
            $operation = $this->catalog->findById($id);
            if ($operation !== null) {
                $idsByKey[$operation->resourceKey . ':' . $operation->operation] = $id;
            }
        }
        if ($idsByKey === []) {
            return [null, $rows];
        }

        $member = TenantMember::where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->where('status', 'active')
            ->field('primary_department_id')
            ->find();
        if ($member === null) {
            return [null, $rows];
        }
        $department = $member->getAttr('primary_department_id');

        $policies = Db::name('data_permission')->alias('policy')
            ->join('role r', "r.tenant_id = policy.tenant_id AND r.id = policy.role_id AND r.status = 'active'")
            ->join('member_role mr', 'mr.tenant_id = r.tenant_id AND mr.role_id = r.id')
            ->where('policy.tenant_id', $tenantId)
            ->where('mr.tenant_member_id', $memberId)
            ->where('policy.status', 'active')
            ->whereIn('policy.resource_key', array_keys($idsByKey))
            ->field('policy.id,policy.role_id,policy.resource_key,policy.scope_type,policy.custom_targets_json,policy.condition_groups_json,policy.valid_from,policy.valid_until,policy.revision')
            ->order('policy.id')
            ->select();
        foreach ($policies as $policy) {
            // Use exact manifest keys even on a case-insensitive SQL collation.
            $id = $idsByKey[$policy['resource_key']] ?? null;
            if ($id !== null) {
                $rows[$id][] = $policy;
            }
        }
        return [$department === null ? null : (int) $department, $rows];
    }

    /** @param array<string, mixed> $policy @param array<string, list<string|null>> $allowed
     * @return list<EffectiveConditionGroup>
     */
    private function effectiveGroups(int $tenantId, array $policy, array $allowed): array
    {
        $policyId = (int) $policy['id'];
        $roleId = (int) $policy['role_id'];
        $scope = $policy['scope_type'];
        if ($scope !== 'rules') {
            $key = self::CONDITION_KEYS[$scope] ?? null;
            if ($key === null || !array_key_exists($key, $allowed)) {
                return [];
            }
            $targetIds = $scope === 'custom_dept' ? self::readList($policy['custom_targets_json']) : [];
            $condition = $this->condition(
                $tenantId, $policyId, $key, $scope === 'custom_dept' ? -$policyId : null,
                $scope === 'custom_dept' ? 'core.department' : null, $targetIds,
            );
            return [new EffectiveConditionGroup($policyId, $roleId, $policyId, [$condition])];
        }

        $groups = [];
        foreach (self::readList($policy['condition_groups_json']) as $group) {
            $conditions = [];
            foreach ($group['conditions'] as $entry) {
                $key = $entry['key'];
                $target = $entry['target_resource_key'];
                if (!array_key_exists($key, $allowed)
                    || ($key === 'core.specified_objects' && !in_array($target, $allowed[$key], true))) {
                    continue 2;
                }
                $conditions[] = $this->condition(
                    $tenantId, $entry['id'], $key, $entry['target_set_id'], $target, $entry['target_ids'],
                );
            }
            if ($conditions !== []) {
                $groups[] = new EffectiveConditionGroup($policyId, $roleId, $group['id'], $conditions);
            }
        }
        return $groups;
    }

    private function condition(
        int $tenantId,
        int $id,
        string $key,
        ?int $targetSetId,
        ?string $targetResourceKey,
        array $targetIds,
    ): EffectiveCondition {
        $targets = array_values(array_unique(array_map('strval', $targetIds)));
        $count = count($targets);
        if ($targetSetId !== null && $count > 500) {
            $this->targetSets->recordTargets($tenantId, $targetSetId, $targets);
        }
        return new EffectiveCondition($id, $key, $targetSetId, $targetResourceKey, $targets, $count);
    }

    private static function readList(?string $json): array
    {
        return $json === null ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
