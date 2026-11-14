<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Identity\Query;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\WorkflowOrganizationQuery;
use PeanutAdmin\Modules\Identity\Persistence\Model\Department;
use PeanutAdmin\Modules\Identity\Persistence\Model\Role;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;

final readonly class ThinkPhpWorkflowOrganizationQuery implements WorkflowOrganizationQuery
{
    private const LIMIT = 500;

    public function __construct(private TenantMemberDirectory $members) {}

    public function resolve(TenantContext $actor, string $kind, string $key, ?string $departmentScope = null): array
    {
        $this->requireActor($actor);
        if ($key === '' || strlen($key) > 128 || ($departmentScope !== null && ($departmentScope === '' || strlen($departmentScope) > 128))) {
            throw AdminAccessException::invalid('WORKFLOW_ORGANIZATION_KEY_INVALID', 'A concrete organization key is required.');
        }
        $query = TenantMember::alias('member')
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->where('member.tenant_id', $actor->tenantId)->where('member.status', 'active');
        $binding = [];
        switch ($kind) {
            case 'member':
                if (preg_match('/^[1-9][0-9]*$/D', $key) !== 1 || strlen($key) > 18 || $departmentScope !== null) {
                    throw AdminAccessException::invalid('WORKFLOW_MEMBER_KEY_INVALID', 'A canonical member id is required.');
                }
                $query->where('member.id', $key);
                break;
            case 'role_members':
                $role = Role::where('tenant_id', $actor->tenantId)->where('key', $key)->where('status', 'active')
                    ->field('id,key,authorization_revision')->find()?->toArray();
                if ($role === null) {
                    return [];
                }
                if (!hash_equals((string) $role['key'], $key)) {
                    throw AdminAccessException::invalid('WORKFLOW_ROLE_KEY_INVALID', 'Use the canonical role key.');
                }
                $binding['role'] = (string) $role['id'] . ':' . (string) $role['authorization_revision'];
                $query->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
                    ->where('membership.role_id', $role['id']);
                if ($departmentScope !== null) {
                    $department = $this->department($actor, $departmentScope);
                    if ($department === null) {
                        return [];
                    }
                    $query->where('member.primary_department_id', $department['id']);
                    $binding['department'] = (string) $department['id'] . ':' . (string) $department['revision'];
                }
                break;
            case 'department_members':
            case 'department_leaders':
                if ($departmentScope !== null) {
                    throw AdminAccessException::invalid('WORKFLOW_DEPARTMENT_SCOPE_INVALID', 'Department rules already declare their direct scope.');
                }
                $department = $this->department($actor, $key);
                if ($department === null) {
                    return [];
                }
                $binding['department'] = (string) $department['id'] . ':' . (string) $department['revision'];
                if ($kind === 'department_members') {
                    $query->where('member.primary_department_id', $department['id']);
                } elseif ($department['leader_member_id'] === null) {
                    return [];
                } else {
                    $query->where('member.id', $department['leader_member_id']);
                }
                break;
            default:
                throw AdminAccessException::invalid('WORKFLOW_ORGANIZATION_KIND_UNSUPPORTED', 'No registered organization provider exists for this kind.');
        }
        $rows = $query->distinct(true)->field('member.id,member.security_revision,member.authorization_revision,account.security_revision AS account_revision')
            ->order('member.id')->limit(self::LIMIT + 1)->select()->toArray();
        if (count($rows) > self::LIMIT) {
            throw AdminAccessException::invalid('WORKFLOW_ASSIGNMENT_LIMIT_EXCEEDED', 'Narrow the assignment to at most 500 active members.');
        }
        $sourceKey = json_encode([$key, $departmentScope], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($sourceKey) > 160) {
            throw AdminAccessException::invalid('WORKFLOW_ORGANIZATION_SOURCE_TOO_LONG', 'Use bounded canonical organization keys.');
        }
        return array_map(static fn(array $row): array => [
            'member_id' => (int) $row['id'], 'source_kind' => $kind, 'source_key' => $sourceKey,
            'binding_revision' => hash('sha256', json_encode([$binding, $row], JSON_THROW_ON_ERROR)),
        ], $rows);
    }

    public function eligible(TenantContext $actor, int $memberId, array $sources): bool
    {
        $this->requireActor($actor);
        if ($memberId < 1 || $sources === [] || count($sources) > 100 || $this->members->activeMembership($actor->tenantId, $memberId) === null) {
            return false;
        }
        foreach ($sources as $source) {
            if (!is_array($source) || !is_string($source['source_kind'] ?? null) || !is_string($source['source_key'] ?? null)
                || !is_string($source['binding_revision'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $source['binding_revision']) !== 1) {
                return false;
            }
            try {
                $key = json_decode($source['source_key'], true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }
            if (!is_array($key) || array_keys($key) !== [0, 1] || !is_string($key[0])
                || ($key[1] !== null && !is_string($key[1]))) {
                return false;
            }
            foreach ($this->resolve($actor, $source['source_kind'], $key[0], $key[1]) as $candidate) {
                if ($candidate['member_id'] === $memberId) {
                    return true;
                }
            }
        }
        return false;
    }

    public function businessDepartment(TenantContext $actor, ?string $requestedKey): ?string
    {
        $this->requireActor($actor);
        $member = $this->members->current($actor);
        $department = $member?->primaryDepartmentId === null ? null : Department::where('tenant_id', $actor->tenantId)
            ->where('id', $member->primaryDepartmentId)->where('status', 'active')->field('code')->find()?->toArray();
        $key = $department === null ? null : (string) $department['code'];
        if ($key !== null) {
            $this->department($actor, $key); // Reject ambiguous business keys before they enter a snapshot.
        }
        if ($requestedKey !== null && ($key === null || !hash_equals($key, $requestedKey))) {
            throw AdminAccessException::invalid('WORKFLOW_BUSINESS_DEPARTMENT_INVALID', 'The requested business department is not a current direct membership.');
        }
        return $key;
    }

    public function activeDepartmentKey(TenantContext $actor, string $key): ?string
    {
        $this->requireActor($actor);
        if ($key === '' || strlen($key) > 128) {
            throw AdminAccessException::invalid('WORKFLOW_DEPARTMENT_KEY_INVALID', 'Use a bounded canonical department key.');
        }
        $department = $this->department($actor, $key);
        return $department === null ? null : (string) $department['code'];
    }

    private function department(TenantContext $actor, string $key): ?array
    {
        $rows = Department::where('tenant_id', $actor->tenantId)->where('code', $key)->where('status', 'active')
            ->field('id,code,revision,leader_member_id')->limit(2)->select()->toArray();
        if (count($rows) > 1) {
            throw AdminAccessException::invalid('WORKFLOW_DEPARTMENT_AMBIGUOUS', 'Department keys must be unambiguous.');
        }
        if (isset($rows[0]) && !hash_equals((string) $rows[0]['code'], $key)) {
            throw AdminAccessException::invalid('WORKFLOW_DEPARTMENT_KEY_INVALID', 'Use the canonical department key.');
        }
        return $rows[0] ?? null;
    }

    private function requireActor(TenantContext $actor): void
    {
        if ($this->members->current($actor) === null) {
            throw new AdminAccessException('WORKFLOW_ORGANIZATION_ACTOR_INVALID', 403, 'The current tenant member is no longer valid.');
        }
    }
}
