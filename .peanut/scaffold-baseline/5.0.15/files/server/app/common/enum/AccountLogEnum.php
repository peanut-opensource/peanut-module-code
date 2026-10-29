<?php

declare(strict_types=1);

namespace app\common\enum;

/**
 * 用户账户流水枚举。
 *
 * 变动类型按「对象_动作_业务来源」划分，和 LikeAdmin 1.9.4 保持一致。
 */
class AccountLogEnum
{
    public const USER_MONEY = 1;

    public const INC = 1;
    public const DEC = 2;

    public const USER_MONEY_DEC_ADMIN = 100;
    public const USER_MONEY_DEC_RECHARGE_REFUND = 101;
    public const USER_MONEY_INC_ADMIN = 200;
    public const USER_MONEY_INC_RECHARGE = 201;

    public const USER_MONEY_DEC = [
        self::USER_MONEY_DEC_ADMIN,
        self::USER_MONEY_DEC_RECHARGE_REFUND,
    ];

    public const USER_MONEY_INC = [
        self::USER_MONEY_INC_ADMIN,
        self::USER_MONEY_INC_RECHARGE,
    ];

    public static function getActionDesc(int $action): string
    {
        return [
            self::DEC => '减少',
            self::INC => '增加',
        ][$action] ?? '';
    }

    public static function getChangeTypeDesc(int $changeType): string
    {
        return [
            self::USER_MONEY_DEC_ADMIN => '平台减少余额',
            self::USER_MONEY_DEC_RECHARGE_REFUND => '充值订单退款减少余额',
            self::USER_MONEY_INC_ADMIN => '平台增加余额',
            self::USER_MONEY_INC_RECHARGE => '充值增加余额',
        ][$changeType] ?? '';
    }

    /** @return array<int,string> */
    public static function getUserMoneyChangeTypeDesc(): array
    {
        $descriptions = [];
        foreach (self::getUserMoneyChangeTypes() as $changeType) {
            $descriptions[$changeType] = self::getChangeTypeDesc($changeType);
        }
        return $descriptions;
    }

    /** @return int[] */
    public static function getUserMoneyChangeTypes(): array
    {
        return array_merge(self::USER_MONEY_DEC, self::USER_MONEY_INC);
    }

    public static function getChangeObject(int $changeType): int|false
    {
        return in_array($changeType, self::getUserMoneyChangeTypes(), true)
            ? self::USER_MONEY
            : false;
    }
}
