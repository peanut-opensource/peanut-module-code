<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Membership\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Persistence\Model\Account;
use PeanutAdmin\Modules\Identity\Persistence\Model\Credential;
use PeanutAdmin\Modules\Identity\Persistence\Model\Department;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberJob;
use PeanutAdmin\Modules\Identity\Persistence\Model\Role;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use Throwable;
use think\db\Raw;
use think\facade\Db;

/** Owns tenant member administration and atomic profile, role and status commands. */
final readonly class MemberAdminService
{
    public const MAX_ROLE_IDS = 100;
    public const MAX_JOB_IDS = 100;

    public function __construct(
        private AuditService $audit,
        private PasswordHasher $passwords,
        private PasswordPolicy $passwordPolicy,
    ) {}

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function createAdministrator(
        TenantContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
        ?int $primaryDepartmentId,
        array $roleIds,
        bool $enabled,
        array $jobIds = [],
    ): array {
        $this->assertRoleIdsLimit($roleIds);
        $jobIds = $this->normalizeJobIds($jobIds);

        return $this->transaction(function () use (
            $actor,
            $email,
            $displayName,
            $initialPassword,
            $primaryDepartmentId,
            $roleIds,
            $enabled,
            $jobIds,
        ): array {
            $member = $this->createPendingInTransaction($actor, $email, $displayName, $initialPassword);
            if ($primaryDepartmentId !== null) {
                $member = $this->updateInTransaction(
                    $actor,
                    (int) $member['id'],
                    $displayName,
                    $primaryDepartmentId,
                    (int) $member['revision'],
                );
            }

            $this->replaceJobsInTransaction($actor, (int) $member['id'], $jobIds);
            return $this->configureAdministratorInTransaction($actor, $member, $roleIds, $enabled);
        });
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function updateAdministrator(
        TenantContext $actor,
        int $memberId,
        string $displayName,
        ?int $primaryDepartmentId,
        array $roleIds,
        bool $enabled,
        int $expectedRevision,
        ?array $jobIds = null,
    ): array {
        $this->assertRoleIdsLimit($roleIds);
        $jobIds = $jobIds === null ? null : $this->normalizeJobIds($jobIds);

        return $this->transaction(function () use (
            $actor,
            $memberId,
            $displayName,
            $primaryDepartmentId,
            $roleIds,
            $enabled,
            $expectedRevision,
            $jobIds,
        ): array {
            $this->requireTenantStatus($actor->tenantId, 'active', true);
            $member = $this->requireMember($actor->tenantId, $memberId, true);
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            $member = $this->updateInTransaction(
                $actor,
                $memberId,
                $displayName,
                $primaryDepartmentId,
                $expectedRevision,
            );

            if ($jobIds !== null) {
                $this->replaceJobsInTransaction($actor, $memberId, $jobIds);
            }
            return $this->configureAdministratorInTransaction($actor, $member, $roleIds, $enabled);
        });
    }

    /** @return list<int> */
    private function normalizeJobIds(array $jobIds): array
    {
        if (!array_is_list($jobIds) || count($jobIds) > self::MAX_JOB_IDS) {
            throw AdminAccessException::invalid('MEMBER_JOB_IDS_INVALID', 'A bounded list of positive job identifiers is required.');
        }
        $ids = [];
        foreach ($jobIds as $id) {
            if ((!is_int($id) && (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw AdminAccessException::invalid('MEMBER_JOB_IDS_INVALID', 'Positive job identifiers are required.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** Descriptive job membership never grants a role or action permission. @param list<int> $jobIds */
    private function replaceJobsInTransaction(TenantContext $actor, int $memberId, array $jobIds): void
    {
        $current = array_map('intval', MemberJob::where('tenant_id', $actor->tenantId)
            ->where('tenant_member_id', $memberId)->order('job_id')->column('job_id'));
        if ($jobIds !== []) {
            // The job lock also serializes assignment against job deletion/status changes.
            $jobs = Db::name('jobs')->where('tenant_id', $actor->tenantId)->whereIn('id', $jobIds)
                ->whereNull('delete_time')->order('id')->lock(true)->field('id,status')->select()->toArray();
            if (count($jobs) !== count($jobIds)) {
                throw AdminAccessException::notFound();
            }
            foreach ($jobs as $job) {
                if ((int) $job['status'] !== 1 && !in_array((int) $job['id'], $current, true)) {
                    throw AdminAccessException::conflict('MEMBER_JOB_INACTIVE', 'A disabled job cannot receive a new member assignment.');
                }
            }
        }
        if ($current === $jobIds) {
            return;
        }
        MemberJob::where('tenant_id', $actor->tenantId)->where('tenant_member_id', $memberId)->delete();
        if ($jobIds !== []) {
            $now = $this->now();
            MemberJob::insertAll(array_map(static fn(int $jobId): array => [
                'tenant_id' => $actor->tenantId,
                'tenant_member_id' => $memberId,
                'job_id' => $jobId,
                'assigned_at' => $now,
            ], $jobIds));
        }
        $this->recordAudit($actor, 'tenant.member.jobs-replaced', 'core.member.update', $memberId);
    }

    /** @param array<string, mixed> $member
     * @param list<int> $roleIds
     * @return array<string, mixed>
     */
    private function configureAdministratorInTransaction(
        TenantContext $actor,
        array $member,
        array $roleIds,
        bool $enabled,
    ): array {
        if ($roleIds === []) {
            throw AdminAccessException::invalid('ADMIN_ROLE_REQUIRED', 'An administrator role is required.');
        }
        $memberId = (int) $member['id'];
        $member = $this->replaceRolesInTransaction($actor, $memberId, $roleIds, (int) $member['revision']);
        if ($enabled && in_array($member['status'], ['pending', 'suspended'], true)) {
            return $this->transitionInTransaction(
                $actor,
                $memberId,
                ['pending', 'suspended'],
                'active',
                (int) $member['revision'],
                'core.member.activate',
            );
        }
        if (!$enabled && $member['status'] === 'active') {
            return $this->transitionInTransaction(
                $actor,
                $memberId,
                ['active'],
                'suspended',
                (int) $member['revision'],
                'core.member.suspend',
            );
        }

        return $member;
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $query = TenantMember::where('tenant_id', $tenantId);
        $total = (int) (clone $query)->count();
        $rows = $query->field(
            'id,display_name,member_no,member_type,primary_department_id,status,security_revision,authorization_revision',
        )->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => $this->hydrateRoles($tenantId, array_values($rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $memberId): array
    {
        $row = TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)->field(
            'id,display_name,member_no,member_type,primary_department_id,status,security_revision,authorization_revision',
        )->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->hydrateRoles($tenantId, [$row])[0];
    }

    /** @return array<string, mixed> */
    public function createPending(
        TenantContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        return $this->transaction(fn(): array => $this->createPendingInTransaction(
            $actor,
            $email,
            $displayName,
            $initialPassword,
        ));
    }

    /** @return array<string, mixed> */
    private function createPendingInTransaction(
        TenantContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        try {
            $identifier = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $this->requireTenantStatus($actor->tenantId, 'active', true);
        $candidateAccountId = Credential::where('identifier_type', 'email')->where('identifier_normalized', $identifier)->value('account_id');
        if ($candidateAccountId !== null) {
            Account::where('id', (int) $candidateAccountId)->lock(true)->find();
        }
        $credential = Credential::where('identifier_type', 'email')
            ->where('identifier_normalized', $identifier)->lock(true)->field('id,account_id,status')->find()?->toArray();
        if ($credential !== null && (int) $credential['account_id'] !== (int) $candidateAccountId) {
            throw AdminAccessException::conflict('MEMBER_ACCOUNT_AUTHORITY_CHANGED', 'The account binding changed while acquiring its authority locks.');
        }
        if ($credential === null) {
            if ($initialPassword === null || $initialPassword === '') {
                throw AdminAccessException::invalid(
                    'INITIAL_PASSWORD_REQUIRED',
                    'An initial password is required for a new account.',
                );
            }
            $accountId = $this->createAccountAndCredential($identifier, $displayName, $initialPassword);
        } else {
            if ($initialPassword !== null) {
                throw AdminAccessException::invalid(
                    'INITIAL_PASSWORD_NOT_ALLOWED',
                    'An existing account credential cannot be overwritten.',
                );
            }
            if ($credential['status'] !== 'active') {
                throw AdminAccessException::conflict('CREDENTIAL_INACTIVE', 'The account credential is inactive.');
            }
            $accountId = (int) $credential['account_id'];
            if (Account::where('id', $accountId)->lock(true)->value('status') !== 'active') {
                throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The account is inactive.');
            }
        }
        $existing = TenantMember::where('tenant_id', $actor->tenantId)
            ->where('account_id', $accountId)->lock(true)->field('id,status')->find()?->toArray();
        if ($existing !== null && $existing['status'] !== 'left') {
            throw AdminAccessException::conflict('MEMBER_ALREADY_EXISTS', 'The account is already a tenant member.');
        }
        $now = $this->now();
        if ($existing === null) {
            $memberId = (int) TenantMember::insertGetId([
                'tenant_id' => $actor->tenantId,
                'account_id' => $accountId,
                'display_name' => $displayName,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $memberId = (int) $existing['id'];
            TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)->update([
                'display_name' => $displayName,
                'status' => 'pending',
                'primary_department_id' => null,
                'security_revision' => new Raw('security_revision + 1'),
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'joined_at' => null,
                'suspended_at' => null,
                'left_at' => null,
                'updated_at' => $now,
            ]);
            MemberRole::where('tenant_id', $actor->tenantId)
                ->where('tenant_member_id', $memberId)->delete();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.pending-created', 'core.member.create', $memberId);

        return $this->get($actor->tenantId, $memberId);
    }

    /** @return array<string, mixed> */
    public function update(
        TenantContext $actor,
        int $memberId,
        ?string $displayName,
        ?int $primaryDepartmentId,
        int $expectedRevision,
    ): array {
        return $this->transaction(fn(): array => $this->updateInTransaction(
            $actor,
            $memberId,
            $displayName,
            $primaryDepartmentId,
            $expectedRevision,
        ));
    }

    /** @return array<string, mixed> */
    private function updateInTransaction(
        TenantContext $actor,
        int $memberId,
        ?string $displayName,
        ?int $primaryDepartmentId,
        int $expectedRevision,
    ): array {
        Tenant::where('id', $actor->tenantId)->lock(true)->find();
        $member = $this->requireMember($actor->tenantId, $memberId, true);
        if ((int) $member['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
        if ($primaryDepartmentId !== null && Department::where('tenant_id', $actor->tenantId)
            ->where('id', $primaryDepartmentId)->where('status', 'active')->value('id') === null) {
            throw AdminAccessException::notFound();
        }
        $now = $this->now();
        if (TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)
            ->where('authorization_revision', $expectedRevision)->update([
                'display_name' => $displayName,
                'primary_department_id' => $primaryDepartmentId,
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]) !== 1) {
            throw AdminAccessException::revisionMismatch();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.updated', 'core.member.update', $memberId);

        return $this->get($actor->tenantId, $memberId);
    }

    /** @return array<string, mixed> */
    public function activate(TenantContext $actor, int $memberId, int $expectedRevision): array
    {
        return $this->transition($actor, $memberId, ['pending', 'suspended'], 'active', $expectedRevision, 'core.member.activate');
    }

    /** @return array<string, mixed> */
    public function suspend(TenantContext $actor, int $memberId, int $expectedRevision): array
    {
        return $this->transition($actor, $memberId, ['active'], 'suspended', $expectedRevision, 'core.member.suspend');
    }

    /** @return array<string, mixed> */
    public function leave(TenantContext $actor, int $memberId, int $expectedRevision): array
    {
        return $this->transition(
            $actor,
            $memberId,
            ['pending', 'active', 'suspended'],
            'left',
            $expectedRevision,
            'core.member.leave',
        );
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function replaceRoles(
        TenantContext $actor,
        int $memberId,
        array $roleIds,
        int $expectedRevision,
    ): array {
        $this->assertRoleIdsLimit($roleIds);

        return $this->transaction(fn(): array => $this->replaceRolesInTransaction(
            $actor,
            $memberId,
            $roleIds,
            $expectedRevision,
        ));
    }

    /** @param list<int> $roleIds */
    private function assertRoleIdsLimit(array $roleIds): void
    {
        if (count($roleIds) > self::MAX_ROLE_IDS) {
            throw AdminAccessException::invalid(
                'MEMBER_ROLE_LIMIT_EXCEEDED',
                'At most ' . self::MAX_ROLE_IDS . ' role identifiers may be supplied.',
            );
        }
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    private function replaceRolesInTransaction(
        TenantContext $actor,
        int $memberId,
        array $roleIds,
        int $expectedRevision,
    ): array {
        $this->requireTenantStatus($actor->tenantId, 'active', true);
        $roleIds = array_values(array_unique($roleIds));
        $member = $this->requireMember($actor->tenantId, $memberId, true);
        if ((int) $member['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
        $roles = $this->rolesByIds($actor->tenantId, $roleIds);
        if (count($roles) !== count($roleIds)) {
            throw AdminAccessException::notFound();
        }
        $activeOwnerMemberIds = $this->activeOwnerMemberIdsForUpdate($actor->tenantId);
        $currentlyOwner = in_array($memberId, $activeOwnerMemberIds, true);
        $keepsOwner = false;
        foreach ($roles as $role) {
            $keepsOwner = $keepsOwner || ($role['key'] === 'core.tenant-owner' && (int) $role['is_builtin'] === 1);
        }
        if ($currentlyOwner && !$keepsOwner) {
            $this->assertOwnerRemovalAllowed($activeOwnerMemberIds);
        }
        $oldRoleIds = array_map('intval', MemberRole::where('tenant_id', $actor->tenantId)->where('tenant_member_id', $memberId)->order('role_id')->column('role_id'));
        MemberRole::where('tenant_id', $actor->tenantId)->where('tenant_member_id', $memberId)->delete();
        $now = $this->now();
        if ($roleIds !== []) {
            MemberRole::insertAll(array_map(
                static fn(int $roleId): array => [
                    'tenant_id' => $actor->tenantId,
                    'tenant_member_id' => $memberId,
                    'role_id' => $roleId,
                    'assigned_by_member_id' => $actor->memberId,
                    'assigned_at' => $now,
                ],
                $roleIds,
            ));
        }
        if (TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)
            ->where('authorization_revision', $expectedRevision)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]) !== 1) {
            throw AdminAccessException::revisionMismatch();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.roles-replaced', 'core.member.role.assign', $memberId);
        if (array_diff($oldRoleIds, $roleIds) !== []) {
            $this->audit->recordRevocation(
                $actor,
                $actor->tenantId,
                $memberId,
                'member',
                (string) $memberId,
                'member_roles_removed',
                ['authorization_revision' => $expectedRevision, 'role_ids' => $oldRoleIds],
                ['authorization_revision' => $expectedRevision + 1, 'role_ids' => $roleIds],
            );
        }

        return $this->get($actor->tenantId, $memberId);
    }

    /** @param list<string> $fromStatuses
     * @return array<string, mixed>
     */
    private function transition(
        TenantContext $actor,
        int $memberId,
        array $fromStatuses,
        string $nextStatus,
        int $expectedRevision,
        string $action,
    ): array {
        return $this->transaction(fn(): array => $this->transitionInTransaction(
            $actor,
            $memberId,
            $fromStatuses,
            $nextStatus,
            $expectedRevision,
            $action,
        ));
    }

    /** @param list<string> $fromStatuses
     * @return array<string, mixed>
     */
    private function transitionInTransaction(
        TenantContext $actor,
        int $memberId,
        array $fromStatuses,
        string $nextStatus,
        int $expectedRevision,
        string $action,
    ): array {
        $this->requireTenantStatus($actor->tenantId, 'active', true);
        $member = $this->requireMember($actor->tenantId, $memberId, true);
        if ((int) $member['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
        if (!in_array($member['status'], $fromStatuses, true)) {
            throw AdminAccessException::conflict('MEMBER_STATUS_CONFLICT', 'The member status transition is not allowed.');
        }
        if (in_array($nextStatus, ['suspended', 'left'], true)) {
            $activeOwnerMemberIds = $this->activeOwnerMemberIdsForUpdate($actor->tenantId);
            if (in_array($memberId, $activeOwnerMemberIds, true)) {
                $this->assertOwnerRemovalAllowed($activeOwnerMemberIds);
            }
        }
        $now = $this->now();
        $data = [
            'status' => $nextStatus,
            'security_revision' => new Raw('security_revision + 1'),
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ];
        if ($nextStatus === 'active') {
            $data['joined_at'] = $now;
        } elseif ($nextStatus === 'suspended') {
            $data['suspended_at'] = $now;
        } elseif ($nextStatus === 'left') {
            $data['left_at'] = $now;
        }
        if (TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)
            ->where('authorization_revision', $expectedRevision)->update($data) !== 1) {
            throw AdminAccessException::revisionMismatch();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.' . $nextStatus, $action, $memberId);
        if (in_array($nextStatus, ['suspended', 'left'], true)) {
            $this->audit->recordRevocation(
                $actor,
                $actor->tenantId,
                $memberId,
                'member',
                (string) $memberId,
                'member_' . $nextStatus,
                ['authorization_revision' => $expectedRevision, 'security_revision' => (int) $member['security_revision'], 'status' => $member['status']],
                ['authorization_revision' => $expectedRevision + 1, 'security_revision' => (int) $member['security_revision'] + 1, 'status' => $nextStatus],
            );
        }

        return $this->get($actor->tenantId, $memberId);
    }

    private function createAccountAndCredential(string $identifier, string $displayName, string $password): int
    {
        $this->passwordPolicy->assertValid($password);
        $now = $this->now();
        $accountId = (int) Account::insertGetId([
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Credential::insert([
            'account_id' => $accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => $identifier,
            'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now,
            'secret_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $accountId;
    }

    /** @return array<string, mixed> */
    private function requireMember(int $tenantId, int $memberId, bool $forUpdate): array
    {
        $query = TenantMember::where('tenant_id', $tenantId)->where('id', $memberId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find()?->toArray() ?? throw AdminAccessException::notFound();
    }

    private function requireTenantStatus(int $tenantId, string $status, bool $forUpdate): void
    {
        $query = Tenant::where('id', $tenantId);
        if ($forUpdate) {
            $query->lock(true);
        }
        if ($query->value('status') !== $status) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant status does not allow this operation.');
        }
    }

    /** @return list<int> */
    private function activeOwnerMemberIdsForUpdate(int $tenantId): array
    {
        $rows = MemberRole::alias('member_role')
            ->join(
                'tenant_member member',
                'member.tenant_id = member_role.tenant_id AND member.id = member_role.tenant_member_id',
            )
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)
            ->where('member.tenant_id', $tenantId)
            ->where('role.tenant_id', $tenantId)
            ->where('member.status', 'active')
            ->where('role.key', 'core.tenant-owner')
            ->where('role.is_builtin', 1)
            ->where('role.status', 'active')
            ->field([
                'member_role.id' => 'assignment_id',
                'member_role.tenant_member_id',
            ])
            ->order('member_role.tenant_member_id')
            ->order('member_role.id')
            ->lock(true)
            ->select();

        $memberIds = [];
        foreach ($rows as $row) {
            $memberIds[(int) $row->getAttr('tenant_member_id')] = true;
        }

        return array_keys($memberIds);
    }

    /** @param list<int> $activeOwnerMemberIds */
    private function assertOwnerRemovalAllowed(array $activeOwnerMemberIds): void
    {
        if (count($activeOwnerMemberIds) <= 1) {
            throw AdminAccessException::conflict(
                'LAST_ACTIVE_OWNER_REQUIRED',
                'The final active tenant owner cannot be removed or suspended.',
            );
        }
    }

    /** @param list<int> $roleIds
     * @return list<array{key: string, is_builtin: int}>
     */
    private function rolesByIds(int $tenantId, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        /** @var list<array{key: string, is_builtin: int}> $roles */
        $roles = Role::where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('id', $roleIds)->field('id,key,is_builtin')->select()->toArray();

        return $roles;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRoles(int $tenantId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $memberIds = array_map('intval', array_column($rows, 'id'));
        $roleKeys = [];
        $roleIds = [];
        foreach (MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)->whereIn('member_role.tenant_member_id', $memberIds)
            ->field(['member_role.tenant_member_id', 'role.id', 'role.key'])->order('role.key')->select()->toArray() as $role) {
            $roleKeys[(int) $role['tenant_member_id']][] = (string) $role['key'];
            $roleIds[(int) $role['tenant_member_id']][] = (int) $role['id'];
        }
        foreach ($rows as &$row) {
            $memberId = (int) $row['id'];
            $row = [
                'id' => (string) $row['id'],
                'display_name' => $row['display_name'],
                'member_no' => $row['member_no'],
                'member_type' => $row['member_type'],
                'primary_department_id' => $row['primary_department_id'] === null
                    ? null
                    : (string) $row['primary_department_id'],
                'status' => $row['status'],
                'security_revision' => (string) $row['security_revision'],
                'revision' => (string) $row['authorization_revision'],
                'role_keys' => array_values(array_unique($roleKeys[$memberId] ?? [])),
                'role_ids' => array_values(array_unique($roleIds[$memberId] ?? [])),
            ];
        }
        unset($row);

        return $rows;
    }

    private function bumpTenantAuthorization(int $tenantId, string $now): void
    {
        Tenant::where('id', $tenantId)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }

    private function recordAudit(TenantContext $actor, string $eventType, string $action, int $memberId): void
    {
        $this->audit->tenantMember(
            context: $actor,
            eventType: $eventType,
            action: $action,
            targetResourceType: 'member',
            targetResourceId: (string) $memberId,
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
                throw AdminAccessException::conflict('RELATION_CONFLICT', 'The requested relation already exists.');
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
