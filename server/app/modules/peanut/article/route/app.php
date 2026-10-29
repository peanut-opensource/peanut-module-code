<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Article\Controller\ArticleCateController;
use PeanutAdmin\Modules\Article\Controller\ArticleController;
use PeanutAdmin\Modules\Article\Controller\HelloController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('peanut.article.hello', [HelloController::class, 'index'])->option(['peanut_permission' => 'peanut.article.list']);
    Route::get('peanut.article.category.list', [ArticleCateController::class, 'lists']);
    Route::get('peanut.article.category.all', [ArticleCateController::class, 'all']);
    Route::get('peanut.article.category.detail', [ArticleCateController::class, 'detail']);
    Route::post('peanut.article.category.add', [ArticleCateController::class, 'add']);
    Route::post('peanut.article.category.edit', [ArticleCateController::class, 'edit']);
    Route::post('peanut.article.category.delete', [ArticleCateController::class, 'delete']);
    Route::post('peanut.article.category.update-status', [ArticleCateController::class, 'updateStatus']);
    Route::get('peanut.article.category.recycle.list', [ArticleCateController::class, 'recycleLists']);
    Route::get('peanut.article.category.recycle.detail', [ArticleCateController::class, 'recycleDetail']);
    Route::post('peanut.article.category.restore', [ArticleCateController::class, 'restore']);
    Route::post('peanut.article.category.force-delete', [ArticleCateController::class, 'forceDelete']);
    Route::get('peanut.article.list', [ArticleController::class, 'lists']);
    Route::get('peanut.article.detail', [ArticleController::class, 'detail']);
    Route::post('peanut.article.add', [ArticleController::class, 'add']);
    Route::post('peanut.article.edit', [ArticleController::class, 'edit']);
    Route::post('peanut.article.document', [ArticleController::class, 'bodyDocument'])->option(['peanut_permission' => 'peanut.article.edit']);
    Route::get('peanut.article.document.editor', [ArticleController::class, 'bodyEditor'])->option(['peanut_permission' => 'peanut.article.edit']);
    Route::post('peanut.article.document.transport', [ArticleController::class, 'bodyTransport'])->option(['peanut_permission' => 'peanut.article.edit']);
    Route::post('peanut.article.document.save', [ArticleController::class, 'bodySave'])->option(['peanut_permission' => 'peanut.article.edit']);
    Route::get('peanut.article.document.operation', [ArticleController::class, 'bodySaveOperation'])->option(['peanut_permission' => 'peanut.article.edit']);
    Route::post('peanut.article.delete', [ArticleController::class, 'delete']);
    Route::post('peanut.article.update-status', [ArticleController::class, 'updateStatus']);
    Route::get('peanut.article.recycle.list', [ArticleController::class, 'recycleLists']);
    Route::get('peanut.article.recycle.detail', [ArticleController::class, 'recycleDetail']);
    Route::post('peanut.article.restore', [ArticleController::class, 'restore']);
    Route::post('peanut.article.force-delete', [ArticleController::class, 'forceDelete']);
})->middleware([
    LoginMiddleware::class,
    [OfficialModuleMiddleware::class, ['peanut.article', 'http.admin']],
    AuthMiddleware::class,
    OperationLogMiddleware::class,
]);
