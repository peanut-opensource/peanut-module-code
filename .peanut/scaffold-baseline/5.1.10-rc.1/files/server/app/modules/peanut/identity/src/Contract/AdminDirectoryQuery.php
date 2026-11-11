<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberJob;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Cache;
use think\facade\Db;
use app\common\http\PageResult;

/** Non-ORM read boundary for the native Account/TenantMember directory. */
final readonly class AdminDirectoryQuery
{
    public function __construct(
        private CurrentExecutionContext $execution,
    ) {}

    /** Tenant-wide presentation settings; customer addresses/timezones belong to the business Module.
     * @return array{id:int,code:string,name:string,display_name:string,locale:string,timezone:string}
     */
    public function tenantProfile(TenantContext $context): array
    {
        $read = static function () use ($context): array {
            $row = Tenant::where('id', $context->tenantId)->where('status', 'active')
                ->field('id,code,name,display_name,locale,timezone')->find();
            if ($row === null) {
                throw new \DomainException('TENANT_PROFILE_UNAVAILABLE');
            }
            $profile = $row->toArray();
            $profile['id'] = (int) $profile['id'];
            return $profile;
        };
        $admission = TenantAuthService::authenticatedRead($context);
        if ($admission === null) {
            return $read();
        }
        return Cache::remember('identity:tenant-profile:v1:' . $context->tenantId . ':'
            . $admission['record']->tenantRevision, $read, 300);
    }

    /**
     * Lifecycle projection for a bounded caller-selected set, not a grant of access.
     * @param list<int> $tenantIds
     * @return list<int>
     */
    public function activeTenantIds(array $tenantIds): array
    {
        foreach ($tenantIds as $tenantId) {
            if (!is_int($tenantId) || $tenantId < 1) {
                throw new \InvalidArgumentException('TENANT_ID_INVALID');
            }
        }
        $active = [];
        foreach (array_chunk(array_values(array_unique($tenantIds)), 500) as $chunk) {
            foreach (Tenant::whereIn('id', $chunk)->where('status', 'active')->column('id') as $id) {
                $active[] = (int) $id;
            }
        }
        return $active;
    }

    /**
     * Complete active-tenant selection for an already authorized platform qualification overview.
     * Includes tenants without configured bindings; this metadata is not an access grant.
     * @return list<int>
     */
    public function activeTenantIdsForQualification(): array
    {
        return array_map('intval', Tenant::where('status', 'active')->order('id')->column('id'));
    }

    /**
     * Locked selection for an explicit product profile, within its existing transaction.
     * The caller still rejects missing, inactive, duplicate or non-exact requested codes.
     * @param list<string> $tenantCodes
     * @return list<array{id:int,code:string}>
     */
    public function profileTenantsForUpdate(array $tenantCodes): array
    {
        $rows = Tenant::whereIn('code', $tenantCodes)->where('status', 'active')
            ->field('id,code')->order('code')->lock(true)->select()->toArray();
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['code']], $rows);
    }

    /**
     * Minimal lifecycle projection for trusted binding resolution; not an authorization grant.
     * A locking read participates in the caller's current transaction and must precede binding locks.
     */
    public function tenantStatus(int $tenantId, bool $forUpdate = false): ?string
    {
        if ($tenantId < 1) {
            return null;
        }
        $query = Tenant::where('id', $tenantId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $status = $query->value('status');
        return is_string($status) ? $status : null;
    }

    /**
     * Current display names for existing tenant-owned log references, not historical snapshots.
     * Inactive members remain addressable; missing references are omitted and never cross tenants.
     * @param list<int> $memberIds
     * @return array<int,string>
     */
    public function memberDisplayNames(\PeanutAdmin\Kernel\Auth\TenantContext $context, array $memberIds): array
    {
        $names = [];
        foreach ($memberIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('TENANT_MEMBER_ID_INVALID');
            }
        }
        $memberIds = array_values(array_unique($memberIds));
        sort($memberIds, SORT_NUMERIC);
        $admission = TenantAuthService::authenticatedRead($context);
        foreach (array_chunk($memberIds, 500) as $chunk) {
            $key = 'identity:member-names:v1:' . $context->tenantId . ':'
                . $context->tenantAuthorizationRevision . ':' . hash('sha256', implode(',', $chunk));
            $batch = $admission === null ? null : Cache::get($key);
            if ($batch === null) {
                $batch = [];
                $rows = TenantMember::where('tenant_id', $context->tenantId)
                    ->whereIn('id', $chunk)->field('id,display_name')->select()->toArray();
                foreach ($rows as $row) {
                    $batch[(int) $row['id']] = (string) $row['display_name'];
                }
                if ($admission !== null) {
                    Cache::set($key, $batch, 300);
                }
            }
            $names += $batch;
        }
        return $names;
    }

    /**
     * Presentation only, for an already admitted actor; never an operation grant.
     * Null IDs select the email directory, explicit IDs resolve bounded references (including inactive members).
     * directoryOnly retains that directory membership restriction for selected historical references.
     * Active/name semantics match TenantMemberDirectory, without revalidating the actor per reference.
     * @param null|list<int> $memberIds
     * @param null|list<int> $departmentIds Null leaves departments unrestricted; explicit IDs are unioned below.
     * @param list<int> $includeMemberIds Additional candidates such as self or historical goal holders.
     * @return array<int,array{id:int,display_name:string,active_name:string,primary_department_id:?int,status:string,active:bool}>
     */
    public function memberSummaries(TenantContext $context, ?array $memberIds = null, string $name = '', bool $directoryOnly = false,
        ?array $departmentIds = null, array $includeMemberIds = []): array
    {
        if ($this->execution->tenantAdmin() !== $context) {
            throw new \DomainException('EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED');
        }
        if ($memberIds !== null) {
            foreach ($memberIds as $id) {
                if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('TENANT_MEMBER_ID_INVALID');
            }
        }
        $result = [];
        $chunks = $memberIds === null ? [null] : array_chunk(array_values(array_unique($memberIds)), 500);
        foreach ($chunks as $chunk) {
            $query = TenantMember::alias('member')
                ->join('tenant tenant', 'tenant.id=member.tenant_id')
                ->join('account account', 'account.id=member.account_id')
                ->where('member.tenant_id', $context->tenantId)
                ->field('member.id,member.display_name,member.primary_department_id,member.status,account.display_name AS account_name,account.status AS account_status,tenant.status AS tenant_status');
            if ($chunk !== null) $query->whereIn('member.id', $chunk);
            if ($chunk === null || $directoryOnly) {
                $credential = \think\facade\Db::name('credential')
                    ->whereColumn('account_id', 'member.account_id')->where('identifier_type', 'email')->field('account_id')->buildSql();
                $query->whereRaw('EXISTS ' . $credential);
            }
            if ($departmentIds !== null) {
                $query->where(function ($scope) use ($departmentIds, $includeMemberIds) {
                    $scope->whereIn('member.primary_department_id', $departmentIds);
                    if ($includeMemberIds) $scope->whereOr('member.id', 'in', $includeMemberIds);
                });
            }
            if (!empty($name)) $query->whereLike('member.display_name', '%' . $name . '%');
            foreach ($query->order('member.id', 'desc')->select()->toArray() as $row) {
                $activeName = trim((string) ($row['display_name'] ?: $row['account_name']));
                $id = (int) $row['id'];
                $result[$id] = ['id'=>$id, 'display_name'=>(string) $row['display_name'], 'active_name'=>$activeName,
                    'primary_department_id'=>$row['primary_department_id'] === null ? null : (int) $row['primary_department_id'],
                    'status'=>(string) $row['status'],
                    'active'=>$row['status'] === 'active' && $row['account_status'] === 'active' && $row['tenant_status'] === 'active' && $activeName !== ''];
            }
        }
        return $result;
    }

    /**
     * Native pagination for self plus the caller's permitted departments and inactive historical references.
     * The caller owns department/history authorization and supplies the admitted actor's display name.
     * Historical IDs do not include active members who have moved outside the permitted departments.
     * @param list<int> $departmentIds
     * @param list<int> $historicalMemberIds
     * @return array{lists:list<array{id:string,name:string}>,count:int,pageNo:int,pageSize:int}
     */
    public function memberOptionsPage(TenantContext $context, array $departmentIds, string $selfName, string $name,
        int $page, int $pageSize, array $historicalMemberIds = [], string $inactiveSuffix = ''): array
    {
        if ($this->execution->tenantAdmin() !== $context) {
            throw new \DomainException('EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED');
        }
        // PHP's ?: also falls back for the literal "0"; binary comparison avoids SQL padding rules.
        $activeName = "CASE WHEN member.display_name IS NULL OR OCTET_LENGTH(member.display_name)=0
            OR CAST(member.display_name AS BINARY)=CAST('0' AS BINARY)
            THEN COALESCE(account.display_name, '') ELSE member.display_name END";
        $nonBlank = $activeName;
        // Only detect blank names here; trim the selected page in PHP with the original whitespace semantics.
        foreach ([0, 9, 10, 11, 13, 32] as $char) $nonBlank = "REPLACE($nonBlank, CHAR($char), '')";
        $active = "(member.status='active' AND account.status='active' AND tenant.status='active' AND OCTET_LENGTH($nonBlank)>0)";
        $credential = Db::name('credential')->whereColumn('account_id', 'member.account_id')
            ->where('identifier_type', 'email')->field('account_id')->buildSql();
        $base = TenantMember::alias('member')
            ->join('tenant tenant', 'tenant.id=member.tenant_id')->join('account account', 'account.id=member.account_id')
            ->where('member.tenant_id', $context->tenantId)
            ->where(function ($scope) use ($context, $departmentIds, $historicalMemberIds) {
                $scope->whereIn('member.id', [$context->memberId, ...$historicalMemberIds]);
                if ($departmentIds) $scope->whereOr('member.primary_department_id', 'in', $departmentIds);
            })
            ->where(function ($directory) use ($context, $credential) {
                $directory->where('member.id', $context->memberId)->whereOrRaw('EXISTS ' . $credential);
            })
            ->field('member.id,member.display_name,member.primary_department_id')
            ->fieldRaw("$activeName AS active_name, $active AS active");
        if ($name !== '') {
            if (mb_stripos($selfName, $name) === false) $base->where('member.id', '<>', $context->memberId);
            if (!empty($name)) {
                $base->where(function ($search) use ($context, $name) {
                    $search->where('member.id', $context->memberId)->whereOr('member.display_name', 'like', '%' . $name . '%');
                });
            }
        }
        $choices = Db::table([$base->buildSql()=>'directory_member'])->pk('id')->field('id,active')
            ->field(Db::raw('CASE WHEN id=' . $context->memberId . ' THEN ? WHEN active=1 THEN active_name ELSE CONCAT(display_name, ?) END AS option_name', [$selfName, $inactiveSuffix]))
            ->where(function ($scope) use ($context, $departmentIds, $historicalMemberIds) {
                $scope->where('id', $context->memberId);
                if ($departmentIds) $scope->whereOr(function ($team) use ($departmentIds) {
                    $team->where('active', 1)->whereIn('primary_department_id', $departmentIds);
                });
                if ($historicalMemberIds) $scope->whereOr(function ($history) use ($historicalMemberIds) {
                    $history->where('active', 0)->whereIn('id', $historicalMemberIds);
                });
            });
        $query = Db::table([$choices->buildSql()=>'member_options'])->pk('id');
        if ($name !== '') $query->where(function ($search) use ($context, $name) {
            // LIKE above preserves the directory search; LOCATE keeps the final literal, case-insensitive match.
            $search->where('id', $context->memberId)
                ->whereOrRaw('LOCATE(CAST(LOWER(?) AS BINARY), CAST(LOWER(option_name) AS BINARY)) > 0', [$name]);
        });
        $query->orderRaw('(id=' . $context->memberId . ') DESC')->order('id', 'desc');
        return PageResult::fromPaginator($query->paginate(['list_rows'=>$pageSize, 'page'=>$page]), $page)
            ->map(static fn(array $row): array => ['id'=>(string) $row['id'],
                'name'=>$row['active'] ? trim((string) $row['option_name']) : (string) $row['option_name']])->responseData();
    }

    /** 受信初始化命令在事务内读取并锁定目标；返回稳定代码，不授予调用者权限。 */
    public function bootstrapTenantCode(int $tenantId): ?string
    {
        if ($tenantId < 1) {
            return null;
        }
        $code = Tenant::where('id', $tenantId)->whereIn('status', ['provisioning', 'active'])
            ->lock(true)->value('code');
        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * 核验初始化调用方已提供的四元组；只返回关系是否成立，不授予初始化或管理权限。
     * 与activeTenantOwner不同，本入口位于租户激活前，不能附加active租户前提。
     */
    public function isProvisionedOwner(int $tenantId, int $accountId, int $memberId, int $roleId): bool
    {
        if (min($tenantId, $accountId, $memberId, $roleId) < 1) {
            return false;
        }
        return TenantMember::alias('member')
            ->join('account account', "account.id=member.account_id AND account.status='active'")
            ->join('member_role membership', 'membership.tenant_id=member.tenant_id AND membership.tenant_member_id=member.id')
            ->join('role role', "role.tenant_id=membership.tenant_id AND role.id=membership.role_id AND role.`key`='core.tenant-owner' AND role.is_builtin=1 AND role.status='active'")
            ->where('member.tenant_id', $tenantId)->where('member.id', $memberId)
            ->where('member.account_id', $accountId)->where('member.status', 'active')
            ->where('role.id', $roleId)->value('member.id') !== null;
    }

    /** Active directory members in a selected tenant department set; no profiles, roles or jobs are loaded.
     * @param list<int> $departmentIds @return list<int>
     */
    public function activeMemberIdsInDepartments(\PeanutAdmin\Kernel\Auth\TenantContext $context, array $departmentIds): array
    {
        if ($departmentIds === []) return [];
        if ($this->execution->tenantAdmin() !== $context) {
            throw new \DomainException('EXECUTION_TENANT_ADMIN_CONTEXT_REQUIRED');
        }
        return array_map('intval', TenantMember::alias('member')
            ->join('account account', 'account.id = member.account_id')
            ->join('credential credential', "credential.account_id = account.id AND credential.identifier_type = 'email'")
            ->where('member.tenant_id', $context->tenantId)->where('member.status', 'active')
            ->whereIn('member.primary_department_id', $departmentIds)
            ->distinct(true)->column('member.id'));
    }

    /** @return list<array<string,mixed>> */
    public function rows(array $filters): array
    {
        $context = $this->execution->tenantAdmin();
        $query = TenantMember::alias('member')
            ->join('account account', 'account.id = member.account_id')
            ->join('credential credential', "credential.account_id = account.id AND credential.identifier_type = 'email'")
            ->leftJoin('department department', 'department.tenant_id = member.tenant_id AND department.id = member.primary_department_id')
            ->leftJoin('member_role membership', 'membership.tenant_id = member.tenant_id AND membership.tenant_member_id = member.id')
            ->leftJoin('role role', 'role.tenant_id = membership.tenant_id AND role.id = membership.role_id')
            ->where('member.tenant_id', $context->tenantId)
            ->field([
                'member.id', 'member.account_id', 'member.display_name', 'member.primary_department_id', 'member.status',
                'member.created_at', 'member.updated_at', 'account.avatar_uri', 'account.last_login_at',
                // ThinkORM 数组字段以表达式为键、响应别名为值，保持原查询输出合同。
                'credential.identifier_normalized' => 'username', 'department.name' => 'department_name',
            ])
            ->fieldRaw("GROUP_CONCAT(DISTINCT role.id ORDER BY role.id SEPARATOR ',') AS role_ids")
            ->fieldRaw("GROUP_CONCAT(DISTINCT role.name ORDER BY role.`key` SEPARATOR '/') AS role_name")
            ->fieldRaw("MAX(CASE WHEN role.`key` = 'core.tenant-owner' AND role.is_builtin = 1 AND role.status = 'active' THEN 1 ELSE 0 END) AS root");
        if (!empty($filters['account'])) {
            $query->whereLike('credential.identifier_normalized', '%' . trim((string) $filters['account']) . '%');
        }
        if (!empty($filters['name'])) {
            $query->whereLike('member.display_name', '%' . trim((string) $filters['name']) . '%');
        }
        if (!empty($filters['id'])) {
            $query->where('member.id', (int) $filters['id']);
        }
        if (!empty($filters['role_id'])) {
            $query->join('member_role filter_membership', 'filter_membership.tenant_id = member.tenant_id AND filter_membership.tenant_member_id = member.id')
                ->where('filter_membership.role_id', (int) $filters['role_id']);
        }
        $rows = $query->group('member.id,member.account_id,member.display_name,member.primary_department_id,member.status,member.created_at,member.updated_at,account.avatar_uri,account.last_login_at,credential.identifier_normalized,department.name')
            ->order('member.id', 'desc')->select()->toArray();
        $jobs = [];
        foreach (array_chunk(array_column($rows, 'id'), 500) as $memberIds) {
            $assignments = MemberJob::alias('assignment')
                ->join('jobs job', 'job.tenant_id=assignment.tenant_id AND job.id=assignment.job_id')
                ->where('assignment.tenant_id', $context->tenantId)
                ->whereIn('assignment.tenant_member_id', $memberIds)
                ->whereNull('job.delete_time')->order('job.id')
                ->field('assignment.tenant_member_id,job.id,job.name')->select()->toArray();
            foreach ($assignments as $job) {
                $jobs[(int) $job['tenant_member_id']][(int) $job['id']] = (string) $job['name'];
            }
        }
        foreach ($rows as &$row) {
            $assigned = $jobs[(int) $row['id']] ?? [];
            $row['job_ids'] = array_keys($assigned);
            $row['job_name'] = implode('/', $assigned);
        }
        unset($row);
        return $rows;
    }

    /**
     * 供受信任务恢复所有者资料；不授予操作权限，也不要求员工在线会话。
     * 指定成员时必须同时指定账号，且租户、账号、成员和内建所有者关系均仍有效。
     *
     * @return null|array{id:int,account_id:int,authorization_revision:int,tenant_authorization_revision:int}
     */
    public function activeTenantOwner(int $tenantId, ?int $memberId, ?int $accountId): ?array
    {
        if ($tenantId < 1 || ($memberId === null) !== ($accountId === null)
            || ($memberId !== null && ($memberId < 1 || $accountId < 1))) {
            return null;
        }
        $query = TenantMember::alias('member')
            ->join('tenant tenant', "tenant.id = member.tenant_id AND tenant.status = 'active'")
            ->join('account account', "account.id = member.account_id AND account.status = 'active'")
            ->join('member_role membership', 'membership.tenant_id = member.tenant_id AND membership.tenant_member_id = member.id')
            ->join('role role', "role.tenant_id = membership.tenant_id AND role.id = membership.role_id AND role.`key` = 'core.tenant-owner' AND role.is_builtin = 1 AND role.status = 'active'")
            ->where('member.tenant_id', $tenantId)->where('member.status', 'active')
            ->field('member.id,member.account_id,member.authorization_revision,tenant.authorization_revision AS tenant_authorization_revision');
        if ($memberId !== null && $accountId !== null) {
            $query->where('member.id', $memberId)->where('member.account_id', $accountId);
        }
        $owner = $query->order('member.id')->find()?->toArray();

        return $owner !== null ? [
            'id' => (int) $owner['id'],
            'account_id' => (int) $owner['account_id'],
            'authorization_revision' => (int) $owner['authorization_revision'],
            'tenant_authorization_revision' => (int) $owner['tenant_authorization_revision'],
        ] : null;
    }

    /** A single current membership/credential projection; the optional count serves the Admin shell.
     * @return null|array{tenant_name:string,username:string,avatar:string,last_login_at:mixed,member:Dto\TenantMemberSummary,switchable_tenant_count?:int}
     */
    public function activePrincipalProfile(int $tenantId, int $accountId, bool $includeTenantCount = false): ?array
    {
        $row = $this->principalProfileQuery($tenantId, $accountId, $includeTenantCount)->find()?->toArray();

        return $row === null ? null : $this->principalProfileFromRow($row, $includeTenantCount);
    }

    /**
     * Current principal and active roles in one read, without retaining state between calls.
     * @return null|array{profile:array{tenant_name:string,username:string,avatar:string,last_login_at:mixed,member:Dto\TenantMemberSummary,switchable_tenant_count?:int},roles:list<array{id:int,key:string,name:string,is_builtin:bool}>}
     */
    public function activePrincipalProfileWithRoles(int $tenantId, int $accountId, bool $includeTenantCount = false): ?array
    {
        // Select the same single profile before expanding roles: credentials are unique by identifier, not account.
        $profileSql = $this->principalProfileQuery($tenantId, $accountId, $includeTenantCount)->limit(1)->buildSql();
        $rows = \think\facade\Db::table($profileSql)->alias('principal_profile')
            ->leftJoin('member_role membership', 'membership.tenant_id = principal_profile.tenant_id AND membership.tenant_member_id = principal_profile.member_id')
            ->leftJoin('role role', "role.tenant_id = membership.tenant_id AND role.id = membership.role_id AND role.status = 'active'")
            ->field([
                'principal_profile.*',
                'role.id' => 'principal_role_id',
                'role.key' => 'principal_role_key',
                'role.name' => 'principal_role_name',
                'role.is_builtin' => 'principal_role_is_builtin',
            ])
            ->order('role.key')->order('role.id')->select()->toArray();
        if ($rows === []) {
            return null;
        }
        $profile = $this->principalProfileFromRow($rows[0], $includeTenantCount);
        if ($profile === null) {
            return null;
        }
        $roles = [];
        foreach ($rows as $row) {
            // An active principal with no active roles must remain available.
            if ($row['principal_role_id'] === null) {
                continue;
            }
            $roles[] = [
                'id' => (int) $row['principal_role_id'],
                'key' => (string) $row['principal_role_key'],
                'name' => (string) $row['principal_role_name'],
                'is_builtin' => (int) $row['principal_role_is_builtin'] === 1,
            ];
        }

        return ['profile' => $profile, 'roles' => $roles];
    }

    private function principalProfileQuery(int $tenantId, int $accountId, bool $includeTenantCount): \think\db\Query
    {
        $query = TenantMember::alias('member')
            ->join('tenant tenant', "tenant.id = member.tenant_id AND tenant.status = 'active'")
            ->join('account account', "account.id = member.account_id AND account.status = 'active'")
            ->join('credential credential', "credential.account_id = account.id AND credential.kind = 'email_password' AND credential.identifier_type = 'email' AND credential.status = 'active'")
            ->where('member.tenant_id', $tenantId)
            ->where('member.account_id', $accountId)
            ->where('member.status', 'active')
            ->field([
                'member.id' => 'member_id', 'member.tenant_id', 'member.account_id', 'member.display_name',
                'member.primary_department_id', 'member.security_revision', 'member.authorization_revision',
                'account.display_name' => 'account_display_name',
                'account.security_revision' => 'account_security_revision',
                'tenant.security_revision' => 'tenant_security_revision',
                'tenant.authorization_revision' => 'tenant_authorization_revision',
                'tenant.name' => 'tenant_name', 'credential.identifier_normalized' => 'username',
                'account.avatar_uri' => 'avatar', 'account.last_login_at',
            ]);
        if ($includeTenantCount) {
            $count = TenantMember::alias('candidate')
                ->join('tenant candidate_tenant', "candidate_tenant.id = candidate.tenant_id AND candidate_tenant.status = 'active'")
                ->join('account candidate_account', "candidate_account.id = candidate.account_id AND candidate_account.status = 'active'")
                ->whereColumn('candidate.account_id', 'member.account_id')
                ->where('candidate.status', 'active')->fieldRaw('COUNT(*)');
            $query->fieldRaw('(' . $count->buildSql(false) . ') AS switchable_tenant_count');
        }
        return $query;
    }

    /**
     * @param array<string,mixed> $row
     * @return null|array{tenant_name:string,username:string,avatar:string,last_login_at:mixed,member:Dto\TenantMemberSummary,switchable_tenant_count?:int}
     */
    private function principalProfileFromRow(array $row, bool $includeTenantCount): ?array
    {
        $displayName = trim((string) ($row['display_name'] ?: $row['account_display_name']));
        if ($displayName === '') {
            return null;
        }
        $result = [
            'tenant_name' => (string) $row['tenant_name'],
            'username' => (string) $row['username'],
            'avatar' => (string) ($row['avatar'] ?? ''),
            'last_login_at' => $row['last_login_at'],
            'member' => new Dto\TenantMemberSummary(
                (int) $row['tenant_id'], (int) $row['member_id'], (int) $row['account_id'], $displayName,
                (int) $row['account_security_revision'], (int) $row['tenant_security_revision'],
                (int) $row['security_revision'], (int) $row['authorization_revision'],
                (int) $row['tenant_authorization_revision'],
                $row['primary_department_id'] === null ? null : (int) $row['primary_department_id'],
            ),
        ];
        if ($includeTenantCount) {
            $result['switchable_tenant_count'] = (int) $row['switchable_tenant_count'];
        }
        return $result;
    }
}
