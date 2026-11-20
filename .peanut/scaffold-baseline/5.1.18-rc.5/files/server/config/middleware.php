<?php

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\api\middleware\CheckTokenMiddleware;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\common\http\middleware\InstallationStateMiddleware;
use app\common\http\middleware\MaintenanceWriteGateMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use app\platform\http\middleware\PlatformHostMiddleware;
use app\platform\http\middleware\PlatformInstanceToolMiddleware;
use app\platform\http\middleware\PlatformLoginMiddleware;
use app\platform\http\middleware\PlatformPermissionMiddleware;

// 中间件配置
return [
    // 别名或分组
    'alias' => [
        'admin.session' => LoginMiddleware::class,
        'admin.permission' => AuthMiddleware::class,
        'admin.audit' => OperationLogMiddleware::class,
        'member.session' => CheckTokenMiddleware::class,
        'module.boundary' => OfficialModuleMiddleware::class,
        'platform.host' => PlatformHostMiddleware::class,
        'platform.session' => PlatformLoginMiddleware::class,
        'platform.permission' => PlatformPermissionMiddleware::class,
        'platform.instance-tool' => PlatformInstanceToolMiddleware::class,
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [
        InstallationStateMiddleware::class,
        MaintenanceWriteGateMiddleware::class,
        PlatformHostMiddleware::class,
        PlatformLoginMiddleware::class,
        LoginMiddleware::class,
        CheckTokenMiddleware::class,
        PublicTenantModuleMiddleware::class,
        OfficialModuleMiddleware::class,
        PlatformPermissionMiddleware::class,
        AuthMiddleware::class,
        PlatformInstanceToolMiddleware::class,
        OperationLogMiddleware::class,
    ],
];
