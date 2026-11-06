<?php

declare(strict_types=1);
use think\facade\Route;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use Custom\Modules\AiAssistant\Controller\AiController;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}
Route::group(function (): void {
    foreach (['overview','list' => 'listing','detail','history','lookups','taskStatus','candidate','requestStatus'] as $key => $method) {
        $name = is_int($key) ? $method : $key;
        Route::get('custom.ai-assistant.' . $name, [AiController::class,$method])->option(['peanut_permission' => 'custom.ai-assistant.view']);
    }
    Route::get('custom.ai-assistant.textRoutes', [AiController::class, 'textRoutes'])->option(['peanut_permission' => 'custom.ai-assistant.use']);
    foreach (['saveConfiguration' => 'configure','saveCredential' => 'configure','configurationState' => 'configure','generateText' => 'use','createTask' => 'use','manualCandidate' => 'use','taskAction' => 'use','collectCandidate' => 'use','decide' => 'review','candidateLifecycle' => 'review','resolve' => 'view'] as $method => $op) {
        Route::post('custom.ai-assistant.' . $method, [AiController::class,$method])->option(['peanut_permission' => 'custom.ai-assistant.' . $op]);
    }
})->middleware(LoginMiddleware::class)->middleware(OfficialModuleMiddleware::class, 'custom.ai-assistant', 'http.admin')->middleware(AuthMiddleware::class);
// Request bodies include private production input or credentials. This module records only safe audit metadata,
// never attaches the generic request-parameter OperationLogMiddleware.
