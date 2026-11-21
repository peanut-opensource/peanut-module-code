<?php

declare(strict_types=1);

namespace app\common\value\http;

use app\common\execution\CurrentExecutionContext;
use app\common\infrastructure\runtime\OperationalLog;
use GuzzleHttp\Exception\ConnectException;

/** Secret-free per-attempt observation shared by application-owned HTTP retries. */
final class OutboundHttpAttemptObservation
{
    public static function response(
        CurrentExecutionContext $executionContext,
        string $method,
        string $url,
        int $attempt,
        int $startedAt,
        int $status,
    ): void {
        $category = match (true) {
            $status < 400 => 'success',
            $status < 500 => 'http_4xx',
            default => 'http_5xx',
        };
        self::write($executionContext, $method, $url, $attempt, $startedAt, $category, $status);
    }

    public static function failure(
        CurrentExecutionContext $executionContext,
        string $method,
        string $url,
        int $attempt,
        int $startedAt,
        \Throwable $exception,
    ): void {
        $category = $exception instanceof ConnectException
            && ((int) ($exception->getHandlerContext()['errno'] ?? 0) === 28
                || str_contains(strtolower($exception->getMessage()), 'timed out'))
            ? 'timeout'
            : 'transport';
        self::write($executionContext, $method, $url, $attempt, $startedAt, $category, null, $exception);
    }

    private static function write(
        CurrentExecutionContext $executionContext,
        string $method,
        string $url,
        int $attempt,
        int $startedAt,
        string $category,
        ?int $status = null,
        ?\Throwable $exception = null,
    ): void {
        $attributes = [
            'method' => strtoupper($method),
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?: 'unknown'),
            'attempt' => $attempt,
            'duration_ms' => max(0, intdiv(hrtime(true) - $startedAt, 1_000_000)),
            'outcome' => $category === 'success' ? 'success' : 'failed',
            'category' => $category,
        ];
        if ($status !== null) {
            $attributes['status'] = $status;
        }
        if ($exception !== null) {
            $attributes['exception'] = $exception::class;
        }
        OperationalLog::warning($executionContext, 'outbound_http_attempt', $attributes);
    }

    private function __construct() {}
}
