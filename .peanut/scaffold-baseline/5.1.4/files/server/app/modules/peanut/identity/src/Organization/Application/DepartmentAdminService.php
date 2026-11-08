<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Organization\Application;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Modules\Identity\Persistence\Model\Department;
use PeanutAdmin\Modules\Identity\Persistence\Model\Account;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use Throwable;
use think\db\Raw;
use think\facade\Db;

final readonly class DepartmentAdminService
{
    private const MAX_DEPTH = 10;

    public function __construct(private AuditService $audit) {}

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $query = Department::where('tenant_id', $tenantId);
        $total = (int) (clone $query)->count();
        $rows = $query->field('id,parent_id,code,name,leader,leader_member_id,mobile,sort_order,status,revision,created_at,updated_at')
            ->order('sort_order')->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => array_values(array_map($this->normalize(...), $rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $departmentId): array
    {
        $row = Department::where('tenant_id', $tenantId)->where('id', $departmentId)
            ->field('id,parent_id,code,name,leader,leader_member_id,mobile,sort_order,status,revision,created_at,updated_at')->find()?->toArray();

        return $row === null ? throw AdminAccessException::notFound() : $this->normalize($row);
    }

    /** A page of matches and its bounded ancestor closure; ancestors never affect total. */
    public function directory(int $tenantId, PageRequest $page, string $mode, int $parentId, string $name, ?string $status, array $ids, bool $activeOnly, bool $flat): array
    {
        $query = Department::where('tenant_id', $tenantId);
        if ($mode === 'children') {
            if ($parentId > 0 && $this->get($tenantId, $parentId)['status'] === 'archived') {
                throw AdminAccessException::invalid('DEPARTMENT_ARCHIVED', 'Cannot browse archived departments.');
            }
            $parentId === 0 ? $query->whereNull('parent_id') : $query->where('parent_id', $parentId);
            if ($activeOnly) { $query->whereIn('status', ['active', 'disabled']); }
        } elseif ($mode === 'resolve') {
            $query->whereIn('id', $ids);
        } else {
            if ($name !== '') { $query->whereRaw('LOCATE(?, name) > 0', [$name]); }
            if ($status !== null) { $query->where('status', $status); }
            if ($activeOnly) { $query->where('status', 'active'); }
        }
        if ($flat) { $query->where('status', 'active'); }
        $total = (int) (clone $query)->count();
        $rows = $query->field('id,parent_id,code,name,leader,leader_member_id,mobile,sort_order,status,revision,created_at,updated_at')
            ->order('sort_order')->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();
        $matches = array_column($rows, null, 'id');
        $known = $matches;
        $pending = $rows;
        // At most one batched query per depth, never one query per department.
        for ($depth = 0; $pending !== [] && $depth < self::MAX_DEPTH; ++$depth) {
            $parents = [];
            foreach ($pending as $row) {
                if ($row['parent_id'] !== null && !isset($known[(int) $row['parent_id']])) {
                    $parents[(int) $row['parent_id']] = true;
                }
            }
            if ($parents === []) { break; }
            $pending = Department::where('tenant_id', $tenantId)->whereIn('id', array_keys($parents))
                ->field('id,parent_id,name,sort_order,status')->select()->toArray();
            if (count($pending) !== count($parents)) {
                throw AdminAccessException::conflict('DEPARTMENT_PATH_INVALID', 'Department parent is missing from this tenant.');
            }
            foreach ($pending as $row) {
                if ($row['status'] === 'archived') {
                    throw AdminAccessException::conflict('DEPARTMENT_PATH_INVALID', 'Archived ancestor in department path.');
                }
                $known[(int) $row['id']] = $row;
            }
        }
        foreach ($matches as $row) {
            $seen = [];
            for ($depth = 1; ; ++$depth) {
                $id = (int) $row['id'];
                if ($depth > self::MAX_DEPTH || isset($seen[$id])) {
                    throw AdminAccessException::conflict('DEPARTMENT_PATH_INVALID', 'Department path exceeds depth or contains a cycle.');
                }
                $seen[$id] = true;
                if ($depth > 1 && $row['status'] === 'archived') {
                    throw AdminAccessException::conflict('DEPARTMENT_PATH_INVALID', 'Archived ancestor in department path.');
                }
                if ($row['parent_id'] === null) { break; }
                $row = $known[(int) $row['parent_id']] ?? throw AdminAccessException::conflict('DEPARTMENT_PATH_INVALID', 'Incomplete department path.');
            }
        }
        $children = [];
        if ($known !== []) {
            $childQuery = Department::where('tenant_id', $tenantId)->whereIn('parent_id', array_keys($known));
            if ($activeOnly) { $childQuery->whereIn('status', ['active', 'disabled']); }
            $children = array_fill_keys($childQuery->group('parent_id')->column('parent_id'), true);
        }
        $project = static function (array $row, bool $structural) use ($children): array {
            $item = ['id' => (int) $row['id'], 'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'name' => $row['name'], 'status' => $row['status'], 'sort_order' => (int) $row['sort_order'],
                'structuralOnly' => $structural, 'selectable' => !$structural && $row['status'] === 'active',
                'hasChildren' => isset($children[(int) $row['id']])];
            return $structural ? $item : $item + array_intersect_key($row, array_flip(['code', 'leader', 'leader_member_id', 'mobile', 'revision', 'created_at', 'updated_at']));
        };
        return ['items' => array_values(array_map(fn(array $r): array => $project($r, $activeOnly && $r['status'] !== 'active'), $matches)),
            'ancestors' => array_values(array_map(fn(array $r): array => $project($r, true), array_diff_key($known, $matches))), 'total' => $total];
    }

    public function setStatus(TenantContext $actor, int $departmentId, int $expectedRevision, bool $active): void
    {
        $this->transaction(function () use ($actor, $departmentId, $expectedRevision, $active): void {
            $this->lockTenant($actor->tenantId);
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            $status = $active ? 'active' : 'disabled';
            if ($department['status'] === $status) {
                return;
            }
            if ($department['status'] === 'archived') {
                throw AdminAccessException::conflict('DEPARTMENT_ARCHIVED', 'An archived department cannot change status.');
            }
            $now = $this->now();
            Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)->update([
                'status' => $status,
                'revision' => new Raw('revision + 1'),
                'updated_at' => $now,
            ]);
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'department.status-changed', 'core.department.update', $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function create(
        TenantContext $actor,
        string $code,
        string $name,
        ?int $parentId,
        int $sortOrder,
        string $leader,
        string $mobile,
    ): array {
        [$leader, $mobile] = $this->contactFields($leader, $mobile);
        return $this->transaction(function () use ($actor, $code, $name, $parentId, $sortOrder, $leader, $mobile): array {
            $this->lockTenant($actor->tenantId);
            if ($parentId !== null) {
                $this->requireActive($actor->tenantId, $parentId, true);
                if ($this->depth($actor->tenantId, $parentId) >= self::MAX_DEPTH) {
                    throw AdminAccessException::invalid('DEPARTMENT_DEPTH_EXCEEDED', 'Department depth cannot exceed 10.');
                }
            }
            $now = $this->now();
            $departmentId = (int) Department::insertGetId([
                'tenant_id' => $actor->tenantId,
                'parent_id' => $parentId,
                'code' => $code,
                'name' => $name,
                'leader' => $leader,
                'mobile' => $mobile,
                'sort_order' => $sortOrder,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.created', 'core.department.create', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function update(
        TenantContext $actor,
        int $departmentId,
        string $code,
        string $name,
        int $sortOrder,
        int $expectedRevision,
        string $leader,
        string $mobile,
        ?int $leaderMemberId,
    ): array {
        [$leader, $mobile] = $this->contactFields($leader, $mobile);
        return $this->transaction(function () use (
            $actor,
            $departmentId,
            $code,
            $name,
            $sortOrder,
            $expectedRevision,
            $leader,
            $mobile,
            $leaderMemberId,
        ): array {
            $this->lockTenant($actor->tenantId);
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($department['status'] === 'archived') {
                throw AdminAccessException::conflict('DEPARTMENT_ARCHIVED', 'An archived department cannot be edited.');
            }
            if ($leaderMemberId !== null) {
                $this->requireLeader($actor->tenantId, $leaderMemberId);
            }
            $now = $this->now();
            if (Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)
                ->where('revision', $expectedRevision)->update([
                    'code' => $code,
                    'name' => $name,
                    'leader' => $leader,
                    'leader_member_id' => $leaderMemberId,
                    'mobile' => $mobile,
                    'sort_order' => $sortOrder,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.updated', 'core.department.update', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function move(
        TenantContext $actor,
        int $departmentId,
        ?int $newParentId,
        int $expectedRevision,
    ): array {
        return $this->transaction(function () use ($actor, $departmentId, $newParentId, $expectedRevision): array {
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($newParentId === $departmentId) {
                throw AdminAccessException::invalid('DEPARTMENT_CYCLE', 'A department cannot be its own parent.');
            }
            $parentDepth = 0;
            if ($newParentId !== null) {
                $this->requireActive($actor->tenantId, $newParentId, true);
                if ($this->isDescendant($actor->tenantId, $departmentId, $newParentId)) {
                    throw AdminAccessException::invalid('DEPARTMENT_CYCLE', 'Department moves cannot create a cycle.');
                }
                $parentDepth = $this->depth($actor->tenantId, $newParentId);
            }
            if ($parentDepth + $this->subtreeDepth($actor->tenantId, $departmentId) > self::MAX_DEPTH) {
                throw AdminAccessException::invalid('DEPARTMENT_DEPTH_EXCEEDED', 'Department depth cannot exceed 10.');
            }
            $now = $this->now();
            if (Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)
                ->where('revision', $expectedRevision)->update([
                    'parent_id' => $newParentId,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.moved', 'core.department.move', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    public function archive(TenantContext $actor, int $departmentId, int $expectedRevision): array
    {
        return $this->transaction(function () use ($actor, $departmentId, $expectedRevision): array {
            $department = $this->requireDepartment($actor->tenantId, $departmentId, true);
            $this->assertRevision($department, $expectedRevision);
            if ($department['status'] === 'archived') {
                throw AdminAccessException::conflict('DEPARTMENT_ALREADY_ARCHIVED', 'The department is already archived.');
            }
            $childCount = Department::where('tenant_id', $actor->tenantId)
                ->where('parent_id', $departmentId)->where('status', '<>', 'archived')->count();
            $memberCount = TenantMember::where('tenant_id', $actor->tenantId)
                ->where('primary_department_id', $departmentId)
                ->whereIn('status', ['pending', 'active', 'suspended'])->count();
            if ((int) $childCount !== 0 || (int) $memberCount !== 0) {
                throw AdminAccessException::conflict(
                    'DEPARTMENT_NOT_EMPTY',
                    'Move child departments and current members before archiving.',
                );
            }
            $now = $this->now();
            if (Department::where('tenant_id', $actor->tenantId)->where('id', $departmentId)
                ->where('revision', $expectedRevision)->update([
                    'status' => 'archived',
                    'archived_at' => $now,
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpTenant($actor->tenantId, $now);
            $this->recordAudit($actor, 'tenant.department.archived', 'core.department.archive', $departmentId);

            return $this->get($actor->tenantId, $departmentId);
        });
    }

    /** @return array<string, mixed> */
    private function requireDepartment(int $tenantId, int $departmentId, bool $forUpdate): array
    {
        $query = Department::where('tenant_id', $tenantId)->where('id', $departmentId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find()?->toArray() ?? throw AdminAccessException::notFound();
    }

    private function requireActive(int $tenantId, int $departmentId, bool $forUpdate): void
    {
        if ($this->requireDepartment($tenantId, $departmentId, $forUpdate)['status'] !== 'active') {
            throw AdminAccessException::invalid('DEPARTMENT_INACTIVE', 'The parent department must be active.');
        }
    }

    /** Leaders are explicit bindings, independent of a member's primary department. */
    public function leaderCandidates(int $tenantId, int $departmentId, PageRequest $page, string $search): array
    {
        $this->requireActive($tenantId, $departmentId, false);
        $query = TenantMember::alias('member')
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->where('member.tenant_id', $tenantId)
            ->where('member.status', 'active');
        if ($search !== '') {
            $query->whereRaw('LOCATE(?, member.display_name) > 0', [$search]);
        }
        $total = (int) (clone $query)->count();
        $rows = $query->field('member.id,member.display_name')->order('member.id')
            ->limit($page->offset(), $page->pageSize)->select()->toArray();
        return ['items' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'name' => (string) $row['display_name'],
        ], $rows), 'total' => $total];
    }

    private function requireLeader(int $tenantId, int $memberId): void
    {
        $member = TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)->lock(true)->find()?->toArray();
        if ($member === null || $member['status'] !== 'active'
            || Account::where('id', $member['account_id'])->where('status', 'active')->lock(true)->value('id') === null) {
            throw AdminAccessException::invalid('DEPARTMENT_LEADER_INVALID', 'Choose an active member of this tenant with an active account.');
        }
    }

    /** @param array<string, mixed> $department */
    private function assertRevision(array $department, int $expectedRevision): void
    {
        if ((int) $department['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function lockTenant(int $tenantId): void
    {
        if (Tenant::where('id', $tenantId)->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant is not active.');
        }
    }

    private function depth(int $tenantId, int $departmentId): int
    {
        $depth = 0;
        $current = $departmentId;
        while ($current !== 0 && $depth <= self::MAX_DEPTH) {
            $parent = Department::where('tenant_id', $tenantId)->where('id', $current)->value('parent_id');
            ++$depth;
            $current = $parent === null ? 0 : (int) $parent;
        }

        return $depth;
    }

    private function subtreeDepth(int $tenantId, int $departmentId): int
    {
        $depth = 0;
        $frontier = [$departmentId];
        while ($frontier !== [] && $depth <= self::MAX_DEPTH) {
            ++$depth;
            $frontier = array_map('intval', Department::where('tenant_id', $tenantId)
                ->whereIn('parent_id', $frontier)->column('id'));
        }

        return $depth;
    }

    private function isDescendant(int $tenantId, int $departmentId, int $possibleDescendantId): bool
    {
        $frontier = [$departmentId];
        for ($depth = 0; $depth <= self::MAX_DEPTH && $frontier !== []; ++$depth) {
            if (in_array($possibleDescendantId, $frontier, true)) {
                return true;
            }
            $frontier = array_map('intval', Department::where('tenant_id', $tenantId)
                ->whereIn('parent_id', $frontier)->column('id'));
        }

        return false;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'code' => $row['code'],
            'name' => $row['name'],
            'leader' => $row['leader'],
            'leader_member_id' => $row['leader_member_id'] === null ? null : (string) $row['leader_member_id'],
            'mobile' => $row['mobile'],
            'sort_order' => (int) $row['sort_order'],
            'status' => $row['status'],
            'revision' => (string) $row['revision'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** @return array{string, string} */
    private function contactFields(string $leader, string $mobile): array
    {
        $leader = trim($leader);
        $mobile = trim($mobile);
        if (mb_strlen($leader) > 50 || mb_strlen($mobile) > 20) {
            throw AdminAccessException::invalid('DEPARTMENT_CONTACT_INVALID', 'Department contact fields are too long.');
        }
        return [$leader, $mobile];
    }

    private function bumpTenant(int $tenantId, string $now): void
    {
        Tenant::where('id', $tenantId)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }

    private function recordAudit(TenantContext $actor, string $eventType, string $action, int $departmentId): void
    {
        $this->audit->tenantMember(
            context: $actor,
            eventType: $eventType,
            action: $action,
            targetResourceType: 'department',
            targetResourceId: (string) $departmentId,
        );
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict('DEPARTMENT_CONFLICT', 'Department code or relation conflicts.');
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
