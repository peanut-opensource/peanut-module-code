<?php

declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use Custom\Modules\AiAccess\Controller\CatalogController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('custom.ai-access.catalog', [CatalogController::class, 'catalog'])
        ->option(['peanut_permission' => 'custom.ai-access.view']);
    Route::post('custom.ai-access.save', [CatalogController::class, 'save'])
        ->option(['peanut_permission' => 'custom.ai-access.configure']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'custom.ai-access', 'http.admin')
    ->middleware(AuthMiddleware::class);
