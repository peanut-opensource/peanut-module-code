<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Platform\PlatformOperatorStatus;
use PeanutAdmin\Modules\Identity\Platform\Authorization\PlatformRoleDirectGrantStore;
use PeanutAdmin\Modules\Identity\Platform\Authorization\ThinkPhpPlatformAuthorizationRepository;
use PeanutAdmin\Modules\Identity\Contract\PlatformRoleAuthorizationPreview;
use PeanutAdmin\Kernel\Module\ModuleAvailability;
use PeanutAdmin\Modules\Identity\Persistence\Model\Account;
use PeanutAdmin\Modules\Identity\Persistence\Model\Credential;
use PeanutAdmin\Modules\Identity\Persistence\Model\Permission;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformOperator;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformOperatorRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformRole;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformRolePermission;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformSession;
use PeanutAdmin\Modules\Identity\Persistence\Model\PlatformSessionToken;
use Throwable;
use think\db\Raw;
use think\facade\Db;

final readonly class PlatformAccessAdminService
{
    private const ROLE_KEY_PATTERN = '/^platform\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D';

    /** @var list<string> */
    private const CONTROL_PERMISSION_KEYS = [
        'platform.operator.create', 'platform.operator.update', 'platform.operator.lifecycle',
        'platform.operator.role.assign', 'platform.role.create', 'platform.role.update',
        'platform.role.archive', 'platform.role.permission.assign',
    ];

    public function __construct(
        private AuditService $audit,
        private PasswordHasher $passwords,
        private PasswordPolicy $passwordPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function createOperator(
        PlatformContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        try {
            $email = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $displayName = $this->text($displayName, 120, 'OPERATOR_DISPLAY_NAME_INVALID');

        return $this->transaction(function () use ($actor, $email, $displayName, $initialPassword): array {
            $this->requireActor($actor);
            $credential = Credential::alias('credential')
                ->join('account account', 'account.id = credential.account_id')
                ->where('credential.identifier_type', 'email')->where('credential.identifier_normalized', $email)
                ->field([
                    'credential.account_id', 'credential.status' => 'credential_status',
                    'account.status' => 'account_status',
                ])->lock(true)->find()?->toArray();
            if ($credential === null) {
                if ($initialPassword === null || $initialPassword === '') {
                    throw AdminAccessException::invalid('INITIAL_PASSWORD_REQUIRED', 'An initial password is required for a new account.');
                }
                $accountId = $this->createAccountAndCredential($email, $displayName, $initialPassword);
            } else {
                if ($initialPassword !== null) {
                    throw AdminAccessException::invalid(
                        'INITIAL_PASSWORD_NOT_ALLOWED',
                        'An existing account credential cannot be overwritten.',
                    );
                }
                if ($credential['credential_status'] !== 'active' || $credential['account_status'] !== 'active') {
                    throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The existing account and credential must be active.');
                }
                $accountId = (int) $credential['account_id'];
            }
            if (PlatformOperator::where('account_id', $accountId)->lock(true)->value('id') !== null) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_EXISTS', 'The account is already a platform operator.');
            }
            $now = $this->now();
            $operatorId = (int) PlatformOperator::insertGetId([
                'account_id' => $accountId, 'display_name' => $displayName, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $operator = $this->operator($operatorId);
            $this->recordAudit(
                $actor,
                'platform-operator.created',
                'platform.operator.create',
                'platform-operator',
                $operatorId,
                null,
                $this->operatorAuditSnapshot($operator),
                [],
            );

            return $operator;
        }, 'PLATFORM_OPERATOR_CONFLICT', 'The operator conflicts with an existing account or credential.');
    }

    /** @return array<string, mixed> */
    public function updateOperator(
        PlatformContext $actor,
        int $operatorId,
        int $expectedRevision,
        string $displayName,
        string $changeReason,
    ): array {
        $displayName = $this->text($displayName, 120, 'OPERATOR_DISPLAY_NAME_INVALID');
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $operatorId, $expectedRevision, $displayName, $changeReason): array {
            $this->requireActor($actor);
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            if ($before['status'] === PlatformOperatorStatus::Closed->value) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_CLOSED', 'A closed operator cannot be updated.');
            }
            if (PlatformOperator::where('id', $operatorId)->where('security_revision', $expectedRevision)->update([
                'display_name' => $displayName,
                'security_revision' => new Raw('security_revision + 1'),
                'updated_at' => $this->now(),
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->operator($operatorId);
            $this->recordAudit(
                $actor,
                'platform-operator.updated',
                'platform.operator.update',
                'platform-operator',
                $operatorId,
                $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after),
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function replaceOperatorRoles(
        PlatformContext $actor,
        int $operatorId,
        array $roleIds,
        int $expectedRevision,
        string $changeReason,
    ): array {
        $roleIds = array_values(array_unique($roleIds));
        if (count($roleIds) > 100 || array_filter($roleIds, static fn(int $id): bool => $id < 1) !== []) {
            throw AdminAccessException::invalid('ROLE_IDS_INVALID', 'Role IDs must contain at most 100 positive values.');
        }
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $operatorId, $roleIds, $expectedRevision, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            if ($before['status'] === PlatformOperatorStatus::Closed->value) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_CLOSED', 'A closed operator cannot receive roles.');
            }
            $roles = $this->activeRoles($roleIds);
            if (count($roles) !== count($roleIds)) {
                throw AdminAccessException::notFound();
            }
            PlatformOperatorRole::where('platform_operator_id', $operatorId)->delete();
            $now = $this->now();
            if ($roles !== []) {
                PlatformOperatorRole::insertAll(array_map(
                    static fn(array $role): array => [
                        'platform_operator_id' => $operatorId,
                        'platform_role_id' => (int) $role['id'],
                        'assigned_by_operator_id' => $actor->operatorId,
                        'assigned_at' => $now,
                    ],
                    $roles,
                ));
            }
            if (PlatformOperator::where('id', $operatorId)->where('security_revision', $expectedRevision)->update([
                'security_revision' => new Raw('security_revision + 1'), 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->assertControlAdminExists();
            $after = $this->operator($operatorId);
            $this->recordAudit(
                $actor,
                'platform-operator.roles-replaced',
                'platform.operator.role.assign',
                'platform-operator',
                $operatorId,
                $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after),
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function transitionOperator(
        PlatformContext $actor,
        int $operatorId,
        int $expectedRevision,
        PlatformOperatorStatus $next,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $operatorId, $expectedRevision, $next, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            $current = PlatformOperatorStatus::from((string) $before['status']);
            try {
                $current->transitionTo($next);
            } catch (DomainException) {
                throw AdminAccessException::conflict(
                    'PLATFORM_OPERATOR_STATUS_INVALID',
                    "Operator cannot transition from {$current->value} to {$next->value}.",
                );
            }
            $now = $this->now();
            $changes = [
                'status' => $next->value, 'security_revision' => new Raw('security_revision + 1'), 'updated_at' => $now,
            ];
            if ($next === PlatformOperatorStatus::Suspended) {
                $changes['suspended_at'] = $now;
            } elseif ($next === PlatformOperatorStatus::Closed) {
                $changes['closed_at'] = $now;
            }
            if (PlatformOperator::where('id', $operatorId)->where('security_revision', $expectedRevision)
                ->update($changes) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            if ($next !== PlatformOperatorStatus::Active) {
                $this->revokeSessions($operatorId, $next->value, $now);
            }
            $this->assertControlAdminExists();
            $after = $this->operator($operatorId);
            $eventType = 'platform-operator.' . match ($next) {
                PlatformOperatorStatus::Active => 'activated', PlatformOperatorStatus::Suspended => 'suspended',
                PlatformOperatorStatus::Closed => 'closed',
            };
            $this->recordAudit(
                $actor,
                $eventType,
                'platform.operator.lifecycle',
                'platform-operator',
                $operatorId,
                $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after),
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function createRole(
        PlatformContext $actor,
        string $key,
        string $name,
        ?string $description,
    ): array {
        $key = trim($key);
        if (preg_match(self::ROLE_KEY_PATTERN, $key) !== 1 || strlen($key) > 96) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_KEY_INVALID', 'The platform role key is invalid.');
        }
        $name = $this->text($name, 120, 'PLATFORM_ROLE_NAME_INVALID');
        $description = $this->nullableText($description, 500, 'PLATFORM_ROLE_DESCRIPTION_INVALID');

        return $this->transaction(function () use ($actor, $key, $name, $description): array {
            $this->requireActor($actor);
            $now = $this->now();
            $roleId = (int) PlatformRole::insertGetId([
                'key' => $key, 'name' => $name, 'description' => $description, 'is_builtin' => 0,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            // A newly created role in direct mode has one explicitly empty
            // authorization record, not an implicit fallback to legacy grants.
            if ((new PlatformRoleDirectGrantStore())->source(true) === 'direct') {
                $origin = Db::name('platform_role_direct_state')->order('platform_role_id')->find();
                if ($origin === null) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_CATALOG_INCOMPLETE', 'Direct source has no registered role catalog.');
                }
                (new PlatformRoleDirectGrantStore())->save(
                    $roleId, 1, [], [], [], (string) $origin['catalog_sha256'], $now,
                );
            }
            $role = $this->role($roleId);
            $this->recordAudit(
                $actor,
                'platform-role.created',
                'platform.role.create',
                'platform-role',
                $roleId,
                null,
                $role,
                [],
            );

            return $role;
        }, 'PLATFORM_ROLE_KEY_CONFLICT', 'The platform role key is already in use.');
    }

    /** @return array<string, mixed> */
    public function updateRole(
        PlatformContext $actor,
        int $roleId,
        int $expectedRevision,
        string $name,
        ?string $description,
        string $changeReason,
    ): array {
        $name = $this->text($name, 120, 'PLATFORM_ROLE_NAME_INVALID');
        $description = $this->nullableText($description, 500, 'PLATFORM_ROLE_DESCRIPTION_INVALID');
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $roleId, $expectedRevision, $name, $description, $changeReason): array {
            $this->requireActor($actor);
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] === 'archived') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_ARCHIVED', 'An archived role cannot be updated.');
            }
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'name' => $name, 'description' => $description,
                'revision' => new Raw('revision + 1'), 'updated_at' => $this->now(),
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpDirectRoleRevisionIfActive($roleId, $expectedRevision);
            $after = $this->role($roleId);
            $this->recordAudit(
                $actor,
                'platform-role.updated',
                'platform.role.update',
                'platform-role',
                $roleId,
                $before,
                $after,
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function archiveRole(
        PlatformContext $actor,
        int $roleId,
        int $expectedRevision,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $roleId, $expectedRevision, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ((bool) $before['is_builtin']) {
                throw AdminAccessException::conflict('BUILTIN_ROLE_IMMUTABLE', 'Built-in platform roles cannot be archived.');
            }
            if ($before['status'] === 'archived') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_ARCHIVED', 'The platform role is already archived.');
            }
            $now = $this->now();
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'status' => 'archived', 'revision' => new Raw('revision + 1'),
                'archived_at' => $now, 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpDirectRoleRevisionIfActive($roleId, $expectedRevision);
            $this->bumpRoleOperators($roleId, $now);
            $this->assertControlAdminExists();
            $after = $this->role($roleId);
            $this->recordAudit(
                $actor,
                'platform-role.archived',
                'platform.role.archive',
                'platform-role',
                $roleId,
                $before,
                $after,
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @param list<string> $permissionKeys
     * @return array<string, mixed>
     */
    public function replaceRolePermissions(
        PlatformContext $actor,
        int $roleId,
        array $permissionKeys,
        int $expectedRevision,
        string $changeReason,
    ): array {
        $permissionKeys = array_values(array_unique($permissionKeys));
        if (count($permissionKeys) > 200) {
            throw AdminAccessException::invalid('PERMISSION_KEYS_INVALID', 'At most 200 permissions may be assigned.');
        }
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $roleId, $permissionKeys, $expectedRevision, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            if ((new PlatformRoleDirectGrantStore())->source(true) !== 'legacy') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_SOURCE_DIRECT', 'Legacy Platform role grant writes are disabled after direct cutover.');
            }
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] !== 'active') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_INACTIVE', 'Only an active role can receive permissions.');
            }
            $permissions = $this->platformPermissions($permissionKeys);
            if (count($permissions) !== count($permissionKeys)) {
                throw AdminAccessException::invalid(
                    'PERMISSION_NOT_ASSIGNABLE',
                    'Only active platform control-plane permissions may be assigned.',
                );
            }
            PlatformRolePermission::where('platform_role_id', $roleId)->delete();
            $now = $this->now();
            if ($permissions !== []) {
                PlatformRolePermission::insertAll(array_map(
                    static fn(array $permission): array => [
                        'platform_role_id' => $roleId,
                        'permission_id' => (int) $permission['id'],
                        'granted_at' => $now,
                    ],
                    $permissions,
                ));
            }
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'revision' => new Raw('revision + 1'), 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpRoleOperators($roleId, $now);
            $this->assertControlAdminExists();
            $after = $this->role($roleId);
            $this->recordAudit(
                $actor,
                'platform-role.permissions-replaced',
                'platform.role.permission.assign',
                'platform-role',
                $roleId,
                $before,
                $after,
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /**
     * Formal direct-node role writer, without an enabled HTTP mutation route.
     * The existing Code PlatformRoleNodeSource::authoritativeCandidate is passed
     * by the trusted host and executed inside this native transaction.
     *
     * @param list<string> $nodeKeys
     * @param callable(array<string,mixed>):array<string,mixed> $trustedSource
     * @return array<string,mixed>
     */
    public function replaceRoleNodes(
        PlatformContext $actor,
        int $roleId,
        array $nodeKeys,
        int $expectedRevision,
        string $expectedCatalogSha256,
        string $changeReason,
        callable $trustedSource,
    ): array {
        $changeReason = $this->changeReason($changeReason);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedCatalogSha256) !== 1) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_SOURCE_INVALID', 'Expected catalog digest is invalid.');
        }
        return Db::transaction(function () use (
            $actor, $roleId, $nodeKeys, $expectedRevision, $expectedCatalogSha256, $changeReason, $trustedSource,
        ): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $direct = new PlatformRoleDirectGrantStore();
            $direct->assertReady();
            $mode = $direct->source(true);
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] !== 'active') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_INACTIVE', 'Only active Platform roles can receive direct nodes.');
            }
            $candidate = ['role_ids' => [$roleId], 'role_assignments' => [[
                'role_id' => $roleId, 'expected_revision' => $expectedRevision, 'node_keys' => $nodeKeys,
            ]]];
            $resolved = $trustedSource($candidate);
            $nodes = $direct->selectedNodes($resolved, $roleId, $expectedRevision, $nodeKeys);
            $digest = $direct->catalogDigest($resolved['nodes'], true);
            if (!hash_equals($expectedCatalogSha256, $digest)) {
                throw AdminAccessException::conflict('PLATFORM_ROLE_API_CATALOG_DRIFT', 'Registered Platform node or API directory changed.');
            }
            $apiIds = array_values(array_unique(array_merge([], ...array_values($nodes ?: [[]]))));
            if ($apiIds !== []) {
                $actual = Db::name('system_api')->whereIn('id', $apiIds)
                    ->where('audience', 'platform')->where('status', 'active')
                    ->where('access_mode', 'platform_permission')->order('id')->lock(true)->column('id');
                if (count($actual) !== count($apiIds)) {
                    throw AdminAccessException::conflict('PLATFORM_ROLE_API_CATALOG_DRIFT', 'Selected API is not registered for Platform access.');
                }
            }
            $now = $this->now();
            $approved = $direct->approvedHttpKeys($apiIds);
            $previousNodes = $mode === 'direct'
                ? Db::name('platform_role_node')->where('platform_role_id', $roleId)->order('node_key')->column('node_key')
                : null;
            $previousDirectKeys = $mode === 'direct'
                ? $direct->rolePermissionKeys($roleId, $expectedRevision) : null;
            if ($mode === 'legacy') {
                $bound = $before['permission_keys'] === [] ? [] : Db::name('permission_api')->alias('binding')
                    ->join('permission permission', 'permission.id = binding.permission_id')
                    ->whereIn('permission.key', $before['permission_keys'])
                    ->distinct(true)->column('permission.key');
                $auxiliary = array_values(array_diff($before['permission_keys'], $bound));
                // Staging must not create permission keys that were absent from
                // the role's current legacy authorization facts.
                $approved = array_values(array_intersect($approved, $before['permission_keys']));
            } else {
                $stored = Db::name('platform_role_direct_state')
                    ->where('platform_role_id', $roleId)->value('auxiliary_permission_keys_json');
                if (!is_string($stored)) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_ROLE_NOT_MIGRATED', 'Direct role state is missing.');
                }
                $auxiliary = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
            }
            $direct->save($roleId, $expectedRevision + 1, $nodes, $approved, $auxiliary, $digest, $now);
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'revision' => new Raw('revision + 1'), 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpRoleOperators($roleId, $now);
            if ($mode === 'direct') {
                $this->assertDirectControlAdminExists($direct);
            } else {
                $this->assertControlAdminExists();
            }
            $after = $this->role($roleId);
            $nextDirectKeys = $direct->rolePermissionKeys($roleId, $expectedRevision + 1);
            $this->recordAudit(
                $actor, 'platform-role.nodes-replaced', 'platform.role.permission.assign',
                'platform-role', $roleId,
                [...$before, 'node_keys' => $previousNodes, 'direct_permission_keys' => $previousDirectKeys],
                [...$after, 'node_keys' => $nodeKeys, 'direct_permission_keys' => $nextDirectKeys],
                ['change_reason' => $changeReason, 'catalog_sha256' => $digest],
            );
            return [...$after, 'node_keys' => $nodeKeys, 'catalog_sha256' => $digest];
        });
    }

    /**
     * One atomic source cutover, never a per-role legacy/direct mixed read.
     * A complete role catalog and effective API capability equivalence are
     * required. Existing legacy grants remain immutable until explicit rollback.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $trustedSource
     */
    public function activateDirectRoleSource(
        PlatformContext $actor,
        PlatformRoleAuthorizationPreview $preview,
        ModuleAvailability $modules,
        string $expectedCatalogSha256,
        string $changeReason,
        callable $trustedSource,
    ): void {
        $changeReason = $this->changeReason($changeReason);
        Db::transaction(function () use ($actor, $preview, $modules, $expectedCatalogSha256, $changeReason, $trustedSource): void {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $direct = new PlatformRoleDirectGrantStore();
            $direct->assertReady();
            if ($direct->source(true) !== 'legacy') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_SOURCE_CONFLICT', 'Direct source is already active.');
            }
            $roles = Db::name('platform_role')->order('id')->lock(true)->field('id,revision,status')->select()->toArray();
            $roleIds = array_map(static fn(array $row): int => (int) $row['id'], $roles);
            $states = Db::name('platform_role_direct_state')->order('platform_role_id')->lock(true)->select()->toArray();
            if ($roleIds === [] || count($states) > count($roleIds)) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_CATALOG_INCOMPLETE', 'All Platform roles must have explicit direct grants.');
            }
            $stateByRole = array_column($states, null, 'platform_role_id');
            foreach ($roles as $role) {
                if (!in_array($role['status'], ['active', 'archived'], true)) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_CATALOG_INCOMPLETE', 'Unknown Platform role state.');
                }
                $roleId = (int) $role['id'];
                if ($role['status'] !== 'archived' || isset($stateByRole[$roleId])) {
                    continue;
                }
                // Archived roles cannot use the ordinary active-role writer,
                // but they have no effective authorization. Stage one explicit
                // empty snapshot inside this same all-or-nothing cutover.
                $direct->save($roleId, (int) $role['revision'], [], [], [], $expectedCatalogSha256, $this->now());
                $stateByRole[$roleId] = Db::name('platform_role_direct_state')
                    ->where('platform_role_id', $roleId)->find();
            }
            if (count($stateByRole) !== count($roleIds)) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_CATALOG_INCOMPLETE', 'Every active Platform role must be staged.');
            }
            $keysByRole = [];
            foreach (Db::name('platform_role_node')->whereIn('platform_role_id', $roleIds)
                ->field('platform_role_id,node_key')->order('platform_role_id,node_key')->select()->toArray() as $row) {
                $keysByRole[(int) $row['platform_role_id']][] = (string) $row['node_key'];
            }
            $assignments = [];
            foreach ($roles as $role) {
                $roleId = (int) $role['id'];
                $state = $stateByRole[$roleId] ?? null;
                if ($state === null || (int) $state['role_revision'] !== (int) $role['revision'] ||
                    !hash_equals($expectedCatalogSha256, (string) $state['catalog_sha256'])) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_CATALOG_STALE', 'Role revision or node source has changed.');
                }
                if ($role['status'] === 'archived' &&
                    ((int) $state['explicit_empty'] !== 1 || ($keysByRole[$roleId] ?? []) !== [] ||
                        json_decode((string) $state['approved_permission_keys_json'], true, 32, JSON_THROW_ON_ERROR) !== [] ||
                        json_decode((string) $state['auxiliary_permission_keys_json'], true, 32, JSON_THROW_ON_ERROR) !== [])) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_ARCHIVED_ROLE_NOT_EMPTY', 'An archived role cannot hold direct grants.');
                }
                $assignments[] = ['role_id' => $roleId, 'expected_revision' => (int) $role['revision'],
                    'node_keys' => $keysByRole[$roleId] ?? []];
            }
            $resolved = $trustedSource(['role_ids' => $roleIds, 'role_assignments' => $assignments]);
            if (!is_array($resolved) || ($resolved['role_ids'] ?? null) !== $roleIds ||
                ($resolved['role_assignments'] ?? null) !== $assignments ||
                !is_array($resolved['nodes'] ?? null)) {
                throw AdminAccessException::conflict('PLATFORM_ROLE_NODE_SOURCE_INVALID', 'Trusted source failed to resolve the complete role selection.');
            }
            $digest = $direct->catalogDigest($resolved['nodes'], true);
            if (!hash_equals($expectedCatalogSha256, $digest)) {
                throw AdminAccessException::conflict('PLATFORM_ROLE_API_CATALOG_DRIFT', 'Platform node or API directory changed at cutover.');
            }
            $activeIds = array_values(array_map(
                static fn(array $row): int => (int) $row['id'],
                array_filter($roles, static fn(array $row): bool => $row['status'] === 'active'),
            ));
            if ($activeIds === []) {
                throw AdminAccessException::conflict('PLATFORM_CONTROL_ADMIN_REQUIRED', 'At least one active Platform role must remain.');
            }
            $activeScope = array_fill_keys($activeIds, true);
            $activeCandidate = [
                'role_ids' => $activeIds, 'nodes' => $resolved['nodes'],
                'role_assignments' => array_values(array_filter(
                    $assignments,
                    static fn(array $row): bool => isset($activeScope[$row['role_id']]),
                )),
            ];
            // Native catalog auditing intentionally treats archived roles as
            // non-comparable. Prove all active roles with its original evaluator;
            // the full role set (including inert archived snapshots) has
            // already been checked against the locked directory above.
            $report = $preview->auditCatalog($actor, $activeCandidate, $modules);
            if (($report['equivalent'] ?? false) !== true || count($report['roles'] ?? []) !== count($activeIds)) {
                throw AdminAccessException::conflict('PLATFORM_DIRECT_MIGRATION_NOT_EQUIVALENT', 'Complete and equivalent Platform authorization is required.');
            }
            // The node equivalence report compares selected API relationships,
            // but cannot prove the actual role permission projection. Validate
            // the SAME direct repository output that middleware will consume,
            // including independent prerequisites and non-HTTP privileges.
            $legacyKeys = [];
            foreach (Db::name('platform_role_permission')->alias('binding')
                ->join('permission permission', 'permission.id = binding.permission_id')
                ->whereIn('binding.platform_role_id', $roleIds)
                ->where('permission.status', 'active')->whereLike('permission.key', 'platform.%')
                ->field('binding.platform_role_id,permission.key')->select()->toArray() as $grant) {
                $legacyKeys[(int) $grant['platform_role_id']][$grant['key']] = true;
            }
            $projected = $direct->rolePermissionKeysBatch(array_column($roles, 'revision', 'id'));
            foreach ($activeIds as $roleId) {
                $current = array_keys($legacyKeys[$roleId] ?? []);
                $future = $projected[$roleId] ?? [];
                sort($current, SORT_STRING);
                sort($future, SORT_STRING);
                if ($current !== $future) {
                    throw AdminAccessException::conflict('PLATFORM_DIRECT_MIGRATION_NOT_EQUIVALENT', 'Direct role permissions differ from the active legacy grants.');
                }
            }
            Db::name('platform_role_authorization_source')->where('id', 1)->update(['mode' => 'direct', 'changed_at' => $this->now()]);
            $this->assertDirectControlAdminExists($direct);
            PlatformOperator::where('status', 'active')->update(['security_revision' => new Raw('security_revision + 1')]);
            $this->recordAudit($actor, 'platform-role.source-activated', 'platform.role.permission.assign',
                'platform-authorization-source', 1, ['mode' => 'legacy'], ['mode' => 'direct', 'catalog_sha256' => $digest],
                ['change_reason' => $changeReason]);
        });
    }

    /** Emergency single switch rollback; the unchanged legacy relations become active again. */
    public function rollbackDirectRoleSource(PlatformContext $actor, string $changeReason): void
    {
        $changeReason = $this->changeReason($changeReason);
        Db::transaction(function () use ($actor, $changeReason): void {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $direct = new PlatformRoleDirectGrantStore();
            $direct->assertReady();
            if ($direct->source(true) !== 'direct') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_SOURCE_CONFLICT', 'Direct source is not active.');
            }
            // An emergency return to the preserved legacy relation must never
            // silently re-grant rights that were revoked while direct was
            // active. Serialize the relevant source rows and compare every
            // active role through the SAME evaluator used by live sessions.
            $rows = Db::name('platform_role')->where('status', 'active')
                ->field('id,revision')->order('id')->lock(true)->select()->toArray();
            $revisions = array_column($rows, 'revision', 'id');
            if ($revisions === []) {
                throw AdminAccessException::conflict('PLATFORM_CONTROL_ADMIN_REQUIRED', 'At least one active Platform role must remain.');
            }
            Db::name('system_api')->where('audience', 'platform')->order('id')->lock(true)->column('id');
            Db::name('permission_api')->order('permission_id,api_id')->lock(true)->column('id');
            Db::name('permission')->whereLike('key', 'platform.%')->order('id')->lock(true)->column('id');
            $current = $direct->rolePermissionKeysBatch($revisions);
            $old = [];
            foreach (Db::name('platform_role_permission')->alias('binding')
                ->join('permission permission', 'permission.id = binding.permission_id')
                ->whereIn('binding.platform_role_id', array_keys($revisions))
                ->where('permission.status', 'active')->whereLike('permission.key', 'platform.%')
                ->field('binding.platform_role_id,permission.key')->select()->toArray() as $grant) {
                $old[(int) $grant['platform_role_id']][$grant['key']] = true;
            }
            foreach ($revisions as $roleId => $_revision) {
                if (array_diff(array_keys($old[$roleId] ?? []), $current[$roleId]) !== []) {
                    throw AdminAccessException::conflict(
                        'PLATFORM_ROLLBACK_PRIVILEGE_EXPANSION',
                        'Legacy grants would restore a permission revoked from the direct source.',
                    );
                }
            }
            Db::name('platform_role_authorization_source')->where('id', 1)->update(['mode' => 'legacy', 'changed_at' => $this->now()]);
            $this->assertControlAdminExists();
            PlatformOperator::where('status', 'active')->update(['security_revision' => new Raw('security_revision + 1')]);
            $this->recordAudit($actor, 'platform-role.source-rolled-back', 'platform.role.permission.assign',
                'platform-authorization-source', 1, ['mode' => 'direct'], ['mode' => 'legacy'],
                ['change_reason' => $changeReason]);
        });
    }

    /**
     * The same last-admin condition as the original writer, now evaluated from
     * direct grants. No permission bundle is written as a parallel source.
     */
    private function assertDirectControlAdminExists(PlatformRoleDirectGrantStore $direct): void
    {
        $rows = PlatformOperator::alias('operator')
            ->join('platform_operator_role membership', 'membership.platform_operator_id = operator.id')
            ->join('platform_role role', 'role.id = membership.platform_role_id')
            ->where('operator.status', 'active')->where('role.status', 'active')
            ->field('operator.id AS operator_id,role.id AS role_id,role.revision,role.key AS role_key,role.is_builtin')
            ->order('operator.id,role.id')->select()->toArray();
        $revisions = [];
        foreach ($rows as $row) {
            if ($row['role_key'] === 'platform.bootstrap-owner' && (int) $row['is_builtin'] === 1) {
                return;
            }
            $revisions[(int) $row['role_id']] = (int) $row['revision'];
        }
        $permissions = $direct->rolePermissionKeysBatch($revisions);
        $operators = [];
        foreach ($rows as $row) {
            $id = (int) $row['operator_id'];
            foreach ($permissions[(int) $row['role_id']] as $key) {
                $operators[$id][$key] = true;
            }
        }
        foreach ($operators as $keys) {
            if (count(array_intersect(self::CONTROL_PERMISSION_KEYS, array_keys($keys))) === count(self::CONTROL_PERMISSION_KEYS)) {
                return;
            }
        }
        throw AdminAccessException::conflict('PLATFORM_CONTROL_ADMIN_REQUIRED', 'At least one active Platform control administrator must remain.');
    }

    private function bumpDirectRoleRevisionIfActive(int $roleId, int $expectedRevision): void
    {
        if ((new PlatformRoleDirectGrantStore())->source(true) !== 'direct') {
            return;
        }
        if (Db::name('platform_role_direct_state')->where('platform_role_id', $roleId)
            ->where('role_revision', $expectedRevision)->update([
                'role_revision' => $expectedRevision + 1, 'updated_at' => $this->now(),
            ]) !== 1) {
            throw AdminAccessException::conflict('PLATFORM_DIRECT_ROLE_NOT_MIGRATED', 'A Platform role has no current direct source.');
        }
    }

    private function createAccountAndCredential(string $email, string $displayName, string $password): int
    {
        $this->passwordPolicy->assertValid($password);
        $now = $this->now();
        $accountId = (int) Account::insertGetId([
            'display_name' => $displayName, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        Credential::insert([
            'account_id' => $accountId, 'kind' => 'email_password', 'identifier_type' => 'email',
            'identifier_normalized' => $email, 'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now, 'secret_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $accountId;
    }

    private function requireActor(PlatformContext $actor): void
    {
        if (PlatformOperator::where('id', $actor->operatorId)->where('account_id', $actor->accountId)
            ->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INACTIVE', 403, 'An active platform operator is required.');
        }
    }

    private function lockControlPlane(): void
    {
        PlatformOperator::where('status', 'active')->order('id')->lock(true)->column('id');
    }

    private function assertControlAdminExists(): void
    {
        $direct = new PlatformRoleDirectGrantStore();
        if ($direct->source() === 'direct') {
            $this->assertDirectControlAdminExists($direct);
            return;
        }
        $rows = PlatformOperator::alias('operator')
            ->join('platform_operator_role operator_role', 'operator_role.platform_operator_id = operator.id')
            ->join('platform_role role', "role.id = operator_role.platform_role_id AND role.status = 'active'")
            ->leftJoin('platform_role_permission role_permission', 'role_permission.platform_role_id = role.id')
            ->leftJoin('permission permission', "permission.id = role_permission.permission_id AND permission.status = 'active'")
            ->where('operator.status', 'active')
            ->field(['operator.id' => 'operator_id', 'role.key' => 'role_key', 'role.is_builtin', 'permission.key' => 'permission_key'])
            ->select()->toArray();
        $operators = [];
        foreach ($rows as $row) {
            $id = (int) $row['operator_id'];
            $operators[$id] ??= ['bootstrap' => false, 'permissions' => []];
            if ($row['role_key'] === 'platform.bootstrap-owner' && (bool) $row['is_builtin']) {
                $operators[$id]['bootstrap'] = true;
            }
            if (is_string($row['permission_key']) && in_array($row['permission_key'], self::CONTROL_PERMISSION_KEYS, true)) {
                $operators[$id]['permissions'][$row['permission_key']] = true;
            }
        }
        foreach ($operators as $operator) {
            if ($operator['bootstrap'] || count($operator['permissions']) === count(self::CONTROL_PERMISSION_KEYS)) {
                return;
            }
        }
        throw AdminAccessException::conflict(
            'PLATFORM_CONTROL_ADMIN_REQUIRED',
            'At least one active platform control administrator must remain.',
        );
    }

    private function revokeSessions(int $operatorId, string $reason, string $now): void
    {
        $sessionIds = PlatformSession::where('platform_operator_id', $operatorId)->column('id');
        if ($sessionIds !== []) {
            PlatformSessionToken::whereIn('session_id', $sessionIds)->where('status', 'active')->update([
                'status' => 'revoked', 'revoked_at' => $now,
            ]);
        }
        PlatformSession::where('platform_operator_id', $operatorId)->where('status', 'active')->update([
            'status' => 'revoked', 'revoked_at' => $now, 'revoke_reason' => 'operator_' . $reason, 'updated_at' => $now,
        ]);
    }

    private function bumpRoleOperators(int $roleId, string $now): void
    {
        $operatorIds = PlatformOperatorRole::where('platform_role_id', $roleId)->column('platform_operator_id');
        if ($operatorIds !== []) {
            PlatformOperator::whereIn('id', $operatorIds)->update([
                'security_revision' => new Raw('security_revision + 1'), 'updated_at' => $now,
            ]);
        }
    }

    /** @param list<int> $roleIds
     * @return list<array<string, mixed>>
     */
    private function activeRoles(array $roleIds): array
    {
        return $roleIds === [] ? [] : array_values(PlatformRole::where('status', 'active')->whereIn('id', $roleIds)
            ->order('id')->lock(true)->field('id,key')->select()->toArray());
    }

    /** @param list<string> $permissionKeys
     * @return list<array<string, mixed>>
     */
    private function platformPermissions(array $permissionKeys): array
    {
        return $permissionKeys === [] ? [] : array_values(Permission::where('status', 'active')
            ->whereLike('key', 'platform.%')->whereIn('key', $permissionKeys)->order('key')
            ->field('id,key')->select()->toArray());
    }

    /** @return array<string, mixed> */
    private function operator(int $operatorId, bool $forUpdate = false): array
    {
        $query = PlatformOperator::where('id', $operatorId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field(
            'id,account_id,display_name,status,security_revision,suspended_at,closed_at,created_at,updated_at',
        )->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['email'] = Credential::where('account_id', (int) $row['account_id'])
            ->where('identifier_type', 'email')->where('status', 'active')->value('identifier_normalized');
        $row['role_keys'] = array_map('strval', PlatformOperatorRole::alias('operator_role')
            ->join('platform_role role', 'role.id = operator_role.platform_role_id')
            ->where('operator_role.platform_operator_id', $operatorId)->where('role.status', 'active')
            ->order('role.key')->distinct(true)->column('role.key'));

        return $this->normalize($row);
    }

    /** @return array<string, mixed> */
    private function role(int $roleId, bool $forUpdate = false): array
    {
        $query = PlatformRole::where('id', $roleId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field('id,key,name,description,is_builtin,status,revision,archived_at,created_at,updated_at')
            ->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['permission_keys'] = array_map('strval', PlatformRolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('role_permission.platform_role_id', $roleId)->where('permission.status', 'active')
            ->order('permission.key')->distinct(true)->column('permission.key'));
        $row['is_builtin'] = (bool) $row['is_builtin'];

        return $this->normalize($row);
    }

    /** @param array<string, mixed> $operator */
    private function assertOperatorRevision(array $operator, int $expectedRevision): void
    {
        if ((int) $operator['security_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    /** @param array<string, mixed> $role */
    private function assertRoleRevision(array $role, int $expectedRevision): void
    {
        if ((int) $role['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function text(string $value, int $maxLength, string $errorCode): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw AdminAccessException::invalid($errorCode, 'The supplied text value is invalid.');
        }

        return $value;
    }

    private function nullableText(?string $value, int $maxLength, string $errorCode): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw AdminAccessException::invalid($errorCode, 'The supplied text value is invalid.');
        }

        return $value === '' ? null : $value;
    }

    private function changeReason(string $value): string
    {
        return $this->text($value, 255, 'CHANGE_REASON_REQUIRED');
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id')
                || str_ends_with($key, '_revision') || $key === 'revision')) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $operator
     * @return array<string, mixed>
     */
    private function operatorAuditSnapshot(array $operator): array
    {
        unset($operator['email']);

        return $operator;
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    private function recordAudit(
        PlatformContext $actor,
        string $eventType,
        string $action,
        string $targetType,
        int $targetId,
        ?array $before,
        ?array $after,
        array $metadata,
    ): void {
        $metadata += [str_replace('-', '_', $targetType) . '_id' => (string) $targetId];
        $this->audit->platform(
            $actor->operatorId,
            $actor->accountId,
            $actor->requestId,
            $eventType,
            $action,
            $metadata,
            $targetType,
            (string) $targetId,
            $before,
            $after,
        );
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation, string $conflictCode, string $conflictMessage): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict($conflictCode, $conflictMessage);
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
