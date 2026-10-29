<?php

declare(strict_types=1);

namespace app\adminapi\http\middleware;

use app\adminapi\infrastructure\AdminApiAccessRegistry;
use app\common\dto\authorization\PermissionDecision;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\http\JsonResponseFactory;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use app\common\execution\AdminExecutionContext;
use app\common\execution\CurrentExecutionContext;

/**
 * 权限中间件（原生 TP 风格）
 *
 * 必须在路由级 LoginMiddleware 之后执行，从 CurrentExecutionContext 获取已验证人员；
 * 不从 Request 动态属性或客户端参数恢复可信身份。
 * 只有版本化 authenticated 元数据或启用的精确权限节点可以放行。
 * root 只绕过角色授权，不能绕过路由登记、登录、TenantContext 或身份边界。
 */
class AuthMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly AdminAuthorizationService $authorization,
        private readonly AdminApiAccessRegistry $accessRegistry,
        private readonly DemoAccountPolicy $demoAccounts,
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
        if ($this->accessRegistry->isAuthenticatedOnly((string) $request->method(), $path)) {
            return $next($request);
        }

        // REST 模块路由由服务器的匹配 Rule 声明权限；不能从请求参数读取。
        // 未声明的现有路由继续使用精确路径，所有权限仍须通过登记与租户授权检查。
        $routePermission = $request->rule()?->getOption('peanut_permission');
        if ($routePermission !== null && (!is_string($routePermission) || trim($routePermission) === '')) {
            throw \app\common\http\ApiProblem::fromEnvelope('路由权限配置无效', null, 40300);
        }
        $accessUri = $routePermission ?? substr($path, strlen('adminapi/'));

        $tenantContext = $current instanceof AdminExecutionContext ? $current->tenant : null;
        $decision = $tenantContext instanceof \PeanutAdmin\Kernel\Auth\TenantContext
            ? $this->authorization->decideForRequest(
                $request,
                $tenantContext,
                $accessUri,
            )
            : PermissionDecision::deny($accessUri, 'INVALID_TENANT_ADMIN_CONTEXT');
        if (!$decision->allowed) {
            throw \app\common\http\ApiProblem::fromEnvelope('暂无访问权限', null, 40300);
        }

        if (in_array(strtoupper((string) $request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $this->demoAccounts->mutationLocked($adminInfo, $accessUri)) {
            throw \app\common\http\ApiProblem::fromEnvelope('演示账号已锁定关键配置和权限操作', null, 40300);
        }

        return $next($request);
    }
}
