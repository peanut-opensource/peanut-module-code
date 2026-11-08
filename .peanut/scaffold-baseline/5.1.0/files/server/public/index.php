<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\App;

// [ 应用入口文件 ]

try {
    require __DIR__ . '/../vendor/autoload.php';

    // 执行HTTP应用并响应；服务注册也属于启动异常边界。
    $http = (new App())->http;
    $response = $http->run();
} catch (Throwable $exception) {
    if ($exception instanceof \PeanutAdmin\Kernel\Module\ModuleException
        && $exception->errorCode === 'MODULE_REGISTRY_NOT_COMPILED') {
        error_log('HTTP Module registry unavailable: run peanut module compile');
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['code' => 50300, 'msg' => '服务暂时不可用',
            'data' => ['error_code' => 'MODULE_REGISTRY_NOT_COMPILED']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($exception instanceof RuntimeException
        && preg_match('/^(?:SERVER_DEPLOYMENT_|HTTP_(?:MOUNT_|IMAGE_|WORKER_|PROGRAM_|MUTABLE_|OWNER_))/', $exception->getMessage()) === 1) {
        error_log('HTTP admission blocked: ' . $exception->getMessage());
        http_response_code(503);
        header('Content-Type: application/json');
        header('Retry-After: 3');
        echo json_encode(['code' => 'INSTALL_RUNTIME_RELOADING', 'restart_required' => true]);
        exit;
    }

    error_log('HTTP bootstrap failed: ' . $exception::class . ' at '
        . basename($exception->getFile()) . ':' . $exception->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'code' => 50000,
        'msg' => '服务暂时不可用',
        'data' => ['error_code' => 'HTTP_BOOTSTRAP_FAILED'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$response->send();

$http->end($response);
