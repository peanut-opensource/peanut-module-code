<?php

declare(strict_types=1);

use PeanutAdmin\Modules\RichText\Controller\RichTextDocumentController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('peanut.rich-text.document.list', [RichTextDocumentController::class, 'lists']);
    Route::get('peanut.rich-text.document.detail', [RichTextDocumentController::class, 'detail']);
    Route::post('peanut.rich-text.document.add', [RichTextDocumentController::class, 'add']);
    Route::post('peanut.rich-text.document.edit', [RichTextDocumentController::class, 'edit']);
    Route::post('peanut.rich-text.document.delete', [RichTextDocumentController::class, 'delete']);
    Route::get('peanut.rich-text.document.collaboration', [RichTextDocumentController::class, 'collaboration']);
})->middleware([
    LoginMiddleware::class,
    [OfficialModuleMiddleware::class, ['peanut.rich-text', 'http.admin']],
    AuthMiddleware::class,
    OperationLogMiddleware::class,
]);
