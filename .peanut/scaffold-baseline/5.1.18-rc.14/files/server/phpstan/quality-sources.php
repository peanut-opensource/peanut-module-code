<?php

declare(strict_types=1);

$server = dirname(__DIR__);
$root = dirname($server);
$moduleSources = $server . '/app/modules';
if (!is_dir($moduleSources . '/peanut')) {
    $modulesRoot = getenv('PEANUT_PHPSTAN_MODULE_ROOT') ?: dirname($root) . '/peanut-module-code';
    $moduleSources = $modulesRoot . '/server/app/modules';
    $moduleSources .= '/peanut';
    if (!is_dir($moduleSources)) {
        throw new RuntimeException('Set PEANUT_PHPSTAN_MODULE_ROOT to the official Module source checkout.');
    }
}

return [
    'parameters' => [
        'scanDirectories' => [
            $moduleSources,
            $root . '/scripts/scaffold-runtime',
        ],
        'scanFiles' => [
            $server . '/vendor/topthink/framework/src/helper.php',
            $server . '/database/InitialInstallationSource.php',
            $server . '/database/environment-guard.php',
            $server . '/database/install.php',
            $server . '/database/resource-registry.php',
        ],
    ],
];
