<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Payment\Service;

final class PaymentBootstrapDefaults
{
    /** @return list<array<string, int|string>> */
    public static function tasks(): array
    {
        return [[
            'name' => '退款状态收敛',
            'type' => 1,
            'command' => 'refund:reconcile',
            'params' => '',
            'status' => 1,
            'expression' => '* * * * *',
            'error' => '',
            'last_time' => 0,
            'time' => 0,
            'max_time' => 0,
            'sort' => 100,
            'remark' => '查询支付渠道并收敛充值退款最终状态',
            'create_time' => 0,
            'update_time' => 0,
        ]];
    }

    private function __construct() {}
}
