<?php

declare(strict_types=1);

namespace app\adminapi\http\middleware;

use app\adminapi\infrastructure\AdminApiAccessRegistry;
use app\common\services\authorization\AdminAuthorizationService;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use app\common\contract\module\ModuleQualificationQuery;
use PeanutAdmin\Modules\Identity\Module\TenantEntitlementService;

/**
 * 权限中间件（原生 TP 风格）
 *
 * 必须在路由级 LoginMiddleware 之后执行，从 CurrentExecutionContext 获取已验证人员；
 * 不从 Request 动态属性或客户端参数恢复可信身份。
 * 只有版本化 authenticated 元数据或启用的精确权限节点可以放行。
 * root 只绕过角色授权，不能绕过套餐权益、路由登记、登录或身份边界。
 */
class AuthMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationService $authorization,
        private readonly AdminApiAccessRegistry $accessRegistry,
        private readonly DemoAccountPolicy $demoAccounts,
        private readonly ApiPermissionCatalog $apis,
        private readonly ModuleQualificationQuery $modules,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    public function handle($request, \Closure $next)
    {
        $current = $this->executionContext->current();
        $adminInfo = $current instanceof AdminExecutionContext
            ? $this->executionContext->tenantAdminPrincipal()
            : null;
        if (empty($adminInfo)) {
            throw \app\common\http\ApiProblem::fromEnvelope('请先登录', null, 40100);
        }

        $path = 'adminapi/' . strtolower(trim($request->pathinfo(), '/'));
        $endpoint = $this->apis->endpoint($request, 'tenant');
        if ($endpoint === null) {
            throw \app\common\http\ApiProblem::fromEnvelope('API 未登记或已退役', null, 40300);
        }
        $tenantContext = $current instanceof AdminExecutionContext ? $current->tenant : null;
        if (!$tenantContext instanceof \PeanutAdmin\Kernel\Auth\TenantContext
            || !$this->apis->moduleAvailable($endpoint, $this->modules)
            || (!in_array($endpoint['module_key'], ['peanut.admin', 'platform', 'member', 'installation'], true)
                && !in_array($endpoint['module_key'], $this->entitlements->moduleKeys($tenantContext->tenantId), true))) {
            throw \app\common\http\ApiProblem::fromEnvelope('API 所属模块不可用', null, 40300);
        }
        if ($endpoint['access_mode'] === 'authenticated'
            && $this->accessRegistry->isAuthenticatedOnly((string) $request->method(), $path)) {
            return $next($request);
        }
        if ($endpoint['access_mode'] !== 'tenant_permission') {
            throw \app\common\http\ApiProblem::fromEnvelope('API 权限模式无效', null, 40300);
        }
        $decision = $this->authorization->decideApiForRequest(
                $request,
                $tenantContext,
                $endpoint,
                $this->apis->permissionKeys($endpoint),
            );
        if (!$decision->allowed) {
            throw \app\common\http\ApiProblem::fromEnvelope('暂无访问权限', null, 40300);
        }

        if (in_array(strtoupper((string) $request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $this->demoAccounts->mutationLocked($adminInfo, (string) ($request->rule()?->getOption('peanut_permission') ?? substr($path, strlen('adminapi/'))))) {
            throw \app\common\http\ApiProblem::fromEnvelope('演示账号已锁定关键配置和权限操作', null, 40300);
        }

        return $next($request);
    }
}
