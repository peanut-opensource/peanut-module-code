<?php

declare(strict_types=1);

$peanutRouteApplication = 'adminapi';
$serverRoot = dirname(__DIR__, 3);
if (!isset($peanutRouteModules)) {
    $peanutRouteModules = [];
    foreach (app(\PeanutAdmin\Kernel\Module\CompiledModuleRegistry::class)->modules as $manifest) {
        $peanutRouteModules[$manifest->data['key']] = $manifest->root;
    }
}

require $serverRoot . '/route/app.php';
require $serverRoot . '/route/tenant.php';
require $serverRoot . '/route/admin.php';

foreach ($peanutRouteModules as $moduleRoot) {
    $moduleRoute = $moduleRoot . '/route/app.php';
    if (is_file($moduleRoute)) {
        require $moduleRoute;
    }
}

unset($peanutRouteApplication, $serverRoot, $moduleRoute, $moduleRoot, $manifest);
