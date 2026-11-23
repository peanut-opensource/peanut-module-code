<?php

declare(strict_types=1);

namespace app\adminapi\http;

/** Compatibility request-token parser; native TenantAuth owns token lifecycle. */
final class AdminTokenExtractor
{
    public static function tokenFromRequest($request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }
        return trim((string) $request->header('token', ''));
    }

    private function __construct() {}
}
