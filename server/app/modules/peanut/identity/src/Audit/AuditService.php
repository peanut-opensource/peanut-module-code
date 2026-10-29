<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Audit;

use PeanutAdmin\Modules\Identity\Audit\Model\PlatformAuditEventRecord;
use PeanutAdmin\Modules\Identity\Audit\Model\TenantAuditEventRecord;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\Raw;

final readonly class AuditService implements \PeanutAdmin\Kernel\Audit\AuditWriter, \PeanutAdmin\Modules\Identity\Contract\RevocationFacts
{
    public function __construct(
        private ?\app\common\execution\CurrentExecutionContext $execution = null,
        /** Lazy optional delivery prevents an Identity -> Task/Collaboration dependency cycle. */
        private ?\Closure $revocationDelivery = null,
    ) {}

    /** Called only by the Identity negative producers inside their original write transaction. */
    public function recordRevocation(TenantContext $actor, int $tenantId, int $memberId, string $kind, string $subjectId, string $reason, array $before, array $after, ?string $sessionFingerprint = null): void
    {
        $ambient = $this->execution?->current();
        $pdo = \think\facade\Db::connect()->getPdo();
        if (!$ambient instanceof \app\common\execution\AdminExecutionContext || $ambient->tenant != $actor
            || !$pdo instanceof \PDO || !$pdo->inTransaction() || $tenantId < 1 || $memberId < 1
            || !in_array($kind, ['member', 'role', 'session', 'tenant_scope_account'], true)
            || !in_array($reason, ['member_suspended', 'member_left', 'member_roles_removed', 'role_permissions_removed', 'role_archived', 'session_revoked', 'credential_changed'], true)
            || $subjectId === '' || strlen($subjectId) > 128 || $before === [] || $after === []
            || $sessionFingerprint !== null && preg_match('/^[0-9a-f]{64}$/D', $sessionFingerprint) !== 1) {
            throw new \DomainException('IDENTITY_REVOCATION_PRODUCER_INVALID');
        }
        foreach ([$before, $after] as $snapshotIndex => $snapshot) {
            foreach ($snapshot as $key => $value) {
                $pendingLeave = $snapshotIndex === 0 && $key === 'status' && $value === 'pending'
                    && $kind === 'member' && $subjectId === (string) $memberId && $reason === 'member_left'
                    && ($after['status'] ?? null) === 'left';
                if (!in_array($key, ['security_revision', 'authorization_revision', 'account_security_revision', 'status', 'role_ids', 'permission_ids'], true)
                    || !(is_int($value) && $value >= 0 || is_string($value) && in_array($value, ['active', 'suspended', 'left', 'archived', 'revoked'], true)
                        || $pendingLeave || is_array($value) && array_is_list($value) && array_filter($value, static fn($id) => !is_int($id) || $id < 1) === [])) {
                    throw new \DomainException('IDENTITY_REVOCATION_SNAPSHOT_INVALID');
                }
            }
        }
        $this->assertRevocationSource($actor, $tenantId, $memberId, $kind, $subjectId, $reason, $before, $after, $sessionFingerprint);
        $fact = ['schema_version' => 1, 'producer' => 'peanut.identity', 'fact_id' => 'revocation_' . bin2hex(random_bytes(16)),
            'tenant_id' => $tenantId, 'member_id' => $memberId, 'subject_kind' => $kind, 'subject_id' => $subjectId,
            'negative_action' => 'invalidate_old_grant', 'reason' => $reason, 'before' => $before, 'after' => $after,
            'session_fingerprint' => $sessionFingerprint, 'producer_operation_id' => $actor->requestId,
            'actor' => ['tenant_id' => $actor->tenantId, 'account_id' => $actor->accountId, 'member_id' => $actor->memberId],
            'occurred_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')];
        $hash = hash('sha256', self::revocationJson($fact));
        $row = new TenantAuditEventRecord();
        if ($row->db()->getConnection()->getPdo() !== $pdo) {
            throw new \DomainException('IDENTITY_REVOCATION_PDO_MISMATCH');
        }
        $row->save(['tenant_id' => $tenantId, 'event_type' => 'identity.revocation.fact', 'action' => $reason, 'outcome' => 'success',
            'actor_tenant_id' => $tenantId, 'actor_type' => 'tenant_system', 'target_resource_type' => 'member',
            'target_resource_id' => (string) $memberId, 'target_count' => 1, 'request_id' => $actor->requestId,
            'operation_id' => $fact['fact_id'], 'metadata_json' => $fact, 'occurred_at' => $fact['occurred_at'],
            'revocation_fact_key' => $fact['fact_id'], 'revocation_fact_sha256' => $hash]);
        if (!$pdo->inTransaction() || \think\facade\Db::connect()->getPdo() !== $pdo) {
            throw new \DomainException('IDENTITY_REVOCATION_PDO_MISMATCH');
        }
        $fact += ['fact_sha256' => $hash, 'audit_id' => (int) $row->id];
        if ($this->revocationDelivery !== null) {
            ($this->revocationDelivery)($fact);
        }
        if (!$pdo->inTransaction()) {
            throw new \DomainException('IDENTITY_REVOCATION_TRANSACTION_LOST');
        }
    }

    /** Persistent post-change evidence narrows this internal writer to the actual Identity producers. */
    private function assertRevocationSource(TenantContext $actor, int $tenantId, int $memberId, string $kind, string $subjectId, string $reason, array $before, array $after, ?string $sessionFingerprint): void
    {
        $member = \PeanutAdmin\Modules\Identity\Persistence\Model\TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)
            ->field('id,account_id,status,security_revision,authorization_revision')->find();
        if ($member === null || $tenantId !== $actor->tenantId && $reason !== 'credential_changed') {
            throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
        }
        $expectedKind = match ($reason) {
            'member_suspended', 'member_left', 'member_roles_removed' => 'member',
            'role_permissions_removed', 'role_archived' => 'role',
            'session_revoked' => 'session', 'credential_changed' => 'tenant_scope_account',
        };
        if ($kind !== $expectedKind || ($reason === 'session_revoked') !== ($sessionFingerprint !== null)) {
            throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
        }
        if ($kind === 'member') {
            if ($subjectId !== (string) $memberId || (int) $member->authorization_revision !== ($after['authorization_revision'] ?? null)
                || ($after['authorization_revision'] ?? null) !== ($before['authorization_revision'] ?? -1) + 1) {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
            if ($reason === 'member_roles_removed') {
                $roles = array_map('intval', \PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole::where('tenant_id', $tenantId)->where('tenant_member_id', $memberId)->column('role_id'));
                sort($roles);
                $newRoles = $after['role_ids'] ?? [];
                sort($newRoles);
                if ($roles !== $newRoles || array_diff($before['role_ids'] ?? [], $newRoles) === []) {
                    throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
                }
            } elseif ($member->status !== $after['status'] || $after['status'] !== substr($reason, 7)
                || (int) $member->security_revision !== ($after['security_revision'] ?? null)
                || $after['security_revision'] !== ($before['security_revision'] ?? -1) + 1) {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
        } elseif ($kind === 'role') {
            if (preg_match('/^[1-9][0-9]*$/D', $subjectId) !== 1) {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
            $role = \PeanutAdmin\Modules\Identity\Persistence\Model\Role::where('tenant_id', $tenantId)->where('id', $subjectId)->field('status,authorization_revision')->find();
            if ($role === null || (int) $role->authorization_revision !== ($after['authorization_revision'] ?? null)
                || ($after['authorization_revision'] ?? null) !== ($before['authorization_revision'] ?? -1) + 1
                || \PeanutAdmin\Modules\Identity\Persistence\Model\MemberRole::where('tenant_id', $tenantId)->where('tenant_member_id', $memberId)->where('role_id', $subjectId)->find() === null) {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
            if ($reason === 'role_permissions_removed') {
                $permissions = array_map('intval', \PeanutAdmin\Modules\Identity\Persistence\Model\RolePermission::where('tenant_id', $tenantId)->where('role_id', $subjectId)->column('permission_id'));
                sort($permissions);
                $newPermissions = $after['permission_ids'] ?? [];
                sort($newPermissions);
                if ($permissions !== $newPermissions || array_diff($before['permission_ids'] ?? [], $newPermissions) === []) {
                    throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
                }
            } elseif ($role->status !== 'archived' || ($after['status'] ?? null) !== 'archived') {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
        } elseif ($kind === 'session') {
            if ($memberId !== $actor->memberId || (int) $member->account_id !== $actor->accountId || $subjectId !== $sessionFingerprint
                || ($before['status'] ?? null) !== 'active' || ($after['status'] ?? null) !== 'revoked'
                || \PeanutAdmin\Modules\Identity\Persistence\Model\TenantSession::where('tenant_id', $tenantId)->where('tenant_member_id', $memberId)->where('account_id', $actor->accountId)
                    ->where('status', 'revoked')->whereRaw('SHA2(session_key, 256) = ?', [$sessionFingerprint])->find() === null) {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
        } else {
            $revision = \PeanutAdmin\Modules\Identity\Persistence\Model\Account::where('id', $actor->accountId)->value('security_revision');
            if ((int) $member->account_id !== $actor->accountId || $subjectId !== (string) $actor->accountId
                || (int) $revision !== ($after['account_security_revision'] ?? null)
                || ($after['account_security_revision'] ?? null) !== ($before['account_security_revision'] ?? -1) + 1) {
                throw new \DomainException('IDENTITY_REVOCATION_SOURCE_INVALID');
            }
        }
    }

    public function revocationFact(int $tenantId, string $factId, string $hash): array
    {
        if ($tenantId < 1 || preg_match('/^revocation_[0-9a-f]{32}$/D', $factId) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new \DomainException('IDENTITY_REVOCATION_REFERENCE_INVALID');
        }
        $context = $this->execution?->current();
        $pdo = \think\facade\Db::connect()->getPdo();
        $producer = $context instanceof \app\common\execution\AdminExecutionContext && $pdo instanceof \PDO && $pdo->inTransaction();
        $worker = $context instanceof \app\common\execution\SystemExecutionContext && $context->tenantId() === $tenantId
            && $context->operation() === 'identity.revocation.propagate' && $context->metadata->jobKey !== null;
        $discovery = $context instanceof \app\common\execution\InstanceExecutionContext && PHP_SAPI === 'cli'
            && $context->operation() === 'console.identity:revocations';
        if (!$producer && !$worker && !$discovery) {
            throw new \DomainException('IDENTITY_REVOCATION_READ_DENIED');
        }
        // The dedicated discovery command only enqueues facts it read before its Task transaction.
        if ($worker && $pdo instanceof \PDO && $pdo->inTransaction()) {
            throw new \DomainException('IDENTITY_REVOCATION_NOT_COMMITTED');
        }
        $row = TenantAuditEventRecord::where('tenant_id', $tenantId)->where('event_type', 'identity.revocation.fact')
            ->where('revocation_fact_key', $factId)->find();
        if ($row === null || !hash_equals((string) $row->revocation_fact_sha256, $hash)) {
            throw new \DomainException('IDENTITY_REVOCATION_FACT_MISMATCH');
        }
        $fact = $row->metadata_json;
        if (!is_array($fact) || $fact['schema_version'] !== 1 || $fact['producer'] !== 'peanut.identity'
            || $fact['tenant_id'] !== $tenantId || $fact['fact_id'] !== $factId || $fact['negative_action'] !== 'invalidate_old_grant'
            || !hash_equals($hash, hash('sha256', self::revocationJson($fact)))) {
            throw new \DomainException('IDENTITY_REVOCATION_FACT_MISMATCH');
        }
        if ($producer) {
            $actor = $fact['actor'] ?? null;
            if (!is_array($actor)) {
                throw new \DomainException('IDENTITY_REVOCATION_FACT_MISMATCH');
            }
            $expectedActor = ['tenant_id' => $context->tenant->tenantId, 'account_id' => $context->tenant->accountId, 'member_id' => $context->tenant->memberId];
            // MySQL JSON object ordering is not the producer's insertion order.
            // Keep exact keys and PHP value types after normalizing only order.
            ksort($actor, SORT_STRING);
            ksort($expectedActor, SORT_STRING);
            if ($actor !== $expectedActor || $fact['producer_operation_id'] !== $context->tenant->requestId) {
                throw new \DomainException('IDENTITY_REVOCATION_FACT_MISMATCH');
            }
        }
        return $fact + ['fact_sha256' => $hash, 'audit_id' => (int) $row->id];
    }

    public function revocationFacts(int $tenantId, int $afterId, int $limit): array
    {
        $context = $this->execution?->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.identity:revocations' || $tenantId < 1 || $afterId < 0 || $limit < 1 || $limit > 100) {
            throw new \DomainException('IDENTITY_REVOCATION_SCAN_DENIED');
        }
        $rows = TenantAuditEventRecord::where('tenant_id', $tenantId)->where('event_type', 'identity.revocation.fact')
            ->where('id', '>', $afterId)->field('id,revocation_fact_key,revocation_fact_sha256')->order('id')->limit($limit)->select();
        $facts = [];
        foreach ($rows as $row) {
            $facts[] = $this->revocationFact($tenantId, (string) $row->revocation_fact_key, (string) $row->revocation_fact_sha256);
        }
        return $facts;
    }

    /** A durable conservative alert, not an invented commit timestamp or measured SLA. */
    public function recordRevocationOverdueCandidate(array $fact): void
    {
        $context = $this->execution?->current();
        if (PHP_SAPI !== 'cli' || !$context instanceof \app\common\execution\InstanceExecutionContext
            || $context->operation() !== 'console.identity:revocations') {
            throw new \DomainException('IDENTITY_REVOCATION_SCAN_DENIED');
        }
        $this->revocationFact($fact['tenant_id'], $fact['fact_id'], $fact['fact_sha256']);
        $key = 'overdue_' . $fact['fact_id'];
        if (TenantAuditEventRecord::where('tenant_id', $fact['tenant_id'])->where('revocation_fact_key', $key)->find() !== null) {
            return;
        }
        $row = new TenantAuditEventRecord();
        $row->save(['tenant_id' => $fact['tenant_id'], 'event_type' => 'identity.revocation.overdue_candidate',
            'action' => 'identity.revocation.detect', 'outcome' => 'error', 'reason_code' => 'REVOCATION_ACK_UNCONFIRMED',
            'actor_tenant_id' => $fact['tenant_id'], 'actor_type' => 'tenant_system', 'target_count' => 1,
            'target_resource_type' => 'revocation_fact', 'target_resource_id' => $fact['fact_id'],
            'request_id' => $context->requestId(), 'metadata_json' => ['fact_id' => $fact['fact_id'], 'fact_sha256' => $fact['fact_sha256'], 'timing_basis' => 'precommit_occurred_at'],
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'), 'revocation_fact_key' => $key]);
    }

    private static function revocationJson(array $value): string
    {
        $normalize = static function (mixed $v) use (&$normalize): mixed {
            if (is_array($v)) {
                if (!array_is_list($v)) {
                    ksort($v, SORT_STRING);
                } foreach ($v as &$item) {
                    $item = $normalize($item);
                }
            }
            return $v;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    /** Persists a fully authorized platform audit projection without exposing its Model. */
    public function appendPlatformEvent(
        string $eventType,
        string $action,
        string $outcome,
        ?string $reasonCode,
        ?int $operatorId,
        ?int $accountId,
        ?string $targetType,
        ?string $targetId,
        string $requestId,
        ?string $operationId,
        ?string $ipAddress,
        ?string $userAgentHash,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $metadataJson,
    ): void {
        self::assertProjection($eventType, $action, $outcome, $requestId);
        if (($operatorId === null) !== ($accountId === null)
            || ($operatorId !== null && ($operatorId < 1 || $accountId < 1))) {
            throw new \DomainException('AUDIT_PLATFORM_ACTOR_INVALID');
        }
        (new PlatformAuditEventRecord())->save([
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'operation_id' => $operationId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'before_json' => self::jsonDocument($beforeJson),
            'after_json' => self::jsonDocument($afterJson),
            'metadata_json' => self::jsonDocument($metadataJson),
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** Persists a fully authorized Tenant audit projection without exposing its Model. */
    public function appendTenantEvent(
        int $tenantId,
        string $eventType,
        string $action,
        string $outcome,
        ?string $reasonCode,
        ?int $actorTenantId,
        ?int $actorTenantMemberId,
        ?int $actorAccountId,
        ?int $actorPlatformOperatorId,
        string $actorType,
        ?string $targetResourceType,
        ?string $targetResourceId,
        ?string $boundaryTargetType,
        ?string $boundaryTargetId,
        int $targetCount,
        ?string $targetSetDigest,
        ?string $authorizationBasisJson,
        string $requestId,
        ?string $operationId,
        ?string $ipAddress,
        ?string $userAgentHash,
        ?string $beforeJson,
        ?string $afterJson,
        ?string $metadataJson,
    ): void {
        self::assertProjection($eventType, $action, $outcome, $requestId);
        $actorValid = match ($actorType) {
            'member' => $actorTenantId === $tenantId && ($actorTenantMemberId ?? 0) > 0
                && ($actorAccountId ?? 0) > 0 && $actorPlatformOperatorId === null,
            'tenant_system' => $actorTenantId === $tenantId && $actorTenantMemberId === null
                && $actorAccountId === null && $actorPlatformOperatorId === null,
            'platform_operator' => $actorTenantId === null && $actorTenantMemberId === null
                && ($actorAccountId ?? 0) > 0 && ($actorPlatformOperatorId ?? 0) > 0,
            default => false,
        };
        if ($tenantId < 1 || !$actorValid || $targetCount < 0) {
            throw new \DomainException('AUDIT_TENANT_PROJECTION_INVALID');
        }
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'actor_tenant_id' => $actorTenantId,
            'actor_tenant_member_id' => $actorTenantMemberId,
            'actor_account_id' => $actorAccountId,
            'actor_platform_operator_id' => $actorPlatformOperatorId,
            'actor_type' => $actorType,
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'boundary_target_type' => $boundaryTargetType,
            'boundary_target_id' => $boundaryTargetId,
            'target_count' => $targetCount,
            'target_set_digest' => $targetSetDigest,
            'authorization_basis_json' => self::jsonDocument($authorizationBasisJson),
            'request_id' => $requestId,
            'operation_id' => $operationId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'before_json' => self::jsonDocument($beforeJson),
            'after_json' => self::jsonDocument($afterJson),
            'metadata_json' => self::jsonDocument($metadataJson),
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    private static function assertProjection(string $eventType, string $action, string $outcome, string $requestId): void
    {
        if (trim($eventType) === '' || trim($action) === '' || trim($requestId) === ''
            || \PeanutAdmin\Kernel\Audit\AuditOutcome::tryFrom($outcome) === null) {
            throw new \DomainException('AUDIT_PROJECTION_INVALID');
        }
    }

    /** @return array<array-key, mixed>|null */
    private static function jsonDocument(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        try {
            $document = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \DomainException('AUDIT_JSON_INVALID', 0, $exception);
        }
        if (!is_array($document)) {
            throw new \DomainException('AUDIT_JSON_DOCUMENT_REQUIRED');
        }
        return $document;
    }

    /** @param array<string, mixed> $metadata */
    public function tenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        array $metadata = [],
        ?int $targetCount = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        ?string $targetSetDigest = null,
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $context->tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $context->tenantId,
            'actor_tenant_member_id' => $context->memberId,
            'actor_account_id' => $context->accountId,
            'actor_type' => 'member',
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'boundary_target_type' => $boundaryTargetType,
            'boundary_target_id' => $boundaryTargetId,
            'target_count' => $targetCount ?? ($targetResourceId === null ? 0 : 1),
            'target_set_digest' => $targetSetDigest,
            'request_id' => $context->requestId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function tenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $tenantId,
            'actor_type' => 'tenant_system',
            'target_count' => 0,
            'request_id' => $requestId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function platform(
        int $operatorId,
        int $accountId,
        string $requestId,
        string $eventType,
        string $action,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new PlatformAuditEventRecord())->save([
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function tenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        \PeanutAdmin\Kernel\Audit\AuditOutcome $outcome = \PeanutAdmin\Kernel\Audit\AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => null,
            'actor_platform_operator_id' => $operatorId,
            'actor_account_id' => $accountId,
            'actor_type' => 'platform_operator',
            'target_resource_type' => $targetType,
            'target_resource_id' => $targetId,
            'target_count' => $targetId === null ? 0 : 1,
            'request_id' => $requestId,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }
}
