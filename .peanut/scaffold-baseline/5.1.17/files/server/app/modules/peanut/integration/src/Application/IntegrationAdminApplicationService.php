<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Application;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use PeanutAdmin\Modules\Integration\Package;
use DateTimeImmutable;
use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** 集成安全后台用例：具体动作在此固定权限、资源和操作，再调用已授权低层服务。 */
final readonly class IntegrationAdminApplicationService
{
    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private MachineIdentityService $machines,
        private WebhookService $webhooks,
        private WebhookDeliveryLogService $deliveries,
        private SessionSecurityService $sessions,
    ) {}

    /** @return list<MachineIdentity> */
    public function machines(TenantContext $tenant, AdminPrincipal $actor): array
    {
        return $this->machines->list($this->operation(
            $tenant,
            $actor,
            'peanut.integration.machine.read',
            'machine-read',
        ));
    }

    /** @param list<string> $scopes */
    public function createMachine(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $name,
        array $scopes,
        ?DateTimeImmutable $expiresAt,
    ): ProvisionedMachineIdentity {
        return $this->machines->create($this->operation(
            $tenant,
            $actor,
            'peanut.integration.machine.manage',
            'machine-manage',
        ), $name, $scopes, $expiresAt);
    }

    public function rotateMachine(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $identityKey,
        int $revision,
    ): ProvisionedMachineIdentity {
        return $this->machines->rotate($this->operation(
            $tenant,
            $actor,
            'peanut.integration.machine.manage',
            'machine-manage',
        ), $identityKey, $revision);
    }

    public function revokeMachine(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $identityKey,
        int $revision,
    ): MachineIdentity {
        return $this->machines->revoke($this->operation(
            $tenant,
            $actor,
            'peanut.integration.machine.manage',
            'machine-manage',
        ), $identityKey, $revision);
    }

    /** @return list<WebhookEndpoint> */
    public function webhooks(TenantContext $tenant, AdminPrincipal $actor): array
    {
        return $this->webhooks->list($this->operation(
            $tenant,
            $actor,
            'peanut.integration.webhook.read',
            'webhook-read',
        ));
    }

    /** @param list<string> $events */
    public function createWebhook(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $name,
        string $url,
        array $events,
    ): ProvisionedWebhookEndpoint {
        return $this->webhooks->create($this->operation(
            $tenant,
            $actor,
            'peanut.integration.webhook.manage',
            'webhook-manage',
        ), $name, $url, $events);
    }

    public function rotateWebhookSecret(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $endpointKey,
        int $revision,
    ): ProvisionedWebhookEndpoint {
        return $this->webhooks->rotateSecret($this->operation(
            $tenant,
            $actor,
            'peanut.integration.webhook.manage',
            'webhook-manage',
        ), $endpointKey, $revision);
    }

    public function disableWebhook(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $endpointKey,
        int $revision,
    ): WebhookEndpoint {
        return $this->webhooks->disable($this->operation(
            $tenant,
            $actor,
            'peanut.integration.webhook.manage',
            'webhook-manage',
        ), $endpointKey, $revision);
    }

    public function deliveries(
        TenantContext $tenant,
        AdminPrincipal $actor,
        int $page,
        int $pageSize,
    ): IntegrationSecurityPage {
        return $this->deliveries->deliveries($this->operation(
            $tenant,
            $actor,
            'peanut.integration.delivery.read',
            'delivery-read',
        ), $page, $pageSize);
    }

    public function deliveryAttempts(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $deliveryKey,
        int $page,
        int $pageSize,
    ): IntegrationSecurityPage {
        return $this->deliveries->attempts($this->operation(
            $tenant,
            $actor,
            'peanut.integration.delivery.read',
            'delivery-read',
        ), $deliveryKey, $page, $pageSize);
    }

    /** @return list<SessionDevice> */
    public function sessions(TenantContext $tenant, AdminPrincipal $actor): array
    {
        return $this->sessions->list($this->operation(
            $tenant,
            $actor,
            'peanut.integration.session.read',
            'session-read',
        ));
    }

    public function revokeSession(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $sessionKey,
    ): SessionDevice {
        return $this->sessions->revoke($this->operation(
            $tenant,
            $actor,
            'peanut.integration.session.revoke',
            'session-revoke',
        ), $sessionKey);
    }

    /** 权限执行点：只允许本类的具体用例选择 permission/resource/operation。 */
    private function operation(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
        string $operation,
    ): AuthorizedOperationContext {
        if (!$this->authorization->decide($tenant, $actor, $permission)->allowed) {
            throw IntegrationSecurityException::denied();
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            Package::RESOURCE_KEY,
            $operation,
            [],
            hash('sha256', implode("\0", [
                (string) $tenant->tenantId,
                (string) $tenant->memberId,
                (string) $tenant->authorizationRevision,
                $tenant->requestId,
                $permission,
                $operation,
            ])),
        ));
    }
}
