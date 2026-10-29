<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Infrastructure\Authorization;

use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\AuthException;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Modules\Identity\Contract\TenantMemberDirectory;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Notification\Contract\NotificationProducerRegistry;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Modules\Notification\Delivery\Persistence\NotificationRepository;
use think\facade\Db;

/** Every signed delivery keeps its real submitting session and current owning authorization. */
final readonly class NotificationAsyncAuthorization
{
    public function __construct(private AdminAuthorizationService $authorization, private TenantSessionAccess $sessions,
        private TenantMemberDirectory $members, private NotificationRepository $repository, private NotificationProducerRegistry $producers,
        private \PeanutAdmin\Kernel\Module\ModuleAvailability $modules) {}

    public function bind(AuthorizedOperationContext $context, string $outboxKey): void
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) { throw $this->denied(); }
        $source = $context->tenantContext;
        $current = $this->sessions->revalidateSession($source->tenantId, $source->accountId, $source->memberId, $source->sessionKey, $source->clientKey, $source->requestId);
        if ($current->tenantId !== $source->tenantId || $current->accountId !== $source->accountId || $current->memberId !== $source->memberId
            || $current->authorizationRevision !== $source->authorizationRevision || $current->sessionKey !== $source->sessionKey || $current->clientKey !== $source->clientKey) { throw $this->denied(); }
        $checked = $this->authorizeCurrent($context->tenantContext, $outboxKey);
        if ($checked->resourceKey !== $context->resourceKey || $checked->operation !== $context->operation
            || $this->targets($checked->targets) !== $this->targets($context->targets)) { throw $this->denied(); }
        $this->repository->bindActor($checked, $outboxKey);
    }

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        if ($envelope->tenantId < 1 || $envelope->accountId < 1 || $envelope->memberId < 1 || $envelope->traceId === '' || $envelope->operationId === '') { throw $this->denied(); }
        $reference = $this->repository->actorReference($envelope);
        $tenant = $this->sessions->revalidateSession($envelope->tenantId, $envelope->accountId, $envelope->memberId,
            $reference['session_key'], $reference['client_key'], $envelope->traceId);
        $member = $this->members->current($tenant);
        if ($member === null || $tenant->tenantId !== $envelope->tenantId || $tenant->accountId !== $envelope->accountId || $tenant->memberId !== $envelope->memberId
            || $tenant->sessionKey !== $reference['session_key'] || $tenant->clientKey !== $reference['client_key'] || $tenant->requestId !== $envelope->traceId
            || $member->accountSecurityRevision !== (int) $reference['account_security_revision'] || $member->tenantSecurityRevision !== (int) $reference['tenant_security_revision']
            || $member->securityRevision !== (int) $reference['member_security_revision']) { throw $this->denied(); }
        $checked = $this->authorizeCurrent($tenant, $reference['outbox_key']);
        if ($checked->resourceKey !== $envelope->resourceKey || $checked->operation !== $envelope->operation
            || $this->targets($checked->targets) !== $this->targets($envelope->requestedTargets)) { throw $this->denied(); }
        return $checked;
    }

    public function authorizeCurrent(\PeanutAdmin\Kernel\Auth\TenantContext $tenant, string $outboxKey): AuthorizedOperationContext
    {
        $this->modules->assertAvailable(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($tenant->tenantId, 'notification'), 'peanut.notification', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $identity = $this->repository->publicationIdentity($tenant->tenantId, $outboxKey);
        if ($identity !== null) { return $this->producers->require($identity->producerKey)->authorize($tenant, $identity); }
        $principal = $this->authorization->principal($tenant);
        if (!$this->authorization->decide($tenant, $principal, Package::MANAGE_PERMISSION)->allowed) { throw $this->denied(); }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($tenant, Package::RESOURCE_KEY, 'manage', [],
            hash('sha256', $tenant->requestId . ':' . $principal->authorizationRevision . ':' . Package::MANAGE_PERMISSION)));
    }

    private function targets(array $targets): string { return hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR)); }
    private function denied(): AuthException { return new AuthException('CONTEXT_SYSTEM_ACTOR_INVALID', 403); }
}
