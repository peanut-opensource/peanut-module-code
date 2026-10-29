<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Workflow\Controller\WorkflowController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

if (is_file(__DIR__ . '/design.php')) {
    require __DIR__ . '/design.php';
}

Route::group('api/v1/workflow', function (): void {
    Route::get('definitions/:moduleKey/:workflowKey/summary', [WorkflowController::class, 'summary'])->option(['peanut_permission' => 'peanut.workflow.instance.start']);
    Route::get('inbox', [WorkflowController::class, 'inbox'])->option(['peanut_permission' => 'peanut.workflow.instance.read']);
    Route::post('instances', [WorkflowController::class, 'start'])->option(['peanut_permission' => 'peanut.workflow.instance.start']);
    Route::get('instances/:instanceKey/events', [WorkflowController::class, 'events'])->option(['peanut_permission' => 'peanut.workflow.instance.read']);
    Route::get('instances/:instanceKey/materials', [WorkflowController::class, 'materials'])->option(['peanut_permission' => 'peanut.workflow.instance.read']);
    Route::get('instances/:instanceKey', [WorkflowController::class, 'instance'])->option(['peanut_permission' => 'peanut.workflow.instance.read']);
    Route::post('instances/:instanceKey/reconcile', [WorkflowController::class, 'reconcile'])->option(['peanut_permission' => 'peanut.workflow.diagnostics.read']);
    Route::get('instances/:instanceKey/diagnostics', [WorkflowController::class, 'diagnostics'])->option(['peanut_permission' => 'peanut.workflow.diagnostics.read']);
    Route::post('instances/:instanceKey/recover', [WorkflowController::class, 'recover'])->option(['peanut_permission' => 'peanut.workflow.diagnostics.retry']);
    Route::get('instances/:instanceKey/notifications', [WorkflowController::class, 'notificationDiagnostics'])->option(['peanut_permission' => 'peanut.workflow.diagnostics.read']);
    Route::post('instances/:instanceKey/notifications/recover', [WorkflowController::class, 'recoverNotification'])->option(['peanut_permission' => 'peanut.workflow.diagnostics.retry']);
    foreach (['claim', 'release', 'decisions' => 'decide', 'completions' => 'complete', 'return' => 'returnTask', 'resubmit'] as $path => $method) {
        if (is_int($path)) {
            $path = $method;
        }
        $permission = $method === 'returnTask' ? 'return' : $method;
        Route::post('tasks/:workItemKey/' . $path, [WorkflowController::class, $method])->option(['peanut_permission' => 'peanut.workflow.' . ($method === 'resubmit' ? 'instance.resubmit' : 'task.' . $permission)]);
    }
    foreach (['withdraw', 'terminate', 'retry', 'reassign'] as $method) {
        Route::post('instances/:instanceKey/' . $method, [WorkflowController::class, $method])->option(['peanut_permission' => 'peanut.workflow.' . (in_array($method, ['retry', 'reassign'], true) ? 'diagnostics.' : 'instance.') . $method]);
    }
})->pattern([
    'moduleKey' => '[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*',
    'workflowKey' => '[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*',
])->completeMatch()->middleware(LoginMiddleware::class)->middleware(OfficialModuleMiddleware::class, 'peanut.workflow', 'http.admin')->middleware(AuthMiddleware::class)->middleware(OperationLogMiddleware::class);
