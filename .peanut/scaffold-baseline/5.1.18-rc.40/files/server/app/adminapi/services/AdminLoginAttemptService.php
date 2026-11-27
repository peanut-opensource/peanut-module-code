<?php

declare(strict_types=1);

namespace app\adminapi\services;

use think\facade\Cache;

class AdminLoginAttemptService
{
    public function __construct(
        private readonly int $configuredMaxAttempts,
        private readonly int $configuredLockMinutes,
    ) {}

    public function isLocked(string $ip): bool
    {
        return (int) Cache::get(self::cacheKey($ip), 0) >= $this->maxAttempts();
    }

    public function recordFailure(string $ip): int
    {
        $key   = self::cacheKey($ip);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::tag('application:v1')->set($key, $count, $this->lockSeconds());
        return $count;
    }

    public function clear(string $ip): void
    {
        Cache::delete(self::cacheKey($ip));
    }

    public function lockedMessage(): string
    {
        return sprintf(
            '密码连续%d次输入错误，请%d分钟后重试',
            $this->maxAttempts(),
            $this->lockMinutes(),
        );
    }

    private static function cacheKey(string $ip): string
    {
        return 'application:v1:admin-login:' . hash('sha256', $ip);
    }

    private function maxAttempts(): int
    {
        return max(1, $this->configuredMaxAttempts);
    }

    private function lockMinutes(): int
    {
        return max(1, $this->configuredLockMinutes);
    }

    private function lockSeconds(): int
    {
        return $this->lockMinutes() * 60;
    }
}
