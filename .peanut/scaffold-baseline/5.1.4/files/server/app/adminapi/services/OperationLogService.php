<?php

declare(strict_types=1);

namespace app\adminapi\services;

use app\common\services\audit\AuditContractHost;
use app\common\policy\audit\RedactionPolicy;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Auth\TenantContext;

/** 管理端操作日志的唯一写入与脱敏入口。 */
final class OperationLogService
{
    public function __construct(private readonly AuditContractHost $audit) {}

    public function record(
        TenantContext $context,
        int $adminId,
        string $username,
        string $ip,
        string $uri,
        string $method,
        mixed $params,
        AuditOutcome $outcome = AuditOutcome::Success,
        ?string $reasonCode = null,
        int $httpStatus = 200,
    ): void {
        $this->audit->recordOperationLog(
            $context,
            $adminId,
            $username,
            $ip,
            $uri,
            $method,
            $params,
            $outcome,
            $reasonCode,
            $httpStatus,
        );
    }

    public static function serializeParams(mixed $params): string
    {
        return RedactionPolicy::encode($params);
    }

    public static function redactSensitive(mixed $value, string $key = ''): mixed
    {
        return RedactionPolicy::sanitize($value, $key);
    }
}
