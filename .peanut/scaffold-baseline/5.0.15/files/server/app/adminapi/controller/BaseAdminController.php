<?php

declare(strict_types=1);

namespace app\adminapi\controller;

use app\BaseController;
use app\common\traits\ApiResponseTrait;
use app\common\execution\AdminExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;

abstract class BaseAdminController extends BaseController
{
    use ApiResponseTrait;

    protected int   $adminId   = 0;
    protected array $adminInfo = [];

    public function initialize(): void
    {
        $current = $this->executionContext();
        if ($current->current() instanceof AdminExecutionContext) {
            $this->adminInfo = $current->tenantAdminPrincipal();
            $this->adminId = (int) ($this->adminInfo['id'] ?? 0);
        }
    }

    /** 供业务入口使用的类型化人员摘要；真实授权仍由业务服务核验。 */
    protected function tenantAdminActor(): \app\common\dto\authorization\AdminPrincipal
    {
        $tenant = $this->tenantAdminContext();
        $actor = \app\common\dto\authorization\AdminPrincipal::fromArray(
            $this->executionContext()->tenantAdminPrincipal(),
        );
        if ($actor->id !== $tenant->memberId || $actor->tenantId !== $tenant->tenantId
            || $actor->accountId !== $tenant->accountId) {
            throw new \DomainException('EXECUTION_ADMIN_PRINCIPAL_REQUIRED');
        }
        return $actor;
    }

    protected function tenantAdminContext(): TenantContext
    {
        return $this->executionContext()->tenantAdmin();
    }
}
