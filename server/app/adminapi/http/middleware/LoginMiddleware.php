<?php

declare(strict_types=1);

namespace app\adminapi\http\middleware;

use app\common\execution\CurrentExecutionContext;
use app\adminapi\http\AdminTokenExtractor;
use app\common\execution\ExecutionContextStore;
use app\common\http\RequestTrace;
use app\common\services\authorization\AdminAuthorizationService;
use app\common\http\JsonResponseFactory;
use PeanutAdmin\Modules\Identity\Auth\TenantAuthService;
use PeanutAdmin\Kernel\Host\ApplicationHostPolicy;
use PeanutAdmin\Kernel\Tenancy\TenantEntryBindingResolver;

/** Establishes management identity only from a validated native Tenant session. */
final class LoginMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly TenantAuthService $tenantAuth,
        private readonly ExecutionContextStore $executionContexts,
        private readonly AdminAuthorizationService $authorization,
        private readonly ApplicationHostPolicy $hostPolicy,
        private readonly TenantEntryBindingResolver $entryBindings,
    ) {}

    public function handle($request, \Closure $next)
    {
        $token = AdminTokenExtractor::tokenFromRequest($request);
        if ($token === '') {
            throw \app\common\http\ApiProblem::fromEnvelope('请求缺少 token', null, 40100);
        }
        if (!str_starts_with($token, 'pa_tat_')) {
            throw \app\common\http\ApiProblem::fromEnvelope('登录超时，请重新登录', null, 40100);
        }

        try {
            $this->hostPolicy->assertTenantAdmin($request);
            $context = $this->tenantAuth->context(
                $token,
                RequestTrace::id($this->executionContext, $request, 'admin'),
            );
            $entryBindings = $this->entryBindings;
            $entryBindings->assertTenantAccess(
                $request,
                TenantEntryBindingResolver::ADMIN_CLIENT,
                $context->tenantId,
            );
            $principal = $this->authorization->principalForRequest($request, $context)->toArray();
            $principal['terminal'] = 1;
            $entryBound = $entryBindings->boundTenantId(
                $request,
                TenantEntryBindingResolver::ADMIN_CLIENT,
            ) !== null;
        } catch (\DomainException $exception) {
            // Only entry/identity policy rejections belong to this 403 envelope.
            // AuthException and infrastructure failures retain the standard mapping.
            $bindingPolicyRejection = $exception->getMessage() === 'TENANT_ENTRY_BINDING_UNAVAILABLE'
                && $exception->getPrevious() === null;
            if (!$bindingPolicyRejection && !in_array($exception->getMessage(), [
                'TENANT_ADMIN_HOST_UNAVAILABLE',
                'TENANT_ADMIN_HOST_FORBIDDEN',
                'TENANT_ENTRY_HOST_INVALID',
                'TENANT_ENTRY_BINDING_CONFLICT',
                'TENANT_ADMIN_PRINCIPAL_UNAVAILABLE',
            ], true)) {
                throw $exception;
            }
            throw \app\common\http\ApiProblem::fromEnvelope('租户会话不可用', null, 40300);
        }

        $operation = sprintf(
            'http.admin.%s.%s',
            strtolower((string) $request->method()),
            trim((string) $request->pathinfo(), '/'),
        );
        return $this->executionContexts->run(
            new \app\common\execution\AdminExecutionContext($context, $operation, $principal, $entryBound),
            static fn() => $next($request),
        );
    }
}
