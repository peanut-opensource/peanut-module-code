<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Collaboration\Controller\InternalController;
use PeanutAdmin\Modules\Collaboration\Controller\TransportController;
use PeanutAdmin\Modules\Collaboration\Middleware\ServiceAuthenticationMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}
Route::post('peanut.collaboration.transport', [TransportController::class,'resolve'])->middleware([
    \app\adminapi\http\middleware\LoginMiddleware::class,
    [\app\common\infrastructure\module\OfficialModuleMiddleware::class,['peanut.collaboration','http.admin']],
    \app\adminapi\http\middleware\AuthMiddleware::class,
]);
Route::group('internal/collaboration/v1', function (): void {
    foreach (['authorize','check','load','store','receipt','references','target','recovery','revoke'] as $action) {
        Route::post($action, [InternalController::class,$action])->completeMatch();
    }
    Route::post('target/prepare', [InternalController::class,'prepareTarget'])->completeMatch();
})->middleware(ServiceAuthenticationMiddleware::class);
