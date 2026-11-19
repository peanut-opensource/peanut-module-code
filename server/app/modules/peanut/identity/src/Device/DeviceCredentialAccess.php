<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Device;

use app\common\exception\BusinessException;
use app\common\contract\authorization\AdminAuthorizationQuery;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Module\ModuleAvailability;

final readonly class DeviceCredentialAccess
{
    public function __construct(
        private CurrentExecutionContext $execution,
        private AdminAuthorizationQuery $authorization,
        private ModuleAvailability $modules,
    ) {}

    public function assert(TenantContext $actor, string $permission, bool $lock = false): void
    {
        $current = $this->execution->current();
        if (!$current instanceof AdminExecutionContext || $current->tenant !== $actor
            || $actor->clientKey !== 'admin-web') {
            throw BusinessException::forbidden('IDENTITY_DEVICE_SCOPE_DENIED', '没有当前设备凭据操作权限。');
        }
        // Reuse current authorization, including this request's admitted API bundle.
        $principal = $this->authorization->principal($actor);
        if (!$this->authorization->decide($actor, $principal, $permission)->allowed) {
            throw BusinessException::forbidden('IDENTITY_DEVICE_SCOPE_DENIED', '没有当前设备凭据操作权限。');
        }
        $this->modules->assertDeployment('peanut.identity', $lock);
    }
}
