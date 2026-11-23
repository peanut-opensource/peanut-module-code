<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContext;
use app\common\infrastructure\runtime\OperationalLog;
use think\Request;

/** Collects only counts and trusted identity for the current HTTP request. */
final class HttpRequestThresholds
{
    private int|float|null $started;
    private int $sqlCount = 0;
    private ?int $tenantId = null;

    public function __construct(
        private readonly int $slowRequestMs,
        private readonly int $maxSqlCount,
    ) {
        $this->started = hrtime(true);
    }

    public function start(): void
    {
        $this->started ??= hrtime(true);
    }

    public function recordQuery(string $sql): void
    {
        if ($this->started !== null && !str_starts_with($sql, 'CONNECT:')) {
            $this->sqlCount++;
        }
    }

    public function rememberContext(ExecutionContext $context): void
    {
        $this->tenantId = $context->tenantId();
    }

    public function finish(CurrentExecutionContext $executionContext, Request $request): void
    {
        if ($this->started === null) {
            return;
        }
        $durationMs = (hrtime(true) - $this->started) / 1_000_000;
        $sqlCount = $this->sqlCount;
        $tenantId = $this->tenantId;
        $this->started = null;
        $this->sqlCount = 0;
        $this->tenantId = null;

        if (($this->slowRequestMs > 0 && $durationMs > $this->slowRequestMs)
            || ($this->maxSqlCount > 0 && $sqlCount > $this->maxSqlCount)) {
            OperationalLog::warning($executionContext, 'http.request.threshold_exceeded', [
                'request_id' => RequestTrace::id($executionContext, $request),
                'tenant_id' => $tenantId,
                'duration_ms' => round($durationMs, 3),
                'sql_count' => $sqlCount,
                'threshold_ms' => $this->slowRequestMs,
                'threshold_sql_count' => $this->maxSqlCount,
            ]);
        }
    }
}
