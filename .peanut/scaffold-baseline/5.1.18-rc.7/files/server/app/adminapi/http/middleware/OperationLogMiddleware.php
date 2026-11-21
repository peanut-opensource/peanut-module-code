<?php

declare(strict_types=1);

namespace app\adminapi\http\middleware;

use app\adminapi\services\OperationLogService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use app\common\execution\CurrentExecutionContext;
use app\common\http\ApiProblemMapper;
use app\common\infrastructure\runtime\OperationalLog;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Modules\Ops\Domain\Logs\TenantDiagnosticAttributes;

/**
 * 操作日志中间件（原生 TP 风格）
 *
 * 必须在路由级 LoginMiddleware 已建立 CurrentExecutionContext 的范围内执行；
 * finally 留痕必须先于外层登录中间件释放当前执行上下文。
 * 记录 POST/PUT/PATCH/DELETE；GET 等只读请求不入库。
 * finally 保证正常失败 envelope 与异常写请求都尝试留痕，日志失败不影响主流程。
 */
class OperationLogMiddleware
{
    /** 不记录的动作后缀（避免日志模块自我刷屏） */
    protected array $except = ['log/clear'];

    public function __construct(
        private readonly CurrentExecutionContext $executionContext,
        private readonly OperationLogService $operationLogs,
        private readonly ApiProblemMapper $problems,
    ) {}

    public function handle($request, \Closure $next)
    {
        try {
            $context = $this->executionContext->tenantAdmin();
        } catch (\Throwable $exception) {
            OperationalLog::warning(
                $this->executionContext,
                'operation_log_tenant_context_unavailable',
                TenantDiagnosticAttributes::fromTenantContext(null),
            );
            throw $exception;
        }
        $outcome = AuditOutcome::Success;
        $reasonCode = null;
        $httpStatus = 200;
        try {
            $response = $next($request);
            if (is_object($response) && method_exists($response, 'getCode')) {
                $httpStatus = (int) $response->getCode();
                if ($httpStatus >= 400) {
                    $outcome = in_array($httpStatus, [401, 403], true)
                        ? AuditOutcome::Denied
                        : AuditOutcome::Error;
                    $reasonCode = 'HTTP_' . $httpStatus;
                }
            }
            return $response;
        } catch (\Throwable $exception) {
            $problem = $this->problems->map($exception);
            $httpStatus = $problem?->httpStatus ?? 500;
            $outcome = in_array($httpStatus, [401, 403], true)
                ? AuditOutcome::Denied
                : AuditOutcome::Error;
            $reasonCode = $problem?->errorCode ?? 'UNHANDLED_EXCEPTION';
            throw $exception;
        } finally {
            if (in_array(strtoupper((string) $request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $this->record($context, $request, $outcome, $reasonCode, $httpStatus);
            }
        }
    }

    protected function record(
        TenantContext $context,
        $request,
        AuditOutcome $outcome,
        ?string $reasonCode,
        int $httpStatus,
    ): void {
        $uri = strtolower(trim($request->pathinfo(), '/'));
        foreach ($this->except as $skip) {
            if (str_ends_with($uri, $skip)) {
                return;
            }
        }

        $adminInfo = $this->executionContext->tenantAdminPrincipal();

        try {
            $this->operationLogs->record(
                $context,
                (int) ($adminInfo['id'] ?? 0),
                (string) ($adminInfo['username'] ?? ''),
                (string) $request->ip(),
                $uri,
                (string) $request->method(),
                $request->post(),
                $outcome,
                $reasonCode,
                $httpStatus,
            );
        } catch (\Throwable $exception) {
            OperationalLog::error(
                $this->executionContext,
                'operation_log_write_failed',
                TenantDiagnosticAttributes::fromTenantContext($context) + ['exception' => $exception::class],
            );
            // 记录日志失败不得影响主流程
        }
    }
}
