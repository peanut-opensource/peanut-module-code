<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Task\Controller\CrontabController;
use PeanutAdmin\Modules\Task\Controller\TaskJobController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('peanut.task.list', [CrontabController::class, 'lists']);
    Route::get('peanut.task.detail', [CrontabController::class, 'detail']);
    Route::get('peanut.task.expression', [CrontabController::class, 'expression']);
    Route::post('peanut.task.add', [CrontabController::class, 'add']);
    Route::post('peanut.task.edit', [CrontabController::class, 'edit']);
    Route::post('peanut.task.delete', [CrontabController::class, 'delete']);
    Route::post('peanut.task.operate', [CrontabController::class, 'operate']);
    Route::get('api/v1/tasks/jobs', [TaskJobController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.task.jobs.read']);
    Route::post('api/v1/tasks/jobs/:jobKey/cancel', [TaskJobController::class, 'cancel'])
        ->option(['peanut_permission' => 'peanut.task.jobs.manage']);
    Route::post('api/v1/tasks/jobs/:jobKey/retry', [TaskJobController::class, 'retry'])
        ->option(['peanut_permission' => 'peanut.task.jobs.manage']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.task', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
