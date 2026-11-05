<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
function rechargeSettingExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$root = dirname(__DIR__, 2);
$sources = [
  'settings_schema' => '/app/modules/peanut/settings/database/migrations/20261101010004_initial_settings_schema.php',
  'provider' => '/app/modules/peanut/settings/src/Infrastructure/ThinkPhpTenantSettingsProvider.php',
  'settings' => '/app/modules/peanut/settings/src/Service/TenantSettingService.php',
  'recharge' => '/app/modules/peanut/payment/src/Service/RechargeTenantSettingService.php',
  'admin' => '/app/modules/peanut/payment/src/Service/RechargeSettingApplicationService.php',
  'api' => '/app/modules/peanut/payment/src/Service/RechargeApplicationService.php',
];
foreach ($sources as $key => &$path) { $path = $root . $path; rechargeSettingExpect(is_file($path), 'missing actual owner: ' . $key); $path = (string) file_get_contents($path); } unset($path);
foreach (['tenant_id', 'namespace', 'config_json', 'uk_tenant_setting_namespace', 'fk_tenant_setting_tenant'] as $key) { rechargeSettingExpect(str_contains($sources['settings_schema'], $key), 'missing scoped tenant setting migration: ' . $key); }
foreach (['where(', 'tenant_id', 'namespace', 'Db::transaction(', 'lock(true)', 'revision'] as $key) { rechargeSettingExpect(str_contains($sources['provider'], $key), 'missing transactional scoped setting owner: ' . $key); }
rechargeSettingExpect(str_contains($sources['settings'], 'implements TenantSettingsQuery, TenantSettingsCommands'), 'settings contract no longer typed');
rechargeSettingExpect(\PeanutAdmin\Modules\Payment\Service\RechargeTenantSettingService::NAMESPACE === 'finance.recharge', 'recharge namespace lost identity');
foreach (['TenantSettingsQuery', 'TenantSettingsCommands', 'PaymentChannelGrantCommands', 'settingsQuery->get(', 'settingsCommands->replace(', 'channelGrants->channelConfigured('] as $key) { rechargeSettingExpect(str_contains($sources['recharge'], $key), 'payment service escaped contract: ' . $key); }
foreach (['admin','api'] as $key) { rechargeSettingExpect(str_contains($sources[$key], 'RechargeTenantSettingService') && !str_contains($sources[$key], 'ConfigService'), 'payment consumer uses global settings: ' . $key); }
echo "RECHARGE-TENANT-SETTING-001 passed; Module owner boundary source checks only; MySQL/HTTP qualified separately\n";
