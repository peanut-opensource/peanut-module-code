<?php

declare(strict_types=1);

namespace app\api\middleware;

use app\api\services\UserTokenService;
use PeanutAdmin\Modules\Member\Contract\MemberQueries;
use app\common\execution\ExecutionContextStore;
use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use app\common\http\JsonResponseFactory;
use app\common\infrastructure\member\MemberApiTenantContextResolver;

/**
 * 用户端登录中间件
 *
 * 挂载到需要登录的路由组上；未挂载的路由无需 token。
 */
class CheckTokenMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly MemberQueries $members,
        private readonly ExecutionContextStore $executionContexts,
        private readonly MemberApiTenantContextResolver $tenantContexts,
        private readonly UserTokenService $tokens,
    ) {}

    public function handle($request, \Closure $next)
    {
        $token = self::bearerToken((string) $request->header('Authorization', ''));

        if (empty($token)) {
            throw \app\common\http\ApiProblem::fromEnvelope('请求缺少 token', null, 40100);
        }

        try {
            $verifiedToken = $this->tokens->parseSessionToken($token);
            $memberId = $verifiedToken['member_id'];
        } catch (\UnexpectedValueException) {
            throw \app\common\http\ApiProblem::fromEnvelope('登录超时，请重新登录', null, 40100);
        }

        try {
            $requestId = RequestTrace::id($this->executionContext, $request, 'member');
            $memberContext = $this->tenantContexts->resolve(
                $memberId,
                $token,
                $requestId,
                $verifiedToken['tenant_id'],
            );
        } catch (\Throwable) {
            throw \app\common\http\ApiProblem::fromEnvelope('租户上下文不可用', null, 40300);
        }

        return $this->executionContexts->run(
            \app\common\execution\ConsumerExecutionContext::member($memberContext, sprintf(
                'http.member.%s.%s',
                strtolower((string) $request->method()),
                trim((string) $request->pathinfo(), '/'),
            )),
            function () use ($memberContext, $memberId, $next, $request) {
                $member = $this->members->identity($memberContext, $memberId);
                if ($member === null) {
                    throw \app\common\http\ApiProblem::fromEnvelope('账号不存在', null, 40100);
                }
                if (!$member->status) {
                    throw \app\common\http\ApiProblem::fromEnvelope('账号已被禁用', null, 40300);
                }

                return $next($request);
            },
        );
    }

    private static function bearerToken(string $authorization): string
    {
        return preg_match(
            '/^Bearer +([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD',
            $authorization,
            $matches,
        ) === 1 ? $matches[1] : '';
    }

}
