<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Model;

use app\common\enum\UserTerminalEnum;
use app\common\model\SharedModel;

/**
 * 充值支付终端与渠道场景。
 */
class PaymentScene extends SharedModel
{
    protected $name = 'payment_scene';

    public const PAY_WAY_WECHAT = 2;
    public const PAY_WAY_ALIPAY = 3;

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    /** @return array<int, int[]> */
    public static function allowedPayWays(): array
    {
        return [
            UserTerminalEnum::WECHAT_MINI_PROGRAM => [self::PAY_WAY_WECHAT],
            UserTerminalEnum::WECHAT_OFFICIAL_ACCOUNT => [self::PAY_WAY_WECHAT, self::PAY_WAY_ALIPAY],
            UserTerminalEnum::H5 => [self::PAY_WAY_WECHAT, self::PAY_WAY_ALIPAY],
            UserTerminalEnum::PC => [self::PAY_WAY_WECHAT, self::PAY_WAY_ALIPAY],
            UserTerminalEnum::IOS => [self::PAY_WAY_WECHAT, self::PAY_WAY_ALIPAY],
            UserTerminalEnum::ANDROID => [self::PAY_WAY_WECHAT, self::PAY_WAY_ALIPAY],
        ];
    }

    public static function supports(int $terminal, int $payWay): bool
    {
        return in_array($payWay, self::allowedPayWays()[$terminal] ?? [], true);
    }

    /** @return array<int, array{pay_way:int,is_default:int}> */
    public static function enabledPayWays(int $terminal): array
    {
        if (!UserTerminalEnum::isValid($terminal)) {
            return [];
        }

        $rows = self::where([
            'terminal' => $terminal,
            'status' => self::STATUS_ENABLED,
        ])->field('pay_way,is_default')
            ->order('is_default', 'desc')
            ->order('pay_way', 'asc')
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => [
            'pay_way' => (int) $row['pay_way'],
            'is_default' => (int) $row['is_default'],
        ], $rows);
    }

    public static function getPayWayDesc(int $payWay): string
    {
        return [
            self::PAY_WAY_WECHAT => '微信支付',
            self::PAY_WAY_ALIPAY => '支付宝支付',
        ][$payWay] ?? '';
    }
}
