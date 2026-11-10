<?php

declare(strict_types=1);

namespace app\platform\http;

use app\common\execution\CurrentExecutionContext;
use app\common\http\RequestTrace;
use PeanutAdmin\Kernel\Auth\PlatformRefreshCookie;
use app\common\http\SessionRefreshCookie;

final class PlatformRequest
{
    public static function bearerToken($request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(\S+)$/iD', $authorization, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    public static function refreshToken($request): string
    {
        $accessToken = self::bearerToken($request);
        if ($accessToken === '') {
            return '';
        }
        return trim((string) $request->cookie(SessionRefreshCookie::name(PlatformRefreshCookie::NAME, $accessToken), ''));
    }

    public static function requestId(CurrentExecutionContext $executionContext, $request): string
    {
        return RequestTrace::id($executionContext, $request, 'platform');
    }

    private function __construct() {}
}
