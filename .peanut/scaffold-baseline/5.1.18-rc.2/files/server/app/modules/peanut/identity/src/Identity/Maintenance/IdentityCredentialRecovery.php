<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Identity\Maintenance;

use PeanutAdmin\Kernel\Context\PlatformSystemContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Modules\Identity\Persistence\Model\Account;
use PeanutAdmin\Modules\Identity\Persistence\Model\AuthSecurityEvent;
use PeanutAdmin\Modules\Identity\Persistence\Model\Credential;
use PeanutAdmin\Modules\Identity\Persistence\Model\LoginChallenge;
use PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformOperator;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformSession;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformSessionToken;
use PeanutAdmin\Modules\Identity\Persistence\Model\Tenant;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSession;
use PeanutAdmin\Modules\Identity\Persistence\Model\TenantSessionToken;
use SensitiveParameter;
use RuntimeException;
use think\db\Raw;
use think\facade\Db;

/** Local credential maintenance; initial and current shared identities have separate admission contracts. */
final readonly class IdentityCredentialRecovery
{
    public const TENANT_OWNER_ACTOR = 'peanut.identity.credential-recovery.tenant-owner';
    public const TENANT_OWNER_OPERATION = 'identity.credential.recover.tenant-owner';
    public const PLATFORM_OPERATOR_ACTOR = 'peanut.identity.credential-recovery.platform-operator';
    public const PLATFORM_OPERATOR_OPERATION = 'identity.credential.recover.platform-operator';

    public function __construct(
        private PasswordHasher $passwords,
        private PasswordPolicy $passwordPolicy,
        private AuditService $audit,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function inspectTenantOwner(TenantSystemContext $context, array $privateBinding): array
    {
        $identities = $this->assertPrivateBinding($privateBinding, 'tenant-owner');
        $this->assertTenantContext($context, $identities);
        return $this->tenantOwnerSnapshot(false, $identities);
    }

    /** @return array<string, bool|int|string|null> */
    public function inspectPlatformOperator(PlatformSystemContext $context, array $privateBinding): array
    {
        $identities = $this->assertPrivateBinding($privateBinding, 'platform-operator');
        $this->assertPlatformContext($context, $identities);
        return $this->platformOperatorSnapshot(false, $identities);
    }

    /** @return array<string, int|string> */
    public function recoverTenantOwner(
        TenantSystemContext $context,
        #[SensitiveParameter] string $newPassword,
        string $requestId,
        string $runId,
        string $approvalRef,
        string $maintenancePrincipal,
        string $passwordRef,
        array $privateBinding,
    ): array {
        $identities = $this->assertPrivateBinding($privateBinding, 'tenant-owner', $runId, $approvalRef);
        if ($passwordRef !== $privateBinding['password_refs']['tenant-owner']) {
            throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_REF_INVALID');
        }
        $this->assertTenantContext($context, $identities);
        return $this->recover(
            'tenant-owner', $identities['owner_account_id'], $identities['owner_credential_id'],
            $newPassword, $requestId, $runId, $approvalRef, $maintenancePrincipal,
            $passwordRef, fn (): array => $this->tenantOwnerSnapshot(true, $identities),
            function (array $before, array $after, array $revoked) use (
                $context,
                $identities,
                $maintenancePrincipal,
                $approvalRef,
                $runId,
                $requestId,
                $passwordRef,
            ): void {
                $this->audit->appendTenantEvent(
                    $identities['tenant_id'],
                    'identity.credential.recovered',
                    self::TENANT_OWNER_OPERATION,
                    'success',
                    null,
                    $identities['tenant_id'],
                    null,
                    null,
                    null,
                    'tenant_system',
                    'credential',
                    (string) $identities['owner_credential_id'],
                    null,
                    null,
                    1,
                    null,
                    $this->json([
                        'actor_key' => $context->actorKey,
                        'operation' => $context->operation,
                        'maintenance_principal' => $maintenancePrincipal,
                        'approval_ref' => $approvalRef,
                        'run_id' => $runId,
                    ]),
                    $requestId,
                    $runId,
                    null,
                    null,
                    $this->json($before),
                    $this->json($after + ['revoked' => $revoked]),
                    $this->json(['password_ref' => $passwordRef]),
                );
            },
        );
    }

    /** @return array<string, int|string> */
    public function recoverPlatformOperator(
        PlatformSystemContext $context,
        #[SensitiveParameter] string $newPassword,
        string $requestId,
        string $runId,
        string $approvalRef,
        string $maintenancePrincipal,
        string $passwordRef,
        array $privateBinding,
    ): array {
        $identities = $this->assertPrivateBinding($privateBinding, 'platform-operator', $runId, $approvalRef);
        if ($passwordRef !== $privateBinding['password_refs']['platform-operator']) {
            throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_REF_INVALID');
        }
        $this->assertPlatformContext($context, $identities);
        return $this->recover(
            'platform-operator', $identities['platform_account_id'], $identities['platform_credential_id'],
            $newPassword, $requestId, $runId, $approvalRef, $maintenancePrincipal,
            $passwordRef, fn (): array => $this->platformOperatorSnapshot(true, $identities),
            function (array $before, array $after, array $revoked) use (
                $context,
                $identities,
                $maintenancePrincipal,
                $approvalRef,
                $runId,
                $requestId,
                $passwordRef,
            ): void {
                $this->audit->appendPlatformEvent(
                    'identity.credential.recovered',
                    self::PLATFORM_OPERATOR_OPERATION,
                    'success',
                    null,
                    null,
                    null,
                    'credential',
                    (string) $identities['platform_credential_id'],
                    $requestId,
                    $runId,
                    null,
                    null,
                    $this->json($before),
                    $this->json($after + ['revoked' => $revoked]),
                    $this->json([
                        'actor_key' => $context->actorKey,
                        'operation' => $context->operation,
                        'maintenance_principal' => $maintenancePrincipal,
                        'approval_ref' => $approvalRef,
                        'run_id' => $runId,
                        'password_ref' => $passwordRef,
                    ]),
                );
            },
        );
    }

    /** Current local maintenance never infers an initial grant or changes either administrative role. */
    public function inspectCurrentSharedAccount(
        TenantSystemContext $tenant,
        PlatformSystemContext $platform,
        int $accountId,
        int $credentialId,
    ): array {
        $this->assertCurrentSharedContexts($tenant, $platform);
        return $this->currentSharedSnapshot($tenant->tenantId, $accountId, $credentialId, false);
    }

    /** The native CLI owns the protected current plan and must revalidate it inside this transaction. */
    public function recoverCurrentSharedAccount(
        TenantSystemContext $tenant,
        PlatformSystemContext $platform,
        #[SensitiveParameter] string $newPassword,
        array $plan,
        callable $nativeGuard,
    ): array {
        $this->assertCurrentSharedContexts($tenant, $platform);
        $origin = $plan['caller_origin'] ?? [];
        $before = $plan['snapshot'] ?? null;
        if (($plan['protocol'] ?? null) !== 'peanut.current-shared-credential-maintenance.v1'
            || ($plan['mode'] ?? null) !== 'current-existing-shared' || ($plan['status'] ?? null) !== 'approved'
            || ($origin['kind'] ?? null) !== 'local-cli' || ($origin['uid'] ?? null) !== posix_geteuid()
            || ($origin['username'] ?? null) !== (posix_getpwuid(posix_geteuid())['name'] ?? null)
            || !is_array($before) || ($before['eligible'] ?? null) !== true
            || !is_array($before['affected_tenant_ids'] ?? null) || $before['affected_tenant_ids'] === []
            || ($before['tenant_id'] ?? null) !== $tenant->tenantId
            || ($plan['operation_id'] ?? null) !== $tenant->operationId || $tenant->operationId !== $platform->operationId
            || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $tenant->operationId) !== 1
            || !is_string($plan['plan_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $plan['plan_sha256']) !== 1
            || !is_string($plan['approval_ref'] ?? null) || $plan['approval_ref'] === ''
            || !is_string($plan['password_ref'] ?? null) || !str_starts_with($plan['password_ref'], 'server/private/credentials/')) {
            throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_PLAN_INVALID');
        }
        $nativeGuard();
        $operation = $plan['operation_id'];
        $metadata = ['mode' => 'current-existing-shared', 'approval_ref' => $plan['approval_ref'],
            'operation_id' => $operation, 'password_ref' => $plan['password_ref'], 'caller_origin' => $origin,
            'plan_sha256' => $plan['plan_sha256'],
            'affected_surfaces' => ['tenant', 'platform'], 'affected_tenant_ids' => $before['affected_tenant_ids']];
        return $this->recover(
            'current-shared', $before['account_id'], $before['credential_id'], $newPassword,
            $operation, $operation, $plan['approval_ref'], $origin['username'], $plan['password_ref'],
            function () use ($nativeGuard, $before): array {
                $nativeGuard();
                $current = $this->currentSharedSnapshot($before['tenant_id'], $before['account_id'], $before['credential_id'], true);
                self::assertCurrentSharedSnapshot($before, $current, false);
                return $current;
            },
            function (array $old, array $after, array $revoked) use ($tenant, $platform, $metadata, $operation, $nativeGuard): void {
                $current = $this->currentSharedSnapshot($old['tenant_id'], $old['account_id'], $old['credential_id'], true);
                self::assertCurrentSharedSnapshot($old, $current, true);
                $beforeJson = $this->json($old);
                $afterJson = $this->json($after + ['revoked' => $revoked, 'failed_attempts' => 0,
                    'affected_surfaces' => ['tenant', 'platform'], 'affected_tenant_ids' => $old['affected_tenant_ids']]);
                $this->audit->appendTenantEvent($old['tenant_id'], 'identity.credential.recovered', self::TENANT_OWNER_OPERATION,
                    'success', null, $old['tenant_id'], null, null, null, 'tenant_system', 'credential',
                    (string) $old['credential_id'], null, null, 1, null, $this->json($metadata), $operation, $operation,
                    null, null, $beforeJson, $afterJson, $this->json($metadata + ['actor_key' => $tenant->actorKey]));
                $this->audit->appendPlatformEvent('identity.credential.recovered', self::PLATFORM_OPERATOR_OPERATION,
                    'success', null, null, null, 'credential', (string) $old['credential_id'], $operation, $operation,
                    null, null, $beforeJson, $afterJson, $this->json($metadata + ['actor_key' => $platform->actorKey]));
                $nativeGuard();
            },
        );
    }

    private function assertCurrentSharedContexts(TenantSystemContext $tenant, PlatformSystemContext $platform): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')
            || $tenant->actorKey !== self::TENANT_OWNER_ACTOR || $tenant->operation !== self::TENANT_OWNER_OPERATION
            || $platform->actorKey !== self::PLATFORM_OPERATOR_ACTOR || $platform->operation !== self::PLATFORM_OPERATOR_OPERATION
            || $tenant->operationId === '' || $tenant->operationId !== $platform->operationId) {
            throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_CONTEXT_INVALID');
        }
    }

    private function currentSharedSnapshot(int $tenantId, int $accountId, int $credentialId, bool $lock): array
    {
        if (min($tenantId, $accountId, $credentialId) < 1) { throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_TARGET_INVALID'); }
        $tenantFields = ['id', 'status', 'security_revision', 'authorization_revision', 'revision'];
        $tenant = Tenant::where('id', $tenantId)->field($tenantFields)->lock($lock)->find();
        $account = Account::where('id', $accountId)->field(['id', 'status', 'security_revision', 'locked_until', 'last_login_at'])->lock($lock)->find();
        $credential = Credential::where('id', $credentialId)->where('account_id', $accountId)
            ->field(['id', 'account_id', 'kind', 'identifier_type', 'identifier_normalized', 'status', 'revision', 'failed_attempts', 'locked_until', 'expires_at'])->lock($lock)->find();
        $members = TenantMember::where('account_id', $accountId)
            ->field(['id', 'account_id', 'tenant_id', 'status', 'security_revision', 'authorization_revision'])
            ->order('tenant_id,id')->lock($lock)->select()->toArray();
        $operators = PlatformOperator::where('account_id', $accountId)
            ->field(['id', 'account_id', 'status', 'security_revision'])->order('id')->lock($lock)->select()->toArray();
        $memberIds = array_column($members, 'id');
        $tenantIds = array_values(array_unique(array_column($members, 'tenant_id')));
        $tenants = $tenantIds === [] ? [] : Tenant::whereIn('id', $tenantIds)->field($tenantFields)
            ->order('id')->lock($lock)->select()->toArray();
        $memberRoles = $memberIds === [] ? [] : MemberRole::alias('binding')
            ->leftJoin('role role', 'role.id=binding.role_id AND role.tenant_id=binding.tenant_id')
            ->whereIn('binding.tenant_member_id', $memberIds)
            ->field(['binding.id' => 'binding_id', 'binding.tenant_id', 'binding.tenant_member_id', 'binding.role_id',
                'role.key' => 'role_key', 'role.is_builtin' => 'role_is_builtin', 'role.status' => 'role_status',
                'role.authorization_revision' => 'role_authorization_revision', 'role.archived_at' => 'role_archived_at'])
            ->order('binding.tenant_id,binding.tenant_member_id,binding.id')->lock($lock)->select()->toArray();
        $platformRoles = $operators === [] ? [] : Db::name('platform_operator_role')->alias('binding')
            ->leftJoin('platform_role role', 'role.id=binding.platform_role_id')
            ->whereIn('binding.platform_operator_id', array_column($operators, 'id'))
            ->field(['binding.id' => 'binding_id', 'binding.platform_operator_id', 'binding.platform_role_id' => 'role_id',
                'role.key' => 'role_key', 'role.is_builtin' => 'role_is_builtin', 'role.status' => 'role_status',
                'role.revision' => 'role_revision', 'role.archived_at' => 'role_archived_at'])
            ->order('binding.id')->lock($lock)->select()->toArray();
        return self::currentSharedSnapshotFromRows($tenantId, $accountId, $credentialId, [
            'tenant' => $tenant?->toArray(), 'account' => $account?->toArray(), 'credential' => $credential?->toArray(),
            'members' => $members, 'tenants' => $tenants, 'operators' => $operators,
            'member_roles' => $memberRoles, 'platform_roles' => $platformRoles,
        ], $this->accountSessionCounts($accountId));
    }

    /** The complete account affiliation is part of the approved business target, not an inferred extra tenant. */
    private static function currentSharedSnapshotFromRows(int $tenantId, int $accountId, int $credentialId, array $rows, array $sessions): array
    {
        $tenant = $rows['tenant']; $account = $rows['account']; $credential = $rows['credential'];
        $members = self::currentSharedOrderedRows($rows['members'], ['id', 'account_id', 'tenant_id', 'security_revision', 'authorization_revision']);
        $tenants = self::currentSharedOrderedRows($rows['tenants'], ['id', 'security_revision', 'authorization_revision', 'revision']);
        $operators = self::currentSharedOrderedRows($rows['operators'], ['id', 'account_id', 'security_revision']);
        $memberRoles = self::currentSharedOrderedRows($rows['member_roles'], ['binding_id', 'tenant_id', 'tenant_member_id', 'role_id', 'role_is_builtin', 'role_authorization_revision'], 'binding_id');
        $platformRoles = self::currentSharedOrderedRows($rows['platform_roles'], ['binding_id', 'platform_operator_id', 'role_id', 'role_is_builtin', 'role_revision'], 'binding_id');
        $selectedMembers = array_values(array_filter($members, static fn(array $member): bool => $member['tenant_id'] === $tenantId));
        if ($tenant === null || $account === null || $credential === null || count($selectedMembers) !== 1 || count($operators) !== 1
            || (int) $tenant['id'] !== $tenantId || (int) $account['id'] !== $accountId
            || (int) $credential['id'] !== $credentialId || (int) $credential['account_id'] !== $accountId
            || (string) $tenant['status'] !== 'active' || (string) $account['status'] !== 'active'
            || $account['locked_until'] !== null || $selectedMembers[0]['status'] !== 'active'
            || $operators[0]['account_id'] !== $accountId || $operators[0]['status'] !== 'active'
            || (string) $credential['kind'] !== 'email_password' || (string) $credential['identifier_type'] !== 'email'
            || (string) $credential['status'] !== 'active' || $credential['locked_until'] !== null
            || (int) $credential['failed_attempts'] < 0 || $credential['expires_at'] !== null) {
            throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_TARGET_INELIGIBLE');
        }
        $tenantIndex = array_column($tenants, null, 'id');
        $memberIndex = array_column($members, null, 'id');
        foreach ($members as $member) {
            if ($member['account_id'] !== $accountId || !isset($tenantIndex[$member['tenant_id']])) {
                throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_TARGET_INELIGIBLE');
            }
        }
        if (count($tenants) !== count(array_unique(array_column($members, 'tenant_id')))) {
            throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_TARGET_INELIGIBLE');
        }
        foreach ($memberRoles as $role) {
            if (!isset($memberIndex[$role['tenant_member_id']]) || $memberIndex[$role['tenant_member_id']]['tenant_id'] !== $role['tenant_id']
                || $role['role_key'] === null || $role['role_status'] === null) {
                throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_ROLES_CHANGED');
            }
        }
        foreach ($platformRoles as $role) {
            if ($role['platform_operator_id'] !== $operators[0]['id'] || $role['role_key'] === null || $role['role_status'] === null) {
                throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_ROLES_CHANGED');
            }
        }
        $ownerRoles = array_values(array_filter($memberRoles, static fn(array $role): bool => $role['tenant_id'] === $tenantId
            && $role['tenant_member_id'] === $selectedMembers[0]['id'] && $role['role_key'] === 'core.tenant-owner'
            && $role['role_is_builtin'] === 1 && $role['role_status'] === 'active'));
        $bootstrapRoles = array_values(array_filter($platformRoles, static fn(array $role): bool => $role['role_key'] === 'platform.bootstrap-owner'
            && $role['role_status'] === 'active'));
        if (count($ownerRoles) !== 1 || count($bootstrapRoles) !== 1) { throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_ROLES_CHANGED'); }
        $affectedTenantIds = array_values(array_unique(array_column($members, 'tenant_id')));
        sort($affectedTenantIds, SORT_NUMERIC);
        return ['eligible' => true, 'tenant_id' => $tenantId, 'member_id' => $selectedMembers[0]['id'],
            'operator_id' => (int) $operators[0]['id'], 'account_id' => $accountId, 'credential_id' => $credentialId,
            'tenant_role_id' => (int) $ownerRoles[0]['role_id'], 'tenant_role_binding_id' => (int) $ownerRoles[0]['binding_id'],
            'platform_role_id' => (int) $bootstrapRoles[0]['role_id'], 'platform_role_binding_id' => (int) $bootstrapRoles[0]['binding_id'],
            'identifier_sha256' => hash('sha256', (string) $credential['identifier_normalized']),
            'last_login_at' => $account['last_login_at'], 'security_revision' => (int) $account['security_revision'],
            'credential_revision' => (int) $credential['revision'], 'failed_attempts' => (int) $credential['failed_attempts'],
            'affected_tenant_ids' => $affectedTenantIds, 'members' => $members, 'tenants' => $tenants,
            'member_roles' => $memberRoles, 'operator' => $operators[0], 'platform_roles' => $platformRoles, ...$sessions];
    }

    /** Normalize only the already selected business rows, so a DB return order never changes the approved target. */
    private static function currentSharedOrderedRows(array $rows, array $integerFields, string $idField = 'id'): array
    {
        foreach ($rows as &$row) {
            foreach ($integerFields as $field) { $row[$field] = (int) $row[$field]; }
        }
        unset($row);
        usort($rows, static fn(array $left, array $right): int => $left[$idField] <=> $right[$idField]);
        if (count(array_unique(array_column($rows, $idField))) !== count($rows)) {
            throw new RuntimeException('IDENTITY_CURRENT_RECOVERY_TARGET_INELIGIBLE');
        }
        return $rows;
    }

    private static function assertCurrentSharedSnapshot(array $before, array $current, bool $after): void
    {
        $expected = $before;
        if ($after) {
            $expected['credential_revision']++;
            $expected['security_revision']++;
            $expected['failed_attempts'] = 0;
        }
        if ($current !== $expected) {
            throw new RuntimeException($after ? 'IDENTITY_CURRENT_RECOVERY_POSTSTATE_CHANGED' : 'IDENTITY_CURRENT_RECOVERY_TARGET_CHANGED');
        }
    }

    /** @param callable(): array<string, mixed> $snapshot
     *  @param callable(array<string, mixed>, array<string, mixed>, array<string, int>): void $writeAudit
     *  @return array<string, int|string>
     */
    private function recover(
        string $surface,
        int $accountId,
        int $credentialId,
        #[SensitiveParameter] string $newPassword,
        string $requestId,
        string $runId,
        string $approvalRef,
        string $maintenancePrincipal,
        string $passwordRef,
        callable $snapshot,
        callable $writeAudit,
    ): array {
        $this->passwordPolicy->assertValid($newPassword);
        if ($requestId === '' || $runId === '' || $approvalRef === '' || $maintenancePrincipal === '') {
            throw new RuntimeException('IDENTITY_RECOVERY_CONTEXT_INVALID');
        }
        $newHash = $this->passwords->hash($newPassword);

        try {
            return Db::transaction(function () use (
                $surface,
                $accountId,
                $credentialId,
                $newPassword,
                $newHash,
                $requestId,
                $runId,
                $approvalRef,
                $maintenancePrincipal,
                $passwordRef,
                $snapshot,
                $writeAudit,
            ): array {
                $before = $snapshot();
                if (($before['eligible'] ?? false) !== true) {
                    throw new RuntimeException('IDENTITY_RECOVERY_PRECONDITION_FAILED');
                }
                $credential = Credential::where('id', $credentialId)
                    ->where('account_id', $accountId)
                    ->where('status', 'active')
                    ->field(['id', 'secret_hash'])
                    ->lock(true)
                    ->find();
                if ($credential === null) {
                    throw new RuntimeException('IDENTITY_RECOVERY_CREDENTIAL_CHANGED');
                }
                if ($this->passwords->verify($newPassword, (string) $credential['secret_hash'])) {
                    throw new RuntimeException('IDENTITY_RECOVERY_PASSWORD_UNCHANGED');
                }

                $now = new Raw('UTC_TIMESTAMP(3)');
                $credentialUpdated = Credential::where('id', $credentialId)
                    ->where('account_id', $accountId)
                    ->where('status', 'active')
                    ->update([
                        'secret_hash' => $newHash,
                        'failed_attempts' => 0,
                        'locked_until' => null,
                        'secret_changed_at' => $now,
                        'revision' => new Raw('revision + 1'),
                        'updated_at' => $now,
                    ]);
                $accountUpdated = Account::where('id', $accountId)
                    ->where('status', 'active')
                    ->update([
                        'security_revision' => new Raw('security_revision + 1'),
                        'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                    ]);
                if ($credentialUpdated !== 1 || $accountUpdated !== 1) {
                    throw new RuntimeException('IDENTITY_RECOVERY_TARGET_CHANGED');
                }

                $revoked = $this->revokeAccountSessions($accountId);
                $challenges = LoginChallenge::where('account_id', $accountId)
                    ->where('status', 'active')
                    ->update(['status' => 'revoked', 'revoked_at' => new Raw('UTC_TIMESTAMP(3)')]);
                $audiences = $surface === 'current-shared' ? ['tenant', 'platform'] : [$surface === 'tenant-owner' ? 'tenant' : 'platform'];
                foreach ($audiences as $audience) {
                    AuthSecurityEvent::insert([
                        'audience' => $audience,
                        'event_type' => 'password_changed',
                        'outcome' => 'success',
                        'reason_code' => 'maintenance_credential_recovery',
                        'account_id' => $accountId,
                        'credential_id' => $credentialId,
                        'request_id' => $requestId,
                        'metadata_json' => $this->json([
                            'run_id' => $runId,
                            'approval_ref' => $approvalRef,
                            'maintenance_principal' => $maintenancePrincipal,
                            'password_ref' => $passwordRef,
                            ...($surface === 'current-shared' ? ['mode' => 'current-existing-shared'] : []),
                        ]),
                        'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
                    ]);
                }

                $after = [
                    'surface' => $surface,
                    'account_id' => $accountId,
                    'credential_id' => $credentialId,
                    'credential_revision_incremented' => true,
                    'account_security_revision_incremented' => true,
                ];
                $revoked['login_challenges'] = $challenges;
                $writeAudit($before, $after, $revoked);
                return ['surface' => $surface, 'account_id' => $accountId, 'credential_id' => $credentialId];
            });
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($newPassword);
            }
            unset($newHash);
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function tenantOwnerSnapshot(bool $lock, array $identities): array
    {
        $tenantId = $identities['tenant_id'];
        $memberId = $identities['owner_member_id'];
        $accountId = $identities['owner_account_id'];
        $credentialId = $identities['owner_credential_id'];
        $tenantQuery = Tenant::where('id', $tenantId)->field(['id', 'code', 'status']);
        $tenant = $lock ? $tenantQuery->lock(true)->find() : $tenantQuery->find();
        $accountQuery = Account::where('id', $accountId)
            ->field(['id', 'status', 'security_revision', 'locked_until', 'last_login_at']);
        $account = $lock ? $accountQuery->lock(true)->find() : $accountQuery->find();
        $memberQuery = TenantMember::where('tenant_id', $tenantId)
            ->where('id', $memberId)
            ->where('account_id', $accountId)
            ->field(['id', 'tenant_id', 'account_id', 'status']);
        $member = $lock ? $memberQuery->lock(true)->find() : $memberQuery->find();
        $credentialQuery = Credential::where('id', $credentialId)
            ->where('account_id', $accountId)
            ->field(['id', 'status', 'kind', 'identifier_type', 'failed_attempts', 'locked_until', 'expires_at', 'revision']);
        $credential = $lock ? $credentialQuery->lock(true)->find() : $credentialQuery->find();
        $ownerRole = MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.tenant_member_id', $memberId)
            ->where('role.key', 'core.tenant-owner')
            ->where('role.is_builtin', 1)
            ->where('role.status', 'active')
            ->value('member_role.id') !== null;
        $sessions = $this->accountSessionCounts($accountId);
        $tenantActive = $tenant !== null && (string) $tenant['status'] === 'active';
        $accountActive = $account !== null && (string) $account['status'] === 'active'
            && $account['locked_until'] === null && $account['last_login_at'] === null;
        $memberActive = $member !== null && (string) $member['status'] === 'active';
        $credentialActive = $this->credentialEligible($credential);
        $eligible = $tenantActive && $accountActive && $memberActive && $ownerRole && $credentialActive
            && $sessions['tenant_sessions'] === 0 && $sessions['platform_sessions'] === 0
            && $sessions['tenant_tokens'] === 0 && $sessions['platform_tokens'] === 0;

        return [
            'eligible' => $eligible,
            'tenant_id' => $tenantId,
            'tenant_status' => $tenant === null ? null : (string) $tenant['status'],
            'account_id' => $accountId,
            'account_status' => $account === null ? null : (string) $account['status'],
            'account_locked' => $account === null ? null : $account['locked_until'] !== null,
            'account_never_logged_in' => $account === null ? null : $account['last_login_at'] === null,
            'member_id' => $memberId,
            'member_status' => $member === null ? null : (string) $member['status'],
            'owner_role_active' => $ownerRole,
            'credential_id' => $credentialId,
            'credential_status' => $credential === null ? null : (string) $credential['status'],
            'credential_locked' => $credential === null ? null : $credential['locked_until'] !== null,
            'credential_failed_attempts' => $credential === null ? null : (int) $credential['failed_attempts'],
            'credential_revision' => $credential === null ? null : (int) $credential['revision'],
            ...$sessions,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function platformOperatorSnapshot(bool $lock, array $identities): array
    {
        $operatorId = $identities['platform_operator_id'];
        $accountId = $identities['platform_account_id'];
        $credentialId = $identities['platform_credential_id'];
        $operatorQuery = PlatformOperator::where('id', $operatorId)
            ->where('account_id', $accountId)
            ->field(['id', 'account_id', 'status']);
        $operator = $lock ? $operatorQuery->lock(true)->find() : $operatorQuery->find();
        $accountQuery = Account::where('id', $accountId)
            ->field(['id', 'status', 'security_revision', 'locked_until', 'last_login_at']);
        $account = $lock ? $accountQuery->lock(true)->find() : $accountQuery->find();
        $credentialQuery = Credential::where('id', $credentialId)
            ->where('account_id', $accountId)
            ->field(['id', 'status', 'kind', 'identifier_type', 'failed_attempts', 'locked_until', 'expires_at', 'revision']);
        $credential = $lock ? $credentialQuery->lock(true)->find() : $credentialQuery->find();
        $sessions = $this->accountSessionCounts($accountId);
        $accountActive = $account !== null && (string) $account['status'] === 'active'
            && $account['locked_until'] === null && $account['last_login_at'] === null;
        $operatorActive = $operator !== null && (string) $operator['status'] === 'active';
        $credentialActive = $this->credentialEligible($credential);
        $eligible = $accountActive && $operatorActive && $credentialActive
            && $sessions['tenant_sessions'] === 0 && $sessions['platform_sessions'] === 0
            && $sessions['tenant_tokens'] === 0 && $sessions['platform_tokens'] === 0;

        return [
            'eligible' => $eligible,
            'platform_operator_id' => $operatorId,
            'operator_status' => $operator === null ? null : (string) $operator['status'],
            'account_id' => $accountId,
            'account_status' => $account === null ? null : (string) $account['status'],
            'account_locked' => $account === null ? null : $account['locked_until'] !== null,
            'account_never_logged_in' => $account === null ? null : $account['last_login_at'] === null,
            'credential_id' => $credentialId,
            'credential_status' => $credential === null ? null : (string) $credential['status'],
            'credential_locked' => $credential === null ? null : $credential['locked_until'] !== null,
            'credential_failed_attempts' => $credential === null ? null : (int) $credential['failed_attempts'],
            'credential_revision' => $credential === null ? null : (int) $credential['revision'],
            ...$sessions,
        ];
    }

    /** @param array<string, mixed>|null $credential */
    private function credentialEligible(mixed $credential): bool
    {
        if ($credential === null || (string) $credential['status'] !== 'active'
            || (string) $credential['kind'] !== 'email_password'
            || (string) $credential['identifier_type'] !== 'email'
            || (int) $credential['failed_attempts'] !== 0
            || $credential['locked_until'] !== null
            || ($credential['expires_at'] !== null && strtotime((string) $credential['expires_at'] . ' UTC') <= time())) {
            return false;
        }
        return true;
    }

    /** @return array{tenant_sessions:int,platform_sessions:int,tenant_tokens:int,platform_tokens:int} */
    private function accountSessionCounts(int $accountId): array
    {
        $tenantIds = TenantSession::where('account_id', $accountId)->column('id');
        $platformIds = PlatformSession::where('account_id', $accountId)->column('id');
        return [
            'tenant_sessions' => count($tenantIds),
            'platform_sessions' => count($platformIds),
            'tenant_tokens' => $tenantIds === [] ? 0 : TenantSessionToken::whereIn('session_id', $tenantIds)->count(),
            'platform_tokens' => $platformIds === [] ? 0 : PlatformSessionToken::whereIn('session_id', $platformIds)->count(),
        ];
    }

    /** @return array{tenant_sessions:int,platform_sessions:int,tenant_tokens:int,platform_tokens:int} */
    private function revokeAccountSessions(int $accountId): array
    {
        $now = new Raw('UTC_TIMESTAMP(3)');
        $tenantIds = TenantSession::where('account_id', $accountId)->column('id');
        $platformIds = PlatformSession::where('account_id', $accountId)->column('id');
        $counts = [
            'tenant_sessions' => 0,
            'platform_sessions' => 0,
            'tenant_tokens' => 0,
            'platform_tokens' => 0,
        ];
        if ($tenantIds !== []) {
            $counts['tenant_tokens'] = TenantSessionToken::whereIn('session_id', $tenantIds)
                ->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => $now]);
            $counts['tenant_sessions'] = TenantSession::whereIn('id', $tenantIds)
                ->where('status', 'active')->update([
                    'status' => 'revoked', 'revoked_at' => $now,
                    'revoke_reason' => 'credential_changed', 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                ]);
        }
        if ($platformIds !== []) {
            $counts['platform_tokens'] = PlatformSessionToken::whereIn('session_id', $platformIds)
                ->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => $now]);
            $counts['platform_sessions'] = PlatformSession::whereIn('id', $platformIds)
                ->where('status', 'active')->update([
                    'status' => 'revoked', 'revoked_at' => $now,
                    'revoke_reason' => 'credential_changed', 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                ]);
        }
        return $counts;
    }

    private function assertTenantContext(TenantSystemContext $context, array $identities): void
    {
        $this->assertBoundIdentityShape($identities);
        if ($context->tenantId !== $identities['tenant_id']
            || $context->actorKey !== self::TENANT_OWNER_ACTOR
            || $context->operation !== self::TENANT_OWNER_OPERATION
            || $context->operationId === '') {
            throw new RuntimeException('IDENTITY_RECOVERY_CONTEXT_INVALID');
        }
    }

    private function assertPlatformContext(PlatformSystemContext $context, array $identities): void
    {
        $this->assertBoundIdentityShape($identities);
        if ($context->actorKey !== self::PLATFORM_OPERATOR_ACTOR
            || $context->operation !== self::PLATFORM_OPERATOR_OPERATION
            || $context->operationId === '') {
            throw new RuntimeException('IDENTITY_RECOVERY_CONTEXT_INVALID');
        }
    }

    private function assertBoundIdentityShape(array $identities): void
    {
        $expected = ['tenant_id', 'owner_account_id', 'owner_member_id', 'owner_credential_id', 'platform_operator_id', 'platform_account_id', 'platform_credential_id'];
        $actual = array_keys($identities);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected || $identities['owner_account_id'] === $identities['platform_account_id']) {
            throw new RuntimeException('IDENTITY_RECOVERY_BINDING_INVALID');
        }
        foreach (['tenant_id', 'owner_account_id', 'owner_member_id', 'owner_credential_id', 'platform_operator_id', 'platform_account_id', 'platform_credential_id'] as $key) {
            if (!is_int($identities[$key] ?? null) || $identities[$key] < 1) {
                throw new RuntimeException('IDENTITY_RECOVERY_BINDING_INVALID');
            }
        }
    }

    /** @return array<string,int> */
    private function assertPrivateBinding(array $binding, string $target, ?string $runId = null, ?string $approvalRef = null): array
    {
        $keys = ['app_slug', 'resource', 'identities', 'password_refs', 'run_id', 'source_ref', 'target', 'approved_plan', 'caller_origin'];
        $actualKeys = array_keys($binding);
        sort($keys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        $resource = $binding['resource'] ?? null;
        $origin = $binding['caller_origin'] ?? null;
        $plan = $binding['approved_plan'] ?? null;
        $passwordRefs = $binding['password_refs'] ?? null;
        $originKeys = ['kind', 'uid', 'username'];
        $planKeys = ['approval_ref', 'expected_app_slug', 'resource', 'target_ids', 'password_refs', 'run_id', 'allowed_operations', 'status', 'source_ref', 'caller_origin'];
        if ($actualKeys !== $keys || ($binding['target'] ?? null) !== $target
            || !is_string($binding['app_slug'] ?? null) || trim($binding['app_slug']) === ''
            || !is_string($binding['run_id'] ?? null) || trim($binding['run_id']) === ''
            || !is_string($binding['source_ref'] ?? null) || trim($binding['source_ref']) === ''
            || ($runId !== null && !hash_equals($binding['run_id'], $runId))
            || !is_array($resource) || !is_array($origin) || !is_array($plan)
            || !is_array($binding['identities'] ?? null) || !is_array($passwordRefs)
            || !is_string($passwordRefs['tenant-owner'] ?? null) || !str_starts_with($passwordRefs['tenant-owner'], 'server/private/credentials/')
            || !is_string($passwordRefs['platform-operator'] ?? null) || !str_starts_with($passwordRefs['platform-operator'], 'server/private/credentials/')
            || array_diff(array_keys($passwordRefs), ['tenant-owner', 'platform-operator']) !== []
            || !is_string($resource['deployment_target'] ?? null) || $resource['deployment_target'] === ''
            || !is_string($resource['database_resource_id'] ?? null) || $resource['database_resource_id'] === ''
            || !is_string($resource['database_endpoint_id'] ?? null) || $resource['database_endpoint_id'] === ''
            || !is_string($resource['schema'] ?? null) || $resource['schema'] === ''
            || !is_string($plan['approval_ref'] ?? null) || trim($plan['approval_ref']) === ''
            || ($approvalRef !== null && !hash_equals($plan['approval_ref'], $approvalRef))
            || ($plan['status'] ?? null) !== 'approved'
            || !is_string($plan['source_ref'] ?? null) || trim($plan['source_ref']) === ''
            || ($plan['expected_app_slug'] ?? null) !== $binding['app_slug']
            || ($plan['resource'] ?? null) !== $resource
            || ($plan['target_ids'] ?? null) !== $binding['identities']
            || ($plan['password_refs'] ?? null) !== $passwordRefs
            || ($plan['run_id'] ?? null) !== $binding['run_id']
            || ($plan['source_ref'] ?? null) !== $binding['source_ref']
            || !is_array($plan['allowed_operations'] ?? null)
            || array_values($plan['allowed_operations']) !== ['tenant-owner', 'platform-operator']
            || !in_array($target, $plan['allowed_operations'], true)
            || ($plan['caller_origin'] ?? null) !== $origin) {
            throw new RuntimeException('IDENTITY_RECOVERY_BINDING_INVALID');
        }
        $actualOriginKeys = array_keys($origin);
        sort($originKeys, SORT_STRING);
        sort($actualOriginKeys, SORT_STRING);
        if ($actualOriginKeys !== $originKeys || ($origin['kind'] ?? null) !== 'local-cli'
            || !is_int($origin['uid'] ?? null) || !is_string($origin['username'] ?? null)
            || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')
            || $origin['uid'] !== posix_geteuid()
            || $origin['username'] !== (posix_getpwuid(posix_geteuid())['name'] ?? null)
            || PHP_SAPI !== 'cli') {
            throw new RuntimeException('IDENTITY_RECOVERY_CALLER_ORIGIN_INVALID');
        }
        $resourceKeys = ['deployment_target', 'database_resource_id', 'database_endpoint_id', 'schema'];
        $actualResourceKeys = array_keys($resource);
        sort($resourceKeys, SORT_STRING);
        sort($actualResourceKeys, SORT_STRING);
        if ($actualResourceKeys !== $resourceKeys) {
            throw new RuntimeException('IDENTITY_RECOVERY_RESOURCE_BINDING_INVALID');
        }
        $actualPlanKeys = array_keys($plan);
        sort($planKeys, SORT_STRING);
        sort($actualPlanKeys, SORT_STRING);
        if ($actualPlanKeys !== $planKeys) {
            throw new RuntimeException('IDENTITY_RECOVERY_APPROVED_PLAN_INVALID');
        }
        $identities = $binding['identities'];
        $this->assertBoundIdentityShape($identities);
        return $identities;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
