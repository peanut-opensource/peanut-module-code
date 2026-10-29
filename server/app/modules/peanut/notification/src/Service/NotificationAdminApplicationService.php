<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use PeanutAdmin\Modules\Notification\Contract\NotificationCommands;
use PeanutAdmin\Modules\Notification\Contract\NotificationQueries;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationException;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationInboxService;
use PeanutAdmin\Modules\Notification\Delivery\Application\NotificationMessage;
use PeanutAdmin\Modules\Notification\Delivery\Package;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** 通知配置、日志与收件箱的租户后台管理用例。 */
final readonly class NotificationAdminApplicationService
{
    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private NotificationQueries $queries,
        private NotificationCommands $commands,
        private NotificationInboxService $inbox,
        private CurrentExecutionContext $executionContext,
    ) {}

    public function channel(TenantContext $tenant, AdminPrincipal $actor): array
    {
        $this->assertPermission($tenant, $actor, 'peanut.notification.channel.detail');
        return $this->queries->channelDetail();
    }

    /** @param array<string,mixed> $input */
    public function saveChannel(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $section,
        array $input,
    ): void {
        $this->assertPermission($tenant, $actor, 'peanut.notification.channel.save');
        $this->commands->saveChannel($section, $input);
    }

    public function scenes(TenantContext $tenant, AdminPrincipal $actor): array
    {
        $this->assertPermission($tenant, $actor, 'peanut.notification.scene.list');
        return $this->queries->scenes();
    }

    public function scene(TenantContext $tenant, AdminPrincipal $actor, int $id): array
    {
        $this->assertPermission($tenant, $actor, 'peanut.notification.scene.detail');
        return $this->queries->sceneDetail($id);
    }

    /** @param array<string,mixed> $params */
    public function saveScene(TenantContext $tenant, AdminPrincipal $actor, array $params): void
    {
        $this->assertPermission($tenant, $actor, 'peanut.notification.scene.save');
        $this->commands->saveScene($params);
    }

    /** @param array<string,mixed> $params */
    public function logs(TenantContext $tenant, AdminPrincipal $actor, array $params): PageResult
    {
        $this->assertPermission($tenant, $actor, 'peanut.notification.log.list');
        return $this->queries->logs($params);
    }

    public function log(TenantContext $tenant, AdminPrincipal $actor, int $id): array
    {
        $this->assertPermission($tenant, $actor, 'peanut.notification.log.detail');
        return $this->queries->logDetail($id);
    }

    /** @return array{items:list<NotificationMessage>,page:int,page_size:int,total:int} */
    public function messages(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $status,
        int $page,
        int $pageSize,
    ): array {
        return $this->inbox->inbox(
            $this->inboxContext($tenant, $actor, 'peanut.notification.inbox.read', 'read'),
            $status,
            $page,
            $pageSize,
        );
    }

    public function markRead(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $messageKey,
        int $revision,
    ): NotificationMessage {
        return $this->inbox->markRead(
            $this->inboxContext($tenant, $actor, 'peanut.notification.inbox.manage', 'manage'),
            $messageKey,
            $revision,
        );
    }

    /** @param list<string> $messageKeys */
    public function bulk(
        TenantContext $tenant,
        AdminPrincipal $actor,
        array $messageKeys,
        string $action,
    ): int {
        return $this->inbox->bulk(
            $this->inboxContext($tenant, $actor, 'peanut.notification.inbox.manage', 'manage'),
            $messageKeys,
            $action,
        );
    }

    /** 权限执行点：配置与日志入口在管理服务内复核，非 HTTP 调用不能绕过。 */
    private function assertPermission(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
    ): void {
        if (!$this->currentMatches($tenant, $actor)
            || !$this->authorization->decide($tenant, $actor, $permission)->allowed
        ) {
            throw BusinessException::forbidden('NOTIFICATION_PERMISSION_DENIED', '无权管理通知模块');
        }
    }

    /** 权限执行点：收件箱低层只接收本用例按固定动作签发的上下文。 */
    private function inboxContext(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
        string $operation,
    ): AuthorizedOperationContext {
        if (!$this->currentMatches($tenant, $actor)
            || !$this->authorization->decide($tenant, $actor, $permission)->allowed
        ) {
            throw NotificationException::denied();
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

    /** 防止显式管理参数与通知模型当前租户 Scope 指向不同租户。 */
    private function currentMatches(TenantContext $tenant, AdminPrincipal $actor): bool
    {
        try {
            $current = $this->executionContext->tenantAdmin();
        } catch (\Throwable) {
            return false;
        }
        return $current->tenantId === $tenant->tenantId
            && $current->accountId === $tenant->accountId
            && $current->memberId === $tenant->memberId
            && $current->authorizationRevision === $tenant->authorizationRevision
            && $actor->tenantId === $tenant->tenantId
            && $actor->accountId === $tenant->accountId
            && $actor->id === $tenant->memberId
            && $actor->authorizationRevision === $tenant->authorizationRevision;
    }
}
