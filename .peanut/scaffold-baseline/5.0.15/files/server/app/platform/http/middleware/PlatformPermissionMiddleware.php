<?php

declare(strict_types=1);

namespace app\platform\http\middleware;

use PeanutAdmin\Modules\Identity\Policy\DemoAccountPolicy;
use app\common\execution\CurrentExecutionContext;
use app\platform\context\PlatformOperatorContext;
use app\platform\services\PlatformOperatorSessionService;
use PeanutAdmin\Modules\Identity\Authorization\ApiPermissionCatalog;
use app\common\services\authorization\EndpointPermissionGrant;
use app\common\contract\module\ModuleQualificationQuery;

final class PlatformPermissionMiddleware
{
    public function __construct(
        private readonly CurrentExecutionContext $execution,
        private readonly PlatformOperatorSessionService $sessions,
        private readonly DemoAccountPolicy $demoAccounts,
        private readonly ApiPermissionCatalog $apis,
        private readonly EndpointPermissionGrant $grant,
        private readonly ModuleQualificationQuery $modules,
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

        $endpoint = $this->apis->endpoint($request, 'platform');
        if ($endpoint === null || $endpoint['access_mode'] !== 'platform_permission'
            || !$this->apis->moduleAvailable($endpoint, $this->modules)) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform API is unavailable.', null, 40300);
        }
        $prerequisites = json_decode($endpoint['prerequisite_permission_keys'], true, 32, JSON_THROW_ON_ERROR);
        $primary = json_decode($endpoint['default_permission_keys'], true, 32, JSON_THROW_ON_ERROR);
        if (!in_array($permission, [...$primary, ...$prerequisites], true)) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform route permission metadata changed; synchronize the catalogue.', null, 40300);
        }
        $permissions = $this->sessions->permissionKeys($context);
        if (array_diff($prerequisites, $permissions) !== []) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform prerequisite permission is required.', null, 40300);
        }
        $allowed = array_intersect($this->apis->permissionKeys($endpoint), $permissions) !== [];
        if (!$allowed) {
            throw \app\common\http\ApiProblem::fromEnvelope('Platform permission is required.', null, 40300);
        }
        $this->grant->record($context->core, $endpoint);

        if (in_array(strtoupper((string) $request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ($this->demoAccounts->platformMutationLocked($context->core->accountId)) {
                throw \app\common\http\ApiProblem::fromEnvelope('演示账号已锁定平台权限和关键配置操作', null, 40300);
            }
        }

        return $next($request);
    }
}
