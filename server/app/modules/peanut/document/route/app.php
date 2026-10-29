<?php

declare(strict_types=1);
use PeanutAdmin\Modules\Document\Controller\DocumentController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}
Route::group(function (): void {
    Route::get('peanut.document.detail', [DocumentController::class,'detail']);
    Route::get('peanut.document.versions', [DocumentController::class,'versions']);
    Route::get('peanut.document.capabilities', [DocumentController::class,'capabilities']);
    Route::get('peanut.document.operation', [DocumentController::class,'operation']);
    Route::get('peanut.document.target', [DocumentController::class,'target']);
    Route::post('peanut.document.target.cancel', [DocumentController::class,'cancelTarget']);
    Route::post('peanut.document.solo.save', [DocumentController::class,'soloSave']);
    Route::post('peanut.document.resource.bind', [DocumentController::class,'bindResource']);
    Route::post('peanut.document.version.create', [DocumentController::class,'createVersion']);
    Route::post('peanut.document.version.restore', [DocumentController::class,'restoreVersion']);
    Route::post('peanut.document.archive', [DocumentController::class,'archive']);
    Route::post('peanut.document.restore', [DocumentController::class,'restore']);
})->middleware([
    \app\adminapi\http\middleware\LoginMiddleware::class,
    [\app\common\infrastructure\module\OfficialModuleMiddleware::class,['peanut.document','http.admin']],
    \app\adminapi\http\middleware\AuthMiddleware::class,
    \app\adminapi\http\middleware\OperationLogMiddleware::class,
]);
