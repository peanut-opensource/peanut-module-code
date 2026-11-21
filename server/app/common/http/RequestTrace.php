<?php

declare(strict_types=1);

namespace app\common\http;

use app\common\execution\CurrentExecutionContext;
use PeanutAdmin\Kernel\Support\TraceId;
use think\Response;

/** One stable request ID without mutating the framework Request object. */
final class RequestTrace
{
    /** @var \WeakMap<object,string>|null */
    private static ?\WeakMap $requestIds = null;
    /** @var array{request_id:string,count:int,sql_seconds:float,deployment_read_calls:int,deployment_read_seconds:float,registry_restore_calls:int,registry_restore_seconds:float,coverage_start:string,started_at_nanoseconds:int|float}|null */
    private static ?array $sqlTiming = null;

    /** FPM creates its final Request after AppService registration; retain only this selected request's scalar totals. */
    public static function beginSqlTiming(string $requestId): bool
    {
        if (PHP_SAPI !== 'fpm-fcgi' || self::$sqlTiming !== null
            || !TraceId::acceptsHeaderValue($requestId)) {
            return false;
        }
        self::$sqlTiming = [
            'request_id' => $requestId,
            'count' => 0,
            'sql_seconds' => 0.0,
            'deployment_read_calls' => 0,
            'deployment_read_seconds' => 0.0,
            'registry_restore_calls' => 0,
            'registry_restore_seconds' => 0.0,
            'coverage_start' => 'AppService.register.before_composition',
            'started_at_nanoseconds' => hrtime(true),
        ];
        return true;
    }

    /** No clock or context lookup for unselected requests; these stages may overlap SQL totals. */
    public static function beginDeploymentTiming(): int|float|null
    {
        return self::$sqlTiming === null ? null : hrtime(true);
    }

    /** Aggregate only scalars, including when deployment admission throws. */
    public static function finishDeploymentReadTiming(int|float|null $started): void
    {
        if ($started === null || self::$sqlTiming === null) {
            return;
        }
        self::$sqlTiming['deployment_read_calls'] += 1;
        self::$sqlTiming['deployment_read_seconds'] += (hrtime(true) - $started) / 1_000_000_000;
    }

    /** Aggregate only scalars; registry restoration is not a mutually exclusive SQL span. */
    public static function finishRegistryRestoreTiming(int|float|null $started): void
    {
        if ($started === null || self::$sqlTiming === null) {
            return;
        }
        self::$sqlTiming['registry_restore_calls'] += 1;
        self::$sqlTiming['registry_restore_seconds'] += (hrtime(true) - $started) / 1_000_000_000;
    }

    /** No SQL or parameters leave this callback; native timing is prepare/bind/execute seconds, before result fetching. */
    public static function addSqlTime(mixed $sql, mixed $seconds): void
    {
        if (self::$sqlTiming === null || !is_string($sql) || str_starts_with($sql, 'CONNECT:')
            || (!is_string($seconds) && !is_int($seconds) && !is_float($seconds)) || !is_numeric($seconds)) {
            return;
        }
        $seconds = (float) $seconds;
        if (!is_finite($seconds) || $seconds < 0) {
            return;
        }
        self::$sqlTiming['count'] += 1;
        self::$sqlTiming['sql_seconds'] += $seconds;
    }

    /** @return array{request_id:string,count:int,sql_seconds:float,deployment_read_calls:int,deployment_read_seconds:float,registry_restore_calls:int,registry_restore_seconds:float,coverage_start:string,coverage_elapsed_seconds:float}|null */
    public static function finishSqlTiming(object $request): ?array
    {
        $timing = self::$sqlTiming;
        self::$sqlTiming = null;
        if ($timing === null || !method_exists($request, 'header')) {
            return null;
        }
        $header = trim((string) $request->header('X-Request-Id', ''));
        $known = self::map()[$request] ?? null;
        if (!hash_equals($timing['request_id'], $header) || ($known !== null && $known !== $timing['request_id'])) {
            return null;
        }
        self::remember($request, $timing['request_id']);
        $timing['coverage_elapsed_seconds'] = (hrtime(true) - $timing['started_at_nanoseconds']) / 1_000_000_000;
        unset($timing['started_at_nanoseconds']);
        return $timing;
    }

    public static function id(
        CurrentExecutionContext $executionContext,
        object $request,
        string $prefix = 'http',
    ): string {
        $candidate = method_exists($request, 'header')
            ? trim((string) $request->header('X-Request-Id', ''))
            : '';
        if (TraceId::acceptsHeaderValue($candidate)) {
            return self::remember($request, $candidate);
        }

        try {
            $current = $executionContext->current();
        } catch (\Throwable) {
            $current = null;
        }
        if ($current !== null) {
            return self::remember($request, $current->requestId());
        }

        $known = self::map()[$request] ?? null;
        if (is_string($known)) {
            return $known;
        }

        return self::remember($request, TraceId::generate($prefix));
    }

    /** Expose correlation without changing the origin's CORS policy. */
    public static function attach(Response $response, string $requestId): Response
    {
        $exposeName = 'Access-Control-Expose-Headers';
        $exposed = [];
        foreach ($response->getHeader() as $name => $value) {
            if (strcasecmp($name, $exposeName) === 0) {
                $exposeName = $name;
                $exposed = array_filter(array_map('trim', explode(',', (string) $value)));
                break;
            }
        }
        if (!in_array('x-trace-id', array_map('strtolower', $exposed), true)) {
            $exposed[] = 'X-Trace-Id';
        }
        return $response->header([
            'X-Request-Id' => $requestId,
            'X-Trace-Id' => $requestId,
            $exposeName => implode(', ', $exposed),
        ]);
    }

    /** @return \WeakMap<object,string> */
    private static function map(): \WeakMap
    {
        return self::$requestIds ??= new \WeakMap();
    }

    private static function remember(object $request, string $requestId): string
    {
        self::map()[$request] = $requestId;
        return $requestId;
    }

    private function __construct() {}
}
