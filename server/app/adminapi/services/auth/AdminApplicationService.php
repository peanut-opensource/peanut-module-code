<?php

declare(strict_types=1);

namespace app\adminapi\services\auth;

use app\common\exception\BusinessException;
use app\common\dto\authorization\AdminPrincipal;
use app\common\execution\CurrentExecutionContext;
use app\common\services\authorization\AdminAuthorizationService;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Modules\File\Contract\FileReferences;
use app\common\services\XlsxExportService;
use PeanutAdmin\Modules\Identity\Authorization\Application\RoleAdminService;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;
use PeanutAdmin\Modules\Identity\Identity\SelfService\AccountSelfService;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use PeanutAdmin\Kernel\Context\TenantContextRequirement;
use app\common\support\ExportPageInfo;
use app\common\support\PaginationInput;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Membership\Application\MemberAdminService;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;

/** 管理员界面编排；账户、成员资料、角色及状态由 Core 聚合命令原子写入。 */
final class AdminApplicationService
{
    private const EXPORT_MAX_ROWS = 25000;
    private const EXPORT_DEFAULT_NAME = '管理员列表';

    public function __construct(
        private readonly XlsxExportService $xlsxExport,
        private readonly AdminDirectoryQuery $directory,
        private readonly MemberAdminService $members,
        private readonly AccountSelfService $selfService,
        private readonly DemoAccountPolicy $demoAccounts,
        private readonly FileReferences $files,
        private readonly PasswordPolicy $passwords,
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationService $authorization,
        private readonly RoleAdminService $roleAdmin,
    ) {}

    public function normalizeInput(array $params, bool $write = false): array
    {
        $params = TenantContextRequirement::withoutTenantId($params);
        $params['account'] ??= $params['username'] ?? null;
        $params['name'] ??= $params['nickname'] ?? null;
        $params['role_id'] ??= $params['role_ids'] ?? null;
        if (!$write) {
            return $params;
        }
        if (array_key_exists('avatar', $params)) {
            throw BusinessException::forbidden('ADMIN_AVATAR_SELF_SERVICE_REQUIRED', '头像只能由账号本人在个人设置修改');
        }
        foreach (['role_id', 'dept_id', 'jobs_id'] as $field) {
            if (isset($params[$field])) {
                if (!is_array($params[$field])) {
                    throw BusinessException::invalid('MEMBER_IDS_INVALID', '角色、部门和岗位必须使用编号数组');
                }
                $params[$field] = self::normalizeIds($params[$field]);
            }
        }
        return $params;
    }

    public function validationRules(string $scene): array
    {
        $rules = [
            'id' => ['require', static fn(mixed $value): bool => (is_int($value) || is_string($value))
                && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false],
            'account' => 'require|email|max:255',
            'name' => 'require|length:1,120',
            'password' => [function ($value, array $data) use ($scene): bool|string {
                if ($scene === 'edit' && (!array_key_exists('password', $data) || $value === '')) {
                    return true;
                }
                if (!is_string($value)) {
                    return '密码必须是字符串';
                }
                try {
                    $this->passwords->assertValid($value);
                    return true;
                } catch (\RuntimeException $exception) {
                    return $exception->getMessage();
                }
            }],
            'password_confirm' => 'requireWith:password|confirm',
            'role_id' => 'array',
            'dept_id' => 'array',
            'jobs_id' => 'array',
            'disable' => ['require', static fn(mixed $value): bool => in_array($value, [0,1,'0','1'], true)],
            'multipoint_login' => 'require|in:0,1',
        ];
        if ($scene === 'add') {
            $rules['password'][] = 'require';
            $rules['role_id'] .= '|require';
            unset($rules['id']);
        }
        return $rules;
    }

    public function lists(TenantContext $context, array $params): array
    {
        $pageSize = max(1, min(
            self::EXPORT_MAX_ROWS,
            (int) ($params['page_size'] ?? $params['limit'] ?? 15),
        ));
        $exportMode = (int) ($params['export'] ?? 0);
        if ($exportMode === 1) {
            return self::exportInfo($this->directory->countRows($params), $pageSize);
        }
        if ($exportMode === 2) {
            $exportRaw = $this->directory->rows($params, self::EXPORT_MAX_ROWS);
            return $this->export($context, $params, $this->hydrateRows($exportRaw));
        }
        $pagination = PaginationInput::from($params);
        $pageNo = $pagination->page;
        $page = $this->directory->paginateRows($params, $pageNo, $pageSize);
        return [
            'lists' => $this->hydrateRows($page['rows']),
            'count' => (int) $page['total'],
            'pageNo' => $pageNo,
            'pageSize' => $pageSize,
        ];
    }

    public function detail(TenantContext $context, int $id): array
    {
        foreach ($this->directory->rows(['id' => $id]) as $rawRow) {
            if ((int) ($rawRow['id'] ?? 0) === $id) {
                return $this->hydrateRows([$rawRow])[0] ?? [];
            }
        }
        return [];
    }

    /** 使用可信租户上下文提交一次 Core 命令；任一步失败由 Core 回滚全部写入。 */
    public function add(TenantContext $context, array $params): bool
    {
        $this->assertMemberPermission($context, 'create');
        $params = self::normalizeInput($params, true);
        try {
            $roles = self::normalizeIds($params['role_id'] ?? []);
            if ($roles === []) {
                throw BusinessException::invalid('ADMIN_ROLE_REQUIRED', '请选择角色');
            }
            $this->assertMemberPermission($context, 'role.assign');
            // Ordinary member creation must never grant the protected owner identity.
            $this->assertOrdinaryOwnerRoleBoundary($context, $roles, false, 0);
            if ((int) $params['disable'] === 0) {
                $this->assertMemberPermission($context, 'activate');
            }
            $department = self::firstId($params['dept_id'] ?? []);
            $service = $this->members;
            $service->createAdministrator(
                $context,
                (string) $params['account'],
                (string) $params['name'],
                (string) $params['password'],
                $department,
                $roles,
                (int) $params['disable'] === 0,
                self::jobIdsInput($params) ?? [],
            );
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /** 先验证表单，再携带成员版本提交 Core 原子编辑；禁止代改账户密码。 */
    public function edit(TenantContext $context, array $params): bool
    {
        $this->assertMemberPermission($context, 'update');
        $params = self::normalizeInput($params, true);
        try {
            if (!empty($params['password'])) {
                throw BusinessException::forbidden('ADMIN_PASSWORD_SELF_SERVICE_REQUIRED', '密码只能由账号本人修改');
            }
            $roles = self::normalizeIds($params['role_id'] ?? []);
            $service = $this->members;
            $member = $service->get($context->tenantId, (int) $params['id']);
            $currentlyOwner = self::isTenantOwnerMember($member);
            $currentRoles = self::normalizeIds($member['role_ids']);
            if ($roles !== $currentRoles) {
                $this->assertMemberPermission($context, 'role.assign');
                $this->assertOrdinaryOwnerRoleBoundary($context, $roles, $currentlyOwner, (int) $member['id']);
            }
            if ($currentlyOwner && (int) $params['disable'] !== ($member['status'] === 'active' ? 0 : 1)) {
                throw BusinessException::forbidden('TENANT_OWNER_STATUS_REQUIRES_PLATFORM_FLOW',
                    '租户所有者状态仅能通过正式平台所有者流程变更');
            }
            $this->assertStatusPermission($context, $member, (int) $params['disable']);
            $service->updateAdministrator(
                $context,
                (int) $member['id'],
                (string) $params['name'],
                self::firstId($params['dept_id'] ?? []),
                $roles,
                (int) $params['disable'] === 0,
                (int) $member['revision'],
                self::jobIdsInput($params),
            );
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /** @param array<string,mixed> $member Current Identity-owned member projection. */
    private static function isTenantOwnerMember(array $member): bool
    {
        $keys = $member['role_keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys)
            || !array_reduce($keys, static fn(bool $valid, mixed $key): bool => $valid && is_string($key), true)) {
            throw BusinessException::conflict('MEMBER_ROLE_IDENTITY_UNAVAILABLE', '成员角色身份当前不可核实');
        }
        return in_array('core.tenant-owner', $keys, true);
    }

    /**
     * Only an active owner may remove another owner's role using normal member editing.
     * Ordinary role assignment cannot grant owner; onboarding remains invitation-based.
     * @param list<int> $requestedRoleIds
     */
    private function assertOrdinaryOwnerRoleBoundary(
        TenantContext $context,
        array $requestedRoleIds,
        bool $currentlyOwner,
        int $targetMemberId,
    ): void
    {
        $requestsOwner = false;
        foreach ($requestedRoleIds as $roleId) {
            $role = $this->roleAdmin->get($context->tenantId, $roleId);
            if (($role['key'] ?? null) === 'core.tenant-owner') {
                $requestsOwner = true;
            }
        }
        if (!$currentlyOwner && $requestsOwner) {
            throw BusinessException::forbidden(
                'TENANT_OWNER_ROLE_REQUIRES_PLATFORM_FLOW',
                '新增租户所有者仍须通过正式邀请流程',
            );
        }
        if ($currentlyOwner && !$requestsOwner) {
            if ($targetMemberId === $context->memberId) {
                throw BusinessException::forbidden(
                    'TENANT_OWNER_SELF_DEMOTION_FORBIDDEN',
                    '不能通过成员编辑取消自己的租户所有者角色',
                );
            }
            $actor = $this->members->get($context->tenantId, $context->memberId);
            if (($actor['status'] ?? null) !== 'active' || !self::isTenantOwnerMember($actor)) {
                throw BusinessException::forbidden(
                    'TENANT_OWNER_ACTOR_REQUIRED',
                    '仅当前租户的有效所有者能够移除其他所有者的角色',
                );
            }
        }
    }

    public function delete(TenantContext $context, int $id, int $selfId = 0): bool
    {
        $this->assertMemberPermission($context, 'leave');
        if ($id === $selfId) {
            throw BusinessException::forbidden('ADMIN_SELF_OPERATION_FORBIDDEN', '不能操作当前登录的管理员');
        }
        try {
            $service = $this->members;
            $member = $service->get($context->tenantId, $id);
            if (self::isTenantOwnerMember($member)) {
                throw BusinessException::forbidden('TENANT_OWNER_STATUS_REQUIRES_PLATFORM_FLOW',
                    '租户所有者状态仅能通过正式平台所有者流程变更');
            }
            $service->leave(
                $context,
                $id,
                (int) $member['revision'],
            );
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function updateStatus(TenantContext $context, int $id, int $disable, int $selfId = 0): bool
    {
        $this->assertMemberPermission($context, 'update');
        if ($id === $selfId) {
            throw BusinessException::forbidden('ADMIN_SELF_OPERATION_FORBIDDEN', '不能操作当前登录的管理员');
        }
        try {
            $service = $this->members;
            $member = $service->get($context->tenantId, $id);
            if (self::isTenantOwnerMember($member)) {
                throw BusinessException::forbidden('TENANT_OWNER_STATUS_REQUIRES_PLATFORM_FLOW',
                    '租户所有者状态仅能通过正式平台所有者流程变更');
            }
            $this->transitionStatus($service, $context, $member, $disable);
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function editSelf(
        TenantContext $context,
        int $memberId,
        array $params,
        string $ip,
        string $userAgent,
    ): bool {
        try {
            if ($memberId !== $context->memberId) {
                throw new \DomainException('TENANT_ADMIN_PRINCIPAL_INVALID');
            }
            $service = $this->selfService;
            $profile = $service->profile($context);
            $service->updateProfile(
                $context,
                (string) ($params['name'] ?? $params['nickname'] ?? $profile['display_name']),
                array_key_exists('avatar', $params)
                    ? $this->files->setTenantFileUrl($context, (string) $params['avatar'])
                    : ($profile['avatar_uri'] ?? null),
            );
            if (!empty($params['password'])) {
                $this->demoAccounts->assertPasswordChangeAllowed($context->accountId);
                $service->changePassword(
                    $context,
                    (string) ($params['password_old'] ?? ''),
                    (string) $params['password'],
                    $ip,
                    $userAgent,
                );
            }
            return true;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /** @param list<array<string,mixed>> $rawRows @return list<array<string,mixed>> */
    private function hydrateRows(array $rawRows): array
    {
        $avatarRefs = [];
        foreach ($rawRows as $row) {
            $ref = trim((string) ($row['avatar_uri'] ?? ''));
            if ($ref !== '') {
                $avatarRefs[] = $ref;
            }
        }
        $avatarUrls = $avatarRefs === [] ? [] : $this->files->getFileUrls($avatarRefs);
        $rows = [];
        foreach ($rawRows as $row) {
            $roleIds = $row['role_ids'] === null || $row['role_ids'] === ''
                ? []
                : array_map('intval', explode(',', (string) $row['role_ids']));
            $avatarUri = trim((string) ($row['avatar_uri'] ?? ''));
            $rows[] = [
                'id' => (int) $row['id'],
                'account' => (string) $row['username'],
                'username' => (string) $row['username'],
                'name' => (string) ($row['display_name'] ?: $row['username']),
                'nickname' => (string) ($row['display_name'] ?: $row['username']),
                'avatar' => $avatarUri === '' ? '' : ($avatarUrls[$avatarUri] ?? ''),
                'avatar_uri' => (string) ($row['avatar_uri'] ?? ''),
                'root' => (int) $row['root'],
                'disable' => $row['status'] === 'active' ? 0 : 1,
                'disable_desc' => match ($row['status']) {
                    'active' => '正常', 'pending' => '待激活', 'left' => '已离开', default => '禁用',
                },
                'multipoint_login' => 1,
                'login_time' => ($row['last_login_at'] ?? null) === null
                    ? ''
                    : (new DateTimeImmutable((string) $row['last_login_at'], new DateTimeZone('UTC')))
                        ->format('Y-m-d\TH:i:s.v\Z'),
                'login_ip' => '',
                'create_time' => (string) $row['created_at'],
                'update_time' => (string) $row['updated_at'],
                'role_id' => $roleIds,
                'role_ids' => $roleIds,
                'dept_id' => $row['primary_department_id'] === null ? [] : [(int) $row['primary_department_id']],
                'jobs_id' => $row['job_ids'],
                'role_name' => (string) ($row['role_name'] ?? ''),
                'dept_name' => (string) ($row['department_name'] ?? ''),
                'jobs_name' => $row['job_name'],
                'roles' => [],
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $member */
    private function transitionStatus(MemberAdminService $service, TenantContext $context, array $member, int $disable): void
    {
        $this->assertStatusPermission($context, $member, $disable);
        if ($disable === 1 && $member['status'] === 'active') {
            $service->suspend($context, (int) $member['id'], (int) $member['revision']);
        } elseif ($disable === 0 && in_array($member['status'], ['pending', 'suspended'], true)) {
            $service->activate($context, (int) $member['id'], (int) $member['revision']);
        }
    }

    /** Sensitive field changes retain their own permissions when an API's primary bundle is rebound. */
    private function assertMemberPermission(TenantContext $context, string $operation): void
    {
        $current = $this->executionContext->tenantAdmin();
        $actor = AdminPrincipal::fromArray($this->executionContext->tenantAdminPrincipal());
        if ($current !== $context
            || !$this->authorization->decide($context, $actor, 'core.member.' . $operation)->allowed) {
            throw BusinessException::forbidden('MEMBER_PERMISSION_DENIED', '无权执行该成员操作');
        }
    }

    private function assertStatusPermission(TenantContext $context, array $member, int $disable): void
    {
        if ($disable === 1 && $member['status'] === 'active') {
            $this->assertMemberPermission($context, 'suspend');
        } elseif ($disable === 0 && in_array($member['status'], ['pending', 'suspended'], true)) {
            $this->assertMemberPermission($context, 'activate');
        }
    }

    private static function exportInfo(int $count, int $pageSize): array
    {
        return ExportPageInfo::from(
            $count,
            $pageSize,
            self::EXPORT_MAX_ROWS,
            self::EXPORT_DEFAULT_NAME,
        )->toArray();
    }

    /** @param list<array<string,mixed>> $rows */
    private function export(TenantContext $context, array $params, array $rows): array
    {
        if ($rows === []) {
            throw BusinessException::conflict('ADMIN_EXPORT_EMPTY', '没有数据，无法导出');
        }
        $rows = array_slice($rows, 0, self::EXPORT_MAX_ROWS);
        $file = $this->xlsxExport->create(
            (string) ($params['file_name'] ?? self::EXPORT_DEFAULT_NAME),
            ['账号', '名称', '角色', '部门', '创建时间', '最近登录时间', '最近登录IP', '状态'],
            array_map(static fn(array $row): array => [$row['account'], $row['name'], $row['role_name'], $row['dept_name'], $row['create_time'], $row['login_time'], $row['login_ip'], $row['disable_desc']], $rows),
        );
        return ['url' => $file['url'], 'file_name' => $file['original_name']];
    }

    /** Missing preserves assignments during edits; an explicit empty list clears them. */
    private static function jobIdsInput(array $params): ?array
    {
        if (!array_key_exists('jobs_id', $params)) {
            return null;
        }
        if (!is_array($params['jobs_id'])) {
            throw BusinessException::invalid('MEMBER_JOB_IDS_INVALID', '岗位必须是编号数组');
        }
        return $params['jobs_id'];
    }

    private static function firstId(array $ids): ?int
    {
        $ids = self::normalizeIds($ids);
        if (count($ids) > 1) {
            throw BusinessException::invalid('MEMBER_DEPARTMENT_INVALID', '只能选择一个主要部门');
        }
        return $ids[0] ?? null;
    }

    /** @return list<int> */
    private static function normalizeIds(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > 100) {
            throw BusinessException::invalid('MEMBER_IDS_INVALID', '编号必须使用不超过100项的列表');
        }
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id))
                || preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw BusinessException::invalid('MEMBER_IDS_INVALID', '编号必须为正整数');
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

}
