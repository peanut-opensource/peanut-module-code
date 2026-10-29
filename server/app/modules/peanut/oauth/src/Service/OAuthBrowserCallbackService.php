<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\OAuth\Service;

use PeanutAdmin\Modules\OAuth\Value\BrowserOAuthCallbackRoutes;

/** Browser callback bridge between WeChat and the product-specific PC/H5 routes. */
final class OAuthBrowserCallbackService
{
    private const CALLBACK_PATHS = [
        'oa' => '/api/oauth/wechat/redirect/official-account',
        'open_pc' => '/api/oauth/wechat/redirect/pc',
    ];

    private const CLIENT_PATHS = [
        'official-account' => '/mobile/#/pages/oauth/callback',
        'pc' => '/pc/oauth/callback',
    ];

    public static function callbackUrl(string $origin, string $scene): string
    {
        return self::routes()->callbackUrl($origin, $scene);
    }

    public static function clientRedirectUrl(string $client, array $query): string
    {
        return self::routes()->clientRedirectUrl($client, $query);
    }

    private static function routes(): BrowserOAuthCallbackRoutes
    {
        return new BrowserOAuthCallbackRoutes(
            self::CALLBACK_PATHS,
            self::CLIENT_PATHS,
            ['official-account' => ['scene' => 'oa']],
        );
    }
}
