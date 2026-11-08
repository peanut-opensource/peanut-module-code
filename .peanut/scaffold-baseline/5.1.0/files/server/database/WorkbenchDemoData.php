<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Context\TenantSystemContext;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsCommands;
use PeanutAdmin\Modules\Settings\Contract\TenantSettingsQuery;

/** Optional synthetic statistics, written only by the fresh installer under its database lock. */
final class WorkbenchDemoData
{
    public const NAMESPACE = 'application.workbench-demo';

    public static function plan(): array
    {
        return [
            'preset' => 'workbench',
            'today' => [
                'time' => date('Y-m-d H:i:s'),
                'today_sales' => 100, 'total_sales' => 1000,
                'today_visitor' => 10, 'total_visitor' => 100,
                'today_new_user' => 30, 'total_new_user' => 3000,
                'order_num' => 12, 'order_sum' => 255,
            ],
            'visitor' => [
                'date' => array_map(static fn(int $day): string => date('m/d', strtotime("-{$day} day")), range(14, 0)),
                'list' => [['name' => 'Demo 访客数', 'data' => [12, 18, 15, 24, 30, 28, 42, 38, 46, 52, 48, 64, 70, 66, 75]]],
            ],
            'sale' => [
                'date' => array_map(static fn(int $day): string => date('m/d', strtotime("-{$day} day")), range(6, 0)),
                'list' => [['name' => 'Demo 销售量', 'data' => [45, 72, 56, 90, 110, 84, 100]]],
            ],
        ];
    }

    public static function seed(\think\App $app, int $tenantId): void
    {
        $preset = (string) $app->config->get('peanut.demo.preset', 'none');
        if ($preset === 'none') {
            return;
        }
        if ($preset !== 'workbench') {
            throw new RuntimeException('Unsupported Demo preset.');
        }
        $context = new TenantSystemContext($tenantId, 'fresh-install', 'installation.demo', 'installation-demo');
        $query = $app->make(TenantSettingsQuery::class);
        if ($query->get($context, self::NAMESPACE)->revision !== 0) {
            throw new RuntimeException('DEMO_DATA_PRESENT: refusing to overwrite existing Demo data.');
        }
        $app->make(TenantSettingsCommands::class)->replace($context, self::NAMESPACE, self::plan());
    }
}
