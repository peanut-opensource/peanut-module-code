<?php

declare(strict_types=1);

use app\api\services\VerificationAttemptRateLimiter;
use PeanutAdmin\Modules\Notification\Service\VerificationCodeService;

return [
    'verification' => [
        // 每次签发的验证码最多允许的错误核验次数；达到上限后只能重新获取验证码。
        'max_failed_attempts' => max(1, (int) env('NOTIFICATION_VERIFICATION_MAX_FAILURES', VerificationCodeService::DEFAULT_MAX_FAILED_ATTEMPTS)),
        // 公开登录／重置入口在同一来源、租户、场景和手机号维度内的错误核验上限。
        'entry_max_failed_attempts' => max(1, (int) env('NOTIFICATION_VERIFICATION_ENTRY_MAX_FAILURES', VerificationAttemptRateLimiter::DEFAULT_MAX_FAILURES)),
        // 公开入口错误核验的固定窗口（秒），用于限制新验证码后的连续试错。
        'entry_window_seconds' => max(60, (int) env('NOTIFICATION_VERIFICATION_ENTRY_WINDOW_SECONDS', VerificationAttemptRateLimiter::DEFAULT_WINDOW_SECONDS)),
    ],
];
