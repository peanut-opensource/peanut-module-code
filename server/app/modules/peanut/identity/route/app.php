<?php

declare(strict_types=1);

use app\adminapi\http\middleware\{AuthMiddleware, LoginMiddleware, OperationLogMiddleware};
use app\common\infrastructure\module\OfficialModuleMiddleware;
use PeanutAdmin\Modules\Identity\Controller\DeviceCredentialController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::post('api/v1/identity/device-credentials', [DeviceCredentialController::class, 'enroll'])
        ->option(['peanut_permission' => 'peanut.identity.device-credential.enroll']);
    Route::post('api/v1/identity/device-credentials/:key/revoke', [DeviceCredentialController::class, 'revoke'])
        ->option(['peanut_permission' => 'peanut.identity.device-credential.revoke'])
        ->pattern(['key' => '(?-i:[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.identity', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class)
    ->completeMatch();
