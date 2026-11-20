<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Contract;

use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Modules\Identity\Authorization\ThinkPhpTenantAuthorizationRepository;
use think\facade\Db;

/** Detects changed native authorization inputs; a digest never grants permission. */
final readonly class TenantAuthorizationReadFence
{
    public function __construct(
        private ThinkPhpTenantAuthorizationRepository $repository,
        private CurrentExecutionContext $execution,
        private TenantModuleStateQueries $moduleStates,
        private RevocationFacts $revocations,
    ) {}

    /** Only the original Admin grant transaction may capture and retain these IAM locks. */
    public function captureGrant(TenantContext $context, array $permissionKeys, array $moduleKeys): string
    {
        $this->assertScope($context);
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new \DomainException('IDENTITY_GRANT_TRANSACTION_REQUIRED');
        }
        $before = $this->repository->readFence($context, $permissionKeys, $moduleKeys, false);
        $this->lockAndAssert($context, $permissionKeys, $moduleKeys, $before);
        if (Db::connect()->getPdo() !== $pdo || !$pdo->inTransaction()) {
            throw new \DomainException('IDENTITY_GRANT_TRANSACTION_CHANGED');
        }
        return $before;
    }

    /** Same input digest for ordinary read-only consumers; this does not acquire locks or authorize. */
    public function currentDigest(TenantContext $context, array $permissionKeys, array $moduleKeys): string
    {
        $this->assertScope($context);
        return $this->repository->readFence($context, $permissionKeys, $moduleKeys, false);
    }

    /** Compare unlocked and locked IAM inputs before exposing them to a single read-only operation.
     * The version digest covers its original keys, independently of the owner's required modules.
     */
    public function withLockedRead(TenantContext $context, array $permissionKeys, array $requiredModules,
        array $revisionPermissions, array $revisionModules, array $optionalModules, callable $read): mixed
    {
        $this->assertScope($context);
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new \DomainException('IDENTITY_GRANT_TRANSACTION_REQUIRED');
        }
        $permissions = array_values(array_unique([...$permissionKeys, ...$revisionPermissions]));
        $before = $this->repository->readFence($context, $permissions, $requiredModules, false);
        $this->moduleStates->lockLifecycleRows($requiredModules);
        $facts = $this->repository->fenceFacts($context, $permissions, $requiredModules, true, $planKeys);
        if (!hash_equals($before, $this->repository::fenceDigest($facts))) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
        }
        $keys = array_values(array_unique([...$requiredModules, ...$revisionModules, ...$optionalModules]));
        $rows = $this->moduleStates->installationIdentities($keys);
        if ($facts['session_current'] === [] || $facts['session'] === []) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_SESSION_ENDED');
        }
        $session = $facts['session'][0];
        $snapshot = ['actor' => $context, 'execution' => $this->execution, 'pdo' => $pdo, 'license' => $facts['license'],
            'member_revision' => (int) ($facts['member'][0]['authorization_revision'] ?? 0),
            'tenant_revision' => (int) ($facts['tenant'][0]['authorization_revision'] ?? 0),
            'session_deadline' => new \DateTimeImmutable(min($session['idle_expires_at'], $session['absolute_expires_at']), new \DateTimeZone('UTC')),
            'effective_modules' => $facts['effective_modules'], 'plan_module_keys' => $planKeys ?? [],
            'installations' => $rows + array_fill_keys($keys, null)];
        $revisionRows = array_values(array_intersect_key($rows, array_flip($revisionModules)));
        $facts = array_replace($facts, $this->repository::installationFacts($revisionRows));
        $facts['permissions'] = array_values(array_filter($facts['permissions'], static fn(array $r): bool => in_array($r['key'], $revisionPermissions, true)));
        $permissionIds = array_column($facts['permissions'], 'id');
        $facts['grants'] = array_values(array_filter($facts['grants'], static fn(array $r): bool => in_array($r['permission_id'], $permissionIds, true)));
        $request = app()->request;
        $previous = $request->middleware(self::class);
        $request->withMiddleware([self::class => $snapshot]);
        try {
            $result = $read($this->repository::fenceDigest($facts));
            self::assertReadWindow($snapshot);
            return $result;
        } finally {
            $request->withMiddleware([self::class => $previous]);
        }
    }

    /** Only nonlocking reads of this transaction's fixed facts may use the current projection. */
    public static function activeRead(?int $tenantId = null): ?array
    {
        $snapshot = app()->request->middleware(self::class);
        if (!is_array($snapshot)) {
            return null;
        }
        $root = $snapshot['execution']->current();
        $actor = $snapshot['actor'];
        if (!$root instanceof AdminExecutionContext || ($tenantId !== null && $actor->tenantId !== $tenantId)
            || $root->tenant->tenantId !== $actor->tenantId || $root->tenant->memberId !== $actor->memberId
            || $root->tenant->accountId !== $actor->accountId || $root->tenant->clientKey !== $actor->clientKey
            || $root->tenant->authorizationRevision !== $snapshot['member_revision']
            || $root->tenant->tenantAuthorizationRevision !== $snapshot['tenant_revision']
            || !hash_equals($root->tenant->sessionKey, $actor->sessionKey)
            || Db::connect()->getPdo() !== $snapshot['pdo'] || !$snapshot['pdo']->inTransaction()) {
            return null;
        }
        self::assertReadWindow($snapshot);
        return $snapshot;
    }

    private static function assertReadWindow(array $snapshot): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($snapshot['session_deadline'] <= $now) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_SESSION_ENDED');
        }
        $license = $snapshot['license'][0] ?? null;
        if ($license !== null) {
            $effective = $license['status'] !== 'suspended' && new \DateTimeImmutable($license['effective_at']) <= $now
                && ($license['expires_at'] === null || new \DateTimeImmutable($license['expires_at']) > $now);
            if ($license['effective'] !== $effective) {
                throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
            }
        }
    }

    /** Grants no Admin context. Only the already claimed immutable fact's own member may be compared. */
    public function assertRevocationScope(TenantContext $context, string $factId, string $factHash): void
    {
        $root = $this->execution->current();
        $pdo = Db::connect()->getPdo();
        if (!$root instanceof \app\common\execution\SystemExecutionContext || $root->operation() !== 'identity.revocation.propagate'
            || $root->metadata->handlerKey !== 'identity.revocation.propagate' || $root->metadata->jobKey === null
            || $root->metadata->attemptNumber < 1 || $context->tenantId !== $root->tenantId()
            || $context->requestId !== $root->metadata->jobKey || $pdo instanceof \PDO && $pdo->inTransaction()) {
            throw new \DomainException('IDENTITY_NEGATIVE_GRANT_SCOPE_DENIED');
        }
        $fact = $this->revocations->revocationFact($context->tenantId, $factId, $factHash);
        if ($fact['member_id'] !== $context->memberId) {
            throw new \DomainException('IDENTITY_NEGATIVE_GRANT_SCOPE_DENIED');
        }
    }

    public function revocationDigest(TenantContext $context, array $permissionKeys, array $moduleKeys, string $factId, string $factHash): string
    {
        $this->assertRevocationScope($context, $factId, $factHash);
        return $this->repository->readFence($context, $permissionKeys, $moduleKeys, false);
    }

    /** Capture outside a transaction around the caller's existing permission decision. */
    public function snapshot(TenantContext $context, array $permissionKeys, array $moduleKeys): string
    {
        $this->assertScope($context);
        if (Db::connect()->getPdo()->inTransaction()) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_SNAPSHOT_TRANSACTION_DENIED');
        }
        return $this->repository->readFence($context, $permissionKeys, $moduleKeys, false);
    }

    /** Locks remain in the caller's native transaction until its commit or rollback. */
    public function lockAndAssert(TenantContext $context, array $permissionKeys, array $moduleKeys, string $expected): void
    {
        $this->assertScope($context);
        if (!Db::connect()->getPdo()->inTransaction() || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
        }
        $this->moduleStates->lockLifecycleRows($moduleKeys);
        if (!hash_equals($expected, $this->repository->readFence($context, $permissionKeys, $moduleKeys, true))) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_PROOF_STALE');
        }
    }

    private function assertScope(TenantContext $context): void
    {
        $root = $this->execution->current();
        if (!$root instanceof AdminExecutionContext || $root->tenant->tenantId !== $context->tenantId
            || $root->tenant->accountId !== $context->accountId || $root->tenant->memberId !== $context->memberId
            || $root->tenant->clientKey !== $context->clientKey || !hash_equals($root->tenant->sessionKey, $context->sessionKey)) {
            throw new \DomainException('IDENTITY_AUTHORIZATION_READ_SCOPE_DENIED');
        }
    }
}
