<?php

declare(strict_types=1);

use PeanutAdmin\Modules\ImportExport\Controller\OperationLogExportController;
use PeanutAdmin\Modules\ImportExport\Controller\ConfigurationTransferController;
use PeanutAdmin\Modules\ImportExport\Controller\ImportExportOperationController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::post('peanut.import-export.operation-log.export', [OperationLogExportController::class, 'export']);
    Route::get('peanut.import-export.operation.status', [OperationLogExportController::class, 'exportStatus']);
    Route::get('peanut.import-export.result.download', [OperationLogExportController::class, 'exportDownload']);
    Route::get('peanut.import-export.configuration.export', [ConfigurationTransferController::class, 'export']);
    Route::post('peanut.import-export.configuration.dry-run', [ConfigurationTransferController::class, 'dryRun']);
    Route::post('peanut.import-export.configuration.apply', [ConfigurationTransferController::class, 'apply']);
    Route::get('api/v1/import-export/operations', [ImportExportOperationController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.import-export.operations.read']);
    Route::post('api/v1/import-export/imports', [ImportExportOperationController::class, 'submitImport'])
        ->option(['peanut_permission' => 'peanut.import-export.operations.create']);
    Route::post('api/v1/import-export/exports', [ImportExportOperationController::class, 'submitExport'])
        ->option(['peanut_permission' => 'peanut.import-export.operations.create']);
    Route::post('api/v1/import-export/operations/:operationKey/cancel', [ImportExportOperationController::class, 'cancel'])
        ->option(['peanut_permission' => 'peanut.import-export.operations.cancel']);
    Route::get('api/v1/files/:fileKey/content', [ImportExportOperationController::class, 'download'])
        ->option(['peanut_permission' => 'peanut.import-export.operations.read']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.import-export', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
