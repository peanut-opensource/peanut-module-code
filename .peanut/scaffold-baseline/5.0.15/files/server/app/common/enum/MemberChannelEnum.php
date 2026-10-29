<?php

declare(strict_types=1);

namespace app\common\enum;

class MemberChannelEnum
{
    public const WECHAT_MMP = 1;
    public const WECHAT_OA = 2;
    public const H5 = 3;
    public const PC = 4;
    public const IOS = 5;
    public const ANDROID = 6;

    public const DESC = [
        self::WECHAT_MMP => '微信小程序',
        self::WECHAT_OA => '微信公众号',
        self::H5 => '手机H5',
        self::PC => '电脑PC',
        self::IOS => '苹果APP',
        self::ANDROID => '安卓APP',
    ];

    public static function getDesc(int $channel): string
    {
        return self::DESC[$channel] ?? '';
    }
}
