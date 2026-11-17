<?php

use app\AppService;
use app\common\composition\ModuleExtensionService;

// 系统服务定义文件
// 服务在完成全局初始化之后执行
if (!class_exists(\think\migration\Service::class)) {
    throw new \RuntimeException('缺少官方迁移依赖，请先按 server/composer.lock 执行 Composer install --no-scripts');
}

return [
    // Register the official Service subtype before automatic vendor discovery.
    \think\migration\Service::class,
    AppService::class,
    ModuleExtensionService::class,
];
