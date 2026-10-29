<?php

declare(strict_types=1);

namespace app\adminapi\services\setting;

use app\common\model\setting\TransactionSetting;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\facade\Db;

/**
 * 交易设置 Logic
 *
 * 4 个配置项（每 Tenant 一行存于 pa_transaction_setting）：
 *   cancel_unpaid_orders       0关/1开：自动取消未付款订单
 *   cancel_unpaid_orders_times 自动取消时间（分钟）
 *   verification_orders        0关/1开：自动核销订单
 *   verification_orders_times  自动核销时间（小时）
 */
class TransactionSettingsApplicationService
{
    private const DEFAULTS = [
        'cancel_unpaid_orders' => 1,
        'cancel_unpaid_orders_times' => 30,
        'verification_orders' => 1,
        'verification_orders_times' => 24,
    ];

    public function getConfig(TenantContext $context): array
    {
        $setting = TransactionSetting::where([])->findOrEmpty();
        if ($setting->isEmpty()) {
            return self::DEFAULTS;
        }
        return [
            'cancel_unpaid_orders'       => (int) $setting->cancel_unpaid_orders,
            'cancel_unpaid_orders_times' => (int) $setting->cancel_unpaid_orders_times,
            'verification_orders'        => (int) $setting->verification_orders,
            'verification_orders_times'  => (int) $setting->verification_orders_times,
        ];
    }

    public function setConfig(TenantContext $context, array $params): void
    {
        unset($params['tenant_id']);
        $current = self::getConfig($context);
        $data = [
            'cancel_unpaid_orders' => (int) $params['cancel_unpaid_orders'],
            'cancel_unpaid_orders_times' => isset($params['cancel_unpaid_orders_times'])
                ? (int) $params['cancel_unpaid_orders_times']
                : $current['cancel_unpaid_orders_times'],
            'verification_orders' => (int) $params['verification_orders'],
            'verification_orders_times' => isset($params['verification_orders_times'])
                ? (int) $params['verification_orders_times']
                : $current['verification_orders_times'],
        ];
        Db::transaction(static function () use ($data): void {
            $setting = TransactionSetting::where([])->lock(true)->findOrEmpty();
            if ($setting->isEmpty()) {
                TransactionSetting::create($data);
                return;
            }
            $setting->save($data);
        });
    }
}
