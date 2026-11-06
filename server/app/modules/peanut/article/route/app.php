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
use PeanutAdmin\Modules\Article\Controller\ConsumerArticleController;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\api\middleware\CheckTokenMiddleware;

if (($peanutRouteApplication ?? null) === 'api') {
    Route::get('index/index', [ConsumerArticleController::class, 'mobileIndex'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.index');
    Route::get('article/cate', [ConsumerArticleController::class, 'cate'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.cate');
    Route::get('article/lists', [ConsumerArticleController::class, 'lists'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.lists');
    Route::get('article/detail', [ConsumerArticleController::class, 'detail'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.detail');
    Route::get('pc/index', [ConsumerArticleController::class, 'pcIndex'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.pc-index');
    Route::get('pc/infoCenter', [ConsumerArticleController::class, 'infoCenter'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.info-center');
    Route::get('pc/articleDetail', [ConsumerArticleController::class, 'pcDetail'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.pc-detail');
    if (isset($peanutRouteModules['peanut.member'])) {
        Route::group(function (): void {
            Route::post('article/addCollect', [ConsumerArticleController::class, 'addCollect']);
            Route::post('article/cancelCollect', [ConsumerArticleController::class, 'cancelCollect']);
            Route::get('article/collect', [ConsumerArticleController::class, 'collect']);
        })->middleware(CheckTokenMiddleware::class)
            ->middleware(OfficialModuleMiddleware::class, 'peanut.article', 'http.member');
    }
    return;
}

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
    Route::get('peanut.article.document.html-preview', [ArticleController::class, 'bodyHtmlPreview'])->option(['peanut_permission' => 'peanut.article.edit']);
    Route::post('peanut.article.document.html-migrate', [ArticleController::class, 'bodyHtmlMigrate'])->option(['peanut_permission' => 'peanut.article.edit']);
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
