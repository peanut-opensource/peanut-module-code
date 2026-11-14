<?php

declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use app\platform\http\middleware\PlatformLoginMiddleware;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictionaryCatalogController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\PlatformDictionaryController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\ReferenceCodesController;
use think\facade\Route;

$application = $peanutRouteApplication ?? null;
if ($application === 'platform') {
    Route::get('api/v1/dictionary-catalog', [PlatformDictionaryController::class, 'types'])
        ->completeMatch()
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
    Route::post('api/v1/dictionary-catalog/:type/tenant-mode', [PlatformDictionaryController::class, 'tenantMode'])
        ->pattern(['type' => '[A-Za-z0-9_-]{1,100}'])
        ->completeMatch()
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
    return;
}
if ($application !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('api/v1/dictionary-catalog', [DictionaryCatalogController::class, 'types'])
        ->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::get('api/v1/dictionary-catalog/:type', [DictionaryCatalogController::class, 'detail'])
        ->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::post('api/v1/dictionary-catalog/:type/mode', [DictionaryCatalogController::class, 'mode'])
        ->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('api/v1/dictionary-catalog/:type/override', [DictionaryCatalogController::class, 'override'])
        ->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('api/v1/dictionary-catalog/:type/override/clear', [DictionaryCatalogController::class, 'clearOverride'])
        ->option(['peanut_permission' => 'peanut.reference-codes.manage']);
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
})->pattern([
    'moduleKey' => '[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*',
    'setKey' => '[a-z][a-z0-9]*(?:-[a-z0-9]+)*',
    'code' => '[a-z][a-z0-9]*(?:-[a-z0-9]+)*',
    'type' => '[A-Za-z0-9_-]{1,100}',
])->caseUrl(true)->completeMatch()->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.reference-codes', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
