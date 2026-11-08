<?php

declare(strict_types=1);

namespace app\common\infrastructure\runtime;

use app\common\value\runtime\RuntimeNamespace;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\AdminExecutionContext;
use app\common\execution\ConsumerExecutionContext;
use app\common\execution\InstallationExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\execution\PlatformExecutionContext;
use app\common\execution\SystemExecutionContext;
use app\common\policy\audit\RedactionPolicy;
use think\facade\Log;

/** Adds execution trace and redaction to every application-owned runtime log. */
final class OperationalLog
{
    /** @param array<string,mixed> $attributes */
    public static function info(
        CurrentExecutionContext $executionContext,
        string $event,
        array $attributes = [],
    ): void {
        self::write($executionContext, 'info', $event, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public static function notice(
        CurrentExecutionContext $executionContext,
        string $event,
        array $attributes = [],
    ): void {
        self::write($executionContext, 'notice', $event, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public static function warning(
        CurrentExecutionContext $executionContext,
        string $event,
        array $attributes = [],
    ): void {
        self::write($executionContext, 'warning', $event, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public static function error(
        CurrentExecutionContext $executionContext,
        string $event,
        array $attributes = [],
    ): void {
        self::write($executionContext, 'error', $event, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    private static function write(
        CurrentExecutionContext $executionContext,
        string $level,
        string $event,
        array $attributes,
    ): void {
        try {
            Log::$level(RedactionPolicy::encode([
                'event' => self::event($event),
                'attributes' => self::attributes($executionContext, $attributes),
            ]));
        } catch (\Throwable) {
            // Runtime diagnostics must never replace the product operation.
        }
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private static function attributes(CurrentExecutionContext $executionContext, array $attributes): array
    {
        try {
            $context = $executionContext->current();
        } catch (\Throwable) {
            $context = null;
        }
        $trace = $context === null ? [] : [
            'request_id' => $context->requestId(),
            'operation' => $context->operation(),
            'audience' => match (true) {
                $context instanceof AdminExecutionContext => 'adminapi',
                $context instanceof ConsumerExecutionContext => 'api',
                $context instanceof PlatformExecutionContext => 'platform',
                $context instanceof InstallationExecutionContext => 'installation',
                $context instanceof SystemExecutionContext => 'system',
                $context instanceof InstanceExecutionContext => 'instance',
            },
            'tenant_id' => $context->tenantId(),
        ] + ($context instanceof SystemExecutionContext ? $context->metadata->toArray() : []);
        $trace['runtime_id'] = RuntimeNamespace::fromConfiguration()->fingerprint();

        $sanitized = RedactionPolicy::sanitize($trace + $attributes);
        return is_array($sanitized) ? $sanitized : [];
    }

    private static function event(string $event): string
    {
        $event = trim($event);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $event) !== 1) {
            throw new \InvalidArgumentException('OPERATIONAL_LOG_EVENT_INVALID');
        }
        return $event;
    }

    private function __construct() {}
}
