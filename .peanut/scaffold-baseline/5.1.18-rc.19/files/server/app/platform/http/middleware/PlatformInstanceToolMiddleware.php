<?php

declare(strict_types=1);

namespace app\platform\http\middleware;

use app\common\validation\instance\InstanceToolAccessGuard;
use app\common\execution\CurrentExecutionContext;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use think\facade\Config;

/** Environment/deployment gate applied after Platform authentication and exact permission checks. */
final class PlatformInstanceToolMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $execution,
        private readonly PlatformOperatorSessionService $sessions,
    ) {}

    public function handle($request, \Closure $next)
    {
        $permission = InstanceToolAccessGuard::fromConfiguredValue(Config::get('deployment.mode'))
            ->platformModulePermission($this->execution);
        if (strtolower(trim((string) Config::get('peanut.environment', ''))) !== 'development'
            || !app()->isDebug()
            || $permission === null) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                'Runtime Module mutation is disabled.',
                ['error_code' => 'MODULE_RUNTIME_MUTATION_DISABLED'],
                40300,
            );
        }
        try {
            $this->sessions->assertAllowed($this->execution->platform(), $permission);
        } catch (AuthorizationException) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform permission is required.', null, 40300);
        }
        return $next($request);
    }
}
