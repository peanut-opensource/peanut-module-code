<?php

declare(strict_types=1);

namespace app\common\enum;

/**
 * 用户发起业务时所在终端。
 */
class UserTerminalEnum
{
    public const WECHAT_MINI_PROGRAM = 1;
    public const WECHAT_OFFICIAL_ACCOUNT = 2;
    public const H5 = 3;
    public const PC = 4;
    public const IOS = 5;
    public const ANDROID = 6;

    public const DESC = [
        self::WECHAT_MINI_PROGRAM => '微信小程序',
        self::WECHAT_OFFICIAL_ACCOUNT => '微信公众号',
        self::H5 => '手机H5',
        self::PC => '电脑PC',
        self::IOS => '苹果APP',
        self::ANDROID => '安卓APP',
    ];

    /** @return int[] */
    public static function all(): array
    {
        return array_keys(self::DESC);
    }

    public static function isValid(int $terminal): bool
    {
        return isset(self::DESC[$terminal]);
    }

    public static function getDesc(int $terminal): string
    {
        return self::DESC[$terminal] ?? '';
    }
}
