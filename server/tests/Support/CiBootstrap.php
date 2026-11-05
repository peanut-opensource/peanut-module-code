<?php

declare(strict_types=1);

/** The same explicit environment as executable tests; no implicit database or service creation. */
$server = dirname(__DIR__, 2);
require_once $server . '/vendor/autoload.php';
if (($environment = getenv('PEANUT_SERVER_ENV_FILE')) !== false && $environment !== '') {
    if (!str_starts_with($environment, '/') || !is_file($environment) || is_link($environment)
        || !is_readable($environment)) {
        throw new RuntimeException('Invalid explicit backend environment file');
    }
    // Use the framework store without initializing application services or mirroring secrets.
    $app = new \think\App($server);
    require_once $app->getThinkPath() . 'helper.php';
    $app->env->load($environment);
}
