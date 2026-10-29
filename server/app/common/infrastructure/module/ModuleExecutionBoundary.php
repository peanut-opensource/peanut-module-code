<?php

declare(strict_types=1);

namespace app\common\infrastructure\module;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\SystemExecutionContext;
use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Kernel\Module\ModuleExecutionContext;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Modules\Identity\Contract\AdminDirectoryQuery;

/**
 * Single execution boundary for every Module-aware entry point.
 *
 * Trusted identity is established before this boundary. Callers provide only
 * the Module and entry type; Tenant identity always comes from the current
 * immutable ExecutionContext.
 */
final readonly class ModuleExecutionBoundary
{
    private ModuleGuard $guard;

    public function __construct(
        private CurrentExecutionContext $execution,
        ModuleRuntimeRepository $modules,
        private AdminDirectoryQuery $tenants,
    ) {
        $this->guard = new ModuleGuard($modules);
    }

    public function assertHttp(string $moduleKey, ?string $operation = null): void
    {
        $context = $this->moduleContext($moduleKey, $operation);
        $this->assertEnabled($context);
    }

    public function assertExternalCallback(string $moduleKey): void
    {
        $context = $this->moduleContext($moduleKey);
        $this->assertBackgroundTenant($context);
    }

    public function assertWorker(string $moduleKey): void
    {
        $context = $this->moduleContext($moduleKey);
        $this->assertBackgroundTenant($context);
    }

    public function assertScheduled(string $moduleKey): void
    {
        $context = $this->moduleContext($moduleKey);
        $this->assertBackgroundTenant($context);
    }

    private function moduleContext(string $moduleKey, ?string $operation = null): ModuleExecutionContext
    {
        $execution = $this->execution->get();
        $operation = trim((string) $operation) !== '' ? trim((string) $operation) : $execution->operation();

        return match (true) {
            $execution instanceof AdminExecutionContext => ModuleExecutionContext::admin(
                $moduleKey,
                $execution->tenant,
                $operation,
            ),
            $execution instanceof ConsumerExecutionContext
                && $execution->member !== null => ModuleExecutionContext::businessMember(
                    $moduleKey,
                    $execution->member,
                    $operation,
                ),
            // 公开入口已由 Host 绑定建立受限系统身份，仍经过相同的模块部署和租户许可校验。
            $execution instanceof ConsumerExecutionContext
                && $execution->publicTenant !== null => ModuleExecutionContext::system(
                    $moduleKey,
                    $execution->publicTenant,
                ),
            $execution instanceof SystemExecutionContext => ModuleExecutionContext::system(
                $moduleKey,
                $execution->system,
            ),
            default => throw new \DomainException('MODULE_EXECUTION_CONTEXT_REQUIRED'),
        };
    }

    private function assertEnabled(ModuleExecutionContext $context): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->guard->assertDeployment($context->moduleKey);
        $this->guard->assertTenant($context->tenantId, $context->moduleKey, $now);
    }

    private function assertBackgroundTenant(ModuleExecutionContext $context): void
    {
        if (in_array($context->moduleKey, ['core', 'platform'], true)) {
            if ($this->tenants->tenantStatus($context->tenantId) !== 'active') {
                throw new ModuleException('CONTEXT_TENANT_REQUIRED', 'Tenant is not active.');
            }
            return;
        }
        $this->assertEnabled($context);
    }
}
