<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Task\Service;

use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\dto\authorization\AdminPrincipal;
use app\common\exception\BusinessException;
use app\common\execution\CurrentExecutionContext;
use app\common\http\PageResult;
use PeanutAdmin\Modules\Task\Contract\JobRecord;
use PeanutAdmin\Modules\Task\Contract\TaskJobRuntime;
use PeanutAdmin\Modules\Task\Contract\TaskJobService;
use PeanutAdmin\Modules\Task\Job\Application\TaskJobException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

/** 定时任务与任务队列的租户后台管理用例。 */
final readonly class TaskAdminApplicationService
{
    public function __construct(
        private AdminAuthorizationQuery $authorization,
        private CrontabApplicationService $crontabs,
        private TaskJobRuntime $tasks,
        private CurrentExecutionContext $executionContext,
    ) {}

    /** @param array<string,mixed> $params */
    public function crontabs(TenantContext $tenant, AdminPrincipal $actor, array $params): PageResult
    {
        $this->assertPermission($tenant, $actor, 'peanut.task.list');
        return $this->crontabs->lists($params);
    }

    public function crontab(TenantContext $tenant, AdminPrincipal $actor, int $id): array
    {
        $this->assertPermission($tenant, $actor, 'peanut.task.detail');
        return $this->crontabs->detail($id);
    }

    /** @param array<string,mixed> $params */
    public function addCrontab(TenantContext $tenant, AdminPrincipal $actor, array $params): bool
    {
        $this->assertPermission($tenant, $actor, 'peanut.task.add');
        return $this->crontabs->add($params);
    }

    /** @param array<string,mixed> $params */
    public function editCrontab(TenantContext $tenant, AdminPrincipal $actor, array $params): bool
    {
        $this->assertPermission($tenant, $actor, 'peanut.task.edit');
        return $this->crontabs->edit($params);
    }

    public function deleteCrontab(TenantContext $tenant, AdminPrincipal $actor, int $id): bool
    {
        $this->assertPermission($tenant, $actor, 'peanut.task.delete');
        return $this->crontabs->delete($id);
    }

    public function operateCrontab(
        TenantContext $tenant,
        AdminPrincipal $actor,
        int $id,
        string $operation,
    ): bool {
        $this->assertPermission($tenant, $actor, 'peanut.task.operate');
        return $this->crontabs->operate($id, $operation);
    }

    public function previewExpression(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $expression,
    ): array {
        $this->assertPermission($tenant, $actor, 'peanut.task.expression');
        return $this->crontabs->expression($expression);
    }

    /** @return array{items:list<JobRecord>,page:int,page_size:int,total:int} */
    public function jobs(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $status,
        int $page,
        int $pageSize,
    ): array {
        $context = $this->jobContext($tenant, $actor, 'peanut.task.jobs.read', 'read');
        return $this->tasks->jobs()->list(
            $context,
            $status,
            $page,
            $pageSize,
        );
    }

    public function cancelJob(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $jobKey,
        int $revision,
    ): JobRecord {
        $context = $this->jobContext($tenant, $actor, 'peanut.task.jobs.manage', 'manage');
        return $this->tasks->jobs()->cancel(
            $context,
            $jobKey,
            $revision,
        );
    }

    public function retryJob(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $jobKey,
        int $revision,
    ): JobRecord {
        $context = $this->jobContext($tenant, $actor, 'peanut.task.jobs.manage', 'manage');
        return $this->tasks->jobs()->retry(
            $context,
            $jobKey,
            $revision,
        );
    }

    /** 权限执行点：旧式定时任务接口也在服务内复核，非 HTTP 调用没有豁免。 */
    private function assertPermission(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
    ): void {
        if (!$this->currentMatches($tenant, $actor)
            || !$this->authorization->decide($tenant, $actor, $permission)->allowed
        ) {
            throw BusinessException::forbidden('TASK_PERMISSION_DENIED', '无权管理定时任务');
        }
    }

    /** 权限执行点：任务队列低层只接收由本管理用例签发的 resource/operation。 */
    private function jobContext(
        TenantContext $tenant,
        AdminPrincipal $actor,
        string $permission,
        string $operation,
    ): AuthorizedOperationContext {
        if (!$this->currentMatches($tenant, $actor)
            || !$this->authorization->decide($tenant, $actor, $permission)->allowed
        ) {
            throw TaskJobException::denied();
        }
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            TaskJobService::RESOURCE_KEY,
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

    /** 防止显式管理参数与 ORM 当前租户 Scope 指向不同租户。 */
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
