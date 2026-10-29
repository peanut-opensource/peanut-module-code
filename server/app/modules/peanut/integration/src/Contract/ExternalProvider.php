<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Integration\Contract;

final class ExternalProvider
{
    public const WECHAT_PAYMENT = 'payment.wechat';
    public const ALIPAY_PAYMENT = 'payment.alipay';
    public const WECHAT_OFFICIAL_CALLBACK = 'wechat.official-account';
    public const WECHAT_OFFICIAL_OAUTH = 'oauth.wechat.oa';
    public const WECHAT_OPEN_PLATFORM = 'oauth.wechat.open-pc';
    public const WECHAT_MINI_PROGRAM = 'oauth.wechat.mini-program';

    public static function oauth(string $scene): string
    {
        return match ($scene) {
            'mnp' => self::WECHAT_MINI_PROGRAM,
            'oa' => self::WECHAT_OFFICIAL_OAUTH,
            'open_pc' => self::WECHAT_OPEN_PLATFORM,
            default => throw new ExternalTenantResolutionException(),
        };
    }

    private function __construct() {}
}
