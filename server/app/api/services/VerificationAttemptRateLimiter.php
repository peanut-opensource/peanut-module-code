<?php

declare(strict_types=1);

namespace app\api\services;

use app\common\exception\BusinessException;
use PeanutAdmin\Kernel\Context\TenantSystemContext;
use think\facade\Cache;
use think\facade\Config;

/**
 * 公开验证码入口的短窗口保护。
 *
 * 单次验证码是否耗尽由数据库行锁和 check_count 保证；这里仅在 File/Cache
 * 可用范围内按来源、租户、场景和手机号收紧连续错误请求，不能替代分布式风控。
 */
final class VerificationAttemptRateLimiter
{
    public const DEFAULT_MAX_FAILURES = 10;
    public const DEFAULT_WINDOW_SECONDS = 300;

    public function assertAllowed(TenantSystemContext $context, string $scene, string $mobile, string $source): void
    {
        $state = Cache::get($this->cacheKey($context, $scene, $mobile, $source));
        if (is_array($state)
            && (int) ($state['expires_at'] ?? 0) > time()
            && (int) ($state['count'] ?? 0) >= $this->maxFailures()) {
            throw new BusinessException('MEMBER_VERIFICATION_RATE_LIMITED', 429, '验证码尝试过于频繁，请稍后重试');
        }
    }

    public function recordFailure(TenantSystemContext $context, string $scene, string $mobile, string $source): int
    {
        $key = $this->cacheKey($context, $scene, $mobile, $source);
        $now = time();
        $state = Cache::get($key);
        if (!is_array($state) || (int) ($state['expires_at'] ?? 0) <= $now) {
            $state = ['count' => 0, 'expires_at' => $now + $this->windowSeconds()];
        }
        $state['count'] = (int) $state['count'] + 1;
        Cache::set($key, $state, max(1, (int) $state['expires_at'] - $now));
        return (int) $state['count'];
    }

    private function cacheKey(TenantSystemContext $context, string $scene, string $mobile, string $source): string
    {
        return 'application:v1:verification-entry:' . hash('sha256', implode("\0", [
            (string) $context->tenantId,
            $scene,
            $mobile,
            $source,
        ]));
    }

    private function maxFailures(): int
    {
        return max(1, (int) Config::get('notification.verification.entry_max_failed_attempts', self::DEFAULT_MAX_FAILURES));
    }

    private function windowSeconds(): int
    {
        return max(60, (int) Config::get('notification.verification.entry_window_seconds', self::DEFAULT_WINDOW_SECONDS));
    }
}
