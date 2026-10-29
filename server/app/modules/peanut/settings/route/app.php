<?php

declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use PeanutAdmin\Modules\Settings\Controller\SettingsController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('api/v1/settings', [SettingsController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.settings.read']);
    Route::put('api/v1/settings/:moduleKey/:settingKey', [SettingsController::class, 'replace'])
        ->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::delete('api/v1/settings/:moduleKey/:settingKey', [SettingsController::class, 'unset'])
        ->option(['peanut_permission' => 'peanut.settings.manage']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.settings', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
