<?php

declare(strict_types=1);

use app\common\value\installation\ApplicationSourceIdentity;

$roots = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('PEANUT_MODULE_ROOTS', '')),
)));

$serverRoot = dirname(__DIR__);
$serverIdentity = $serverRoot . '/.peanut/release-identity.json';
// Source/generated applications own plugins.lock at the application root.
// Server releases and development runtimes use their verified backend-only projection.
$serverRelease = file_exists($serverIdentity) || is_link($serverIdentity);
$pluginLockDefault = $serverRelease || ApplicationSourceIdentity::hasRuntime($serverRoot)
    ? 'plugins.lock'
    : '../plugins.lock';

return [
    // Module roots are an explicit deployment input. An empty list keeps the
    // platform control plane available while TenantModule management fails closed.
    'roots' => $roots,
    'plugin_lock' => (string) env('PEANUT_PLUGIN_LOCK', $pluginLockDefault),
    'kernel_version' => (string) env('PEANUT_MODULE_KERNEL_VERSION', '1.0.0'),
    'registered_client_keys' => ['admin-web', 'platform-web', 'pc-web', 'uniapp'],
    'tenant_hooks' => [],
];
