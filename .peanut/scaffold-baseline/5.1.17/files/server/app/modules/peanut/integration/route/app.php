<?php

declare(strict_types=1);

use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use PeanutAdmin\Modules\Integration\Controller\MachineIdentityController;
use PeanutAdmin\Modules\Integration\Controller\SessionSecurityController;
use PeanutAdmin\Modules\Integration\Controller\WebhookController;
use PeanutAdmin\Modules\Integration\Controller\WebhookDeliveryController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

Route::group(function (): void {
    Route::get('api/v1/integration-security/machine-identities', [MachineIdentityController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.integration.machine.read']);
    Route::post('api/v1/integration-security/machine-identities', [MachineIdentityController::class, 'create'])
        ->option(['peanut_permission' => 'peanut.integration.machine.manage']);
    Route::post('api/v1/integration-security/machine-identities/:identityKey/rotate', [MachineIdentityController::class, 'rotate'])
        ->option(['peanut_permission' => 'peanut.integration.machine.manage']);
    Route::delete('api/v1/integration-security/machine-identities/:identityKey', [MachineIdentityController::class, 'revoke'])
        ->option(['peanut_permission' => 'peanut.integration.machine.manage']);

    Route::get('api/v1/integration-security/webhooks', [WebhookController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.integration.webhook.read']);
    Route::post('api/v1/integration-security/webhooks', [WebhookController::class, 'create'])
        ->option(['peanut_permission' => 'peanut.integration.webhook.manage']);
    Route::post('api/v1/integration-security/webhooks/:endpointKey/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->option(['peanut_permission' => 'peanut.integration.webhook.manage']);
    Route::delete('api/v1/integration-security/webhooks/:endpointKey', [WebhookController::class, 'disable'])
        ->option(['peanut_permission' => 'peanut.integration.webhook.manage']);

    Route::get('api/v1/integration-security/deliveries', [WebhookDeliveryController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.integration.delivery.read']);
    Route::get('api/v1/integration-security/deliveries/:deliveryKey/attempts', [WebhookDeliveryController::class, 'attempts'])
        ->option(['peanut_permission' => 'peanut.integration.delivery.read']);

    Route::get('api/v1/integration-security/sessions', [SessionSecurityController::class, 'index'])
        ->option(['peanut_permission' => 'peanut.integration.session.read']);
    Route::post('api/v1/integration-security/sessions/:sessionKey/revoke', [SessionSecurityController::class, 'revoke'])
        ->option(['peanut_permission' => 'peanut.integration.session.revoke']);
})->middleware(LoginMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.integration', 'http.admin')
    ->middleware(AuthMiddleware::class)
    ->middleware(OperationLogMiddleware::class);
