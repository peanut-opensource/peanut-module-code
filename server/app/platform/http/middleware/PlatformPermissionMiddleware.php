<?php

declare(strict_types=1);

namespace app\platform\http\middleware;

use app\common\http\JsonResponseFactory;
use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use app\common\execution\CurrentExecutionContext;
use app\platform\context\PlatformOperatorContext;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;

final class PlatformPermissionMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $execution,
        private readonly PlatformOperatorSessionService $sessions,
        private readonly DemoAccountPolicy $demoAccounts,
    ) {}

    public function handle($request, \Closure $next, string $permission)
    {
        try {
            $context = $this->execution->platform();
        } catch (\Throwable) {
            $context = null;
        }
        if (!$context instanceof PlatformOperatorContext) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform authentication is required.', null, 40100);
        }
        if (!str_starts_with($permission, 'platform.')) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform permission boundary is invalid.', null, 40300);
        }

        try {
            $this->sessions->assertAllowed($context, $permission);
        } catch (AuthorizationException) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform permission is required.', null, 40300);
        }

        if (in_array(strtoupper((string) $request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ($this->demoAccounts->platformMutationLocked($context->core->accountId)) {
                throw \app\common\http\ApiProblem::fromEnvelope('演示账号已锁定平台权限和关键配置操作', null, 40300);
            }
        }

        return $next($request);
    }
}
