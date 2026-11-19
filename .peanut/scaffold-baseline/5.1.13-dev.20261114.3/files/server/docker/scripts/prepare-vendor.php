<?php

declare(strict_types=1);

// Dependency preparation runs before an instance has database credentials.
// Invoke ThinkPHP's commands without initializing the business application.
$serverRoot = dirname(__DIR__, 2);
require $serverRoot . '/vendor/autoload.php';

$app = new \think\App($serverRoot . DIRECTORY_SEPARATOR);
$output = new \think\console\Output();
foreach ([\think\console\command\ServiceDiscover::class, \think\console\command\VendorPublish::class] as $type) {
    $command = new $type();
    $command->setApp($app);
    $input = new \think\console\Input([]);
    $input->setInteractive(false);
    $status = $command->run($input, $output);
    if ($status !== 0) {
        exit($status);
    }
}
