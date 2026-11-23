<?php

declare(strict_types=1);

namespace app\common\enum\decoration;

class DecorationEnum
{
    public const MOBILE_HOME = 1;
    public const MOBILE_PROFILE = 2;
    public const MOBILE_CUSTOMER_SERVICE = 3;
    public const PC_HOME = 4;
    public const SYSTEM_THEME = 5;

    public const MOBILE_TYPES = [self::MOBILE_HOME, self::MOBILE_PROFILE, self::MOBILE_CUSTOMER_SERVICE, self::SYSTEM_THEME];
    public const ALL_TYPES = [self::MOBILE_HOME, self::MOBILE_PROFILE, self::MOBILE_CUSTOMER_SERVICE, self::PC_HOME, self::SYSTEM_THEME];
}
