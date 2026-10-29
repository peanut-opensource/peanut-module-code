<?php

declare(strict_types=1);

use PeanutAdmin\Modules\File\Controller\FileController;
use PeanutAdmin\Modules\File\Controller\UploadController;
use app\api\controller\UploadController as ApiUploadController;
use app\api\controller\StorageController as ApiStorageController;
use app\platform\controller\PlatformStorageController;
use app\api\middleware\CheckTokenMiddleware;
use app\platform\http\middleware\PlatformLoginMiddleware;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::group(function (): void {
        Route::post('peanut.file.upload.image', [UploadController::class, 'image']);
        Route::post('peanut.file.upload.video', [UploadController::class, 'video']);
        Route::post('peanut.file.upload.file', [UploadController::class, 'file']);
        Route::get('peanut.file.list', [FileController::class, 'lists']);
        Route::post('api/v1/files/resolve', [FileController::class, 'resolve']);
        Route::post('api/v1/files/visibility/preview', [\PeanutAdmin\Modules\File\Controller\FileVisibilityController::class, 'preview'])
            ->completeMatch()
            ->option(['peanut_permission' => 'peanut.file.visibility.change']);
        Route::post('api/v1/files/visibility/batches', [\PeanutAdmin\Modules\File\Controller\FileVisibilityController::class, 'submit'])
            ->completeMatch()
            ->option(['peanut_permission' => 'peanut.file.visibility.change']);
        Route::get('api/v1/files/visibility/batches', [\PeanutAdmin\Modules\File\Controller\FileVisibilityController::class, 'recent'])
            ->completeMatch()
            ->option(['peanut_permission' => 'peanut.file.visibility.change']);
        Route::get('api/v1/files/visibility/batches/:batchKey', [\PeanutAdmin\Modules\File\Controller\FileVisibilityController::class, 'status'])
            ->completeMatch()
            ->option(['peanut_permission' => 'peanut.file.visibility.change']);
        Route::post('api/v1/files/visibility/batches/:batchKey/resume', [\PeanutAdmin\Modules\File\Controller\FileVisibilityController::class, 'resume'])
            ->completeMatch()
            ->option(['peanut_permission' => 'peanut.file.visibility.change']);
        Route::get('api/v1/files/assets', [FileController::class, 'assets'])
            ->option(['peanut_permission' => 'peanut.file.list']);
        Route::post('peanut.file.move', [FileController::class, 'move']);
        Route::post('peanut.file.rename', [FileController::class, 'rename']);
        Route::post('peanut.file.delete', [FileController::class, 'delete']);
        Route::get('peanut.file.snapshot.pending', [FileController::class,'pendingSnapshots'])->option(['peanut_permission' => 'peanut.file.manage']);
        Route::post('peanut.file.snapshot.recover', [FileController::class,'recoverSnapshot'])->option(['peanut_permission' => 'peanut.file.delete']);
        Route::get('peanut.file.category.list', [FileController::class, 'listCate']);
        Route::post('peanut.file.category.add', [FileController::class, 'addCate']);
        Route::post('peanut.file.category.edit', [FileController::class, 'editCate']);
        Route::post('peanut.file.category.delete', [FileController::class, 'delCate']);
    })->middleware(LoginMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'peanut.file', 'http.admin')
        ->middleware(AuthMiddleware::class)
        ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) === 'api') {
    Route::get('storage/delivery', [ApiStorageController::class, 'delivery']);
    if (isset($peanutRouteModules['peanut.member'])) {
        Route::post('upload/image', [ApiUploadController::class, 'image'])
            ->middleware(CheckTokenMiddleware::class)
            ->middleware(OfficialModuleMiddleware::class, 'peanut.file', 'http.member-upload');
    }
}

if (($peanutRouteApplication ?? null) === 'platform') {
    Route::get('infrastructure/storage', [PlatformStorageController::class, 'snapshot'])
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
    Route::post('infrastructure/storage/account', [PlatformStorageController::class, 'createAccount'])
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
    Route::post('infrastructure/storage/account/update', [PlatformStorageController::class, 'updateAccount'])
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
    Route::post('infrastructure/storage/space', [PlatformStorageController::class, 'createSpace'])
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
    Route::post('infrastructure/storage/space/update', [PlatformStorageController::class, 'updateSpace'])
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
    Route::post('infrastructure/storage/route', [PlatformStorageController::class, 'setRoute'])
        ->middleware(PlatformLoginMiddleware::class)
        ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
}
