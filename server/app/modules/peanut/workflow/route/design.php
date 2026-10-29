<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Workflow\Controller\WorkflowDefinitionController as Controller;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') { return; }

// Full artifacts include this contribution explicitly. APP_ENV cannot restore it in runtime.
Route::group('api/v1/workflow/definitions', function (): void {
    Route::get('', [Controller::class, 'index'])->option(['peanut_permission' => 'peanut.workflow.definition.read']);
    Route::post('validate', [Controller::class, 'validateGraph'])->option(['peanut_permission' => 'peanut.workflow.definition.write']);
    Route::post('preview', [Controller::class, 'preview'])->option(['peanut_permission' => 'peanut.workflow.definition.write']);
    Route::put(':moduleKey/:workflowKey/draft', [Controller::class, 'saveDraft'])->option(['peanut_permission' => 'peanut.workflow.definition.write']);
    foreach (['publish', 'activate', 'retire'] as $method) {
        Route::post(':moduleKey/:workflowKey/' . $method, [Controller::class, $method])->option(['peanut_permission' => 'peanut.workflow.definition.publish']);
    }
    Route::get(':moduleKey/:workflowKey', [Controller::class, 'detail'])->option(['peanut_permission' => 'peanut.workflow.definition.read']);
    Route::get(':moduleKey/:workflowKey/scenario', [Controller::class, 'scenario'])->option(['peanut_permission' => 'peanut.workflow.definition.read']);
})->pattern([
    'moduleKey' => '[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*',
    'workflowKey' => '[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*',
])->completeMatch()->middleware(LoginMiddleware::class)->middleware(OfficialModuleMiddleware::class, 'peanut.workflow', 'http.admin')->middleware(AuthMiddleware::class)->middleware(OperationLogMiddleware::class);
