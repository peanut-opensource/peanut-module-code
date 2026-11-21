<?php

use app\AppService;
use app\common\composition\ModuleExtensionService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
if (!class_exists(\think\migration\Service::class)) {
    throw new \RuntimeException('缺少官方迁移依赖，请先按 server/composer.lock 执行 Composer install --no-scripts');
}

$config = config('peanut');
$applicationServices = array_key_exists('services', $config) ? $config['services'] : [];
if (!is_array($applicationServices) || !array_is_list($applicationServices)) {
    throw new \InvalidArgumentException('PEANUT_SERVICES_INVALID');
}
foreach ($applicationServices as $service) {
    if (!is_string($service) || !is_subclass_of($service, \think\Service::class)
        || !(new \ReflectionClass($service))->isInstantiable()) {
        throw new \InvalidArgumentException('PEANUT_SERVICE_INVALID');
    }
}

return [
    // Register the official Service subtype before automatic vendor discovery.
    \think\migration\Service::class,
    AppService::class,
    ModuleExtensionService::class,
    ...$applicationServices,
];
