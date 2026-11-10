<?php

declare(strict_types=1);

namespace app\common\http\middleware;

use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use app\common\services\audit\AuditContractHost;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Ops\Contract\InstanceSafetyQueries;

/** Fails closed for every HTTP mutation while an active maintenance window is in effect. */
final class MaintenanceWriteGateMiddleware
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly AuditContractHost $audit,
        private readonly CurrentExecutionContext $executionContext,
        private readonly InstanceSafetyQueries $instanceSafety,
    ) {}

    public function handle($request, \Closure $next)
    {
        if (!in_array(strtoupper((string) $request->method()), self::WRITE_METHODS, true)
            || $this->isMaintenanceControlRequest($request)
        ) {
            return $next($request);
        }

        $requestId = RequestTrace::id($this->executionContext, $request, 'maintenance');
        try {
            $window = $this->instanceSafety->blockingMaintenanceWindow();
            if ($window !== null) {
                $this->audit->recordPlatform(
                    'platform.maintenance.write-blocked',
                    'maintenance.write',
                    $requestId,
                    null,
                    null,
                    [
                        'maintenance_key' => (string) $window['maintenance_key'],
                        'reason_key' => (string) $window['reason_key'],
                        'request_method' => strtoupper((string) $request->method()),
                        'request_path' => trim((string) $request->pathinfo(), '/'),
                    ],
                    AuditOutcome::Denied,
                    'MAINTENANCE_WRITE_BLOCKED',
                );
            }
        } catch (\Throwable) {
            throw \app\common\http\ApiProblem::fromEnvelope(
                '系统维护状态不可用，写入操作已拒绝。',
                ['error_code' => 'MAINTENANCE_GATE_UNAVAILABLE'],
                50300,
            )->withHeaders(['Cache-Control' => 'no-store', 'X-Request-Id' => $requestId]);
        }

        if ($window === null) {
            return $next($request);
        }

        throw \app\common\http\ApiProblem::fromEnvelope(
            '系统维护中，暂不支持写入操作。',
            ['error_code' => 'MAINTENANCE_WRITE_BLOCKED'],
            50300,
        )->withHeaders(['Cache-Control' => 'no-store', 'X-Request-Id' => $requestId]);
    }

    private function isMaintenanceControlRequest($request): bool
    {
        $method = strtoupper((string) $request->method());
        $path = trim((string) $request->pathinfo(), '/');
        return ($method === 'PUT' && $path === 'v1/ops/maintenance')
            || ($method === 'POST'
                && preg_match('#^v1/ops/maintenance/maintenance_[a-f0-9]{32}/close$#D', $path) === 1);
    }
}
