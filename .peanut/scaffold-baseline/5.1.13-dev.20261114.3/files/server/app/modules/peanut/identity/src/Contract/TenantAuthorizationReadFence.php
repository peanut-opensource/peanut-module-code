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
