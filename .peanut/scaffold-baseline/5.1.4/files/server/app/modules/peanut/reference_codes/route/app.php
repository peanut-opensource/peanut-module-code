<?php

declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use PeanutAdmin\Modules\ReferenceCodes\Controller\ReferenceCodesController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('api/v1/reference-code-sets', [ReferenceCodesController::class, 'sets'])
        ->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::get('api/v1/reference-code-sets/:moduleKey/:setKey/codes', [ReferenceCodesController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::post('api/v1/reference-code-sets/:moduleKey/:setKey/codes', [ReferenceCodesController::class, 'create'])
        ->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::get('api/v1/reference-code-sets/:moduleKey/:setKey/codes/:code', [ReferenceCodesController::class, 'detail'])
        ->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::put('api/v1/reference-code-sets/:moduleKey/:setKey/codes/:code', [ReferenceCodesController::class, 'replace'])
        ->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::delete('api/v1/reference-code-sets/:moduleKey/:setKey/codes/:code', [ReferenceCodesController::class, 'retire'])
        ->option(['peanut_permission' => 'peanut.reference-codes.manage']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.reference-codes', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
