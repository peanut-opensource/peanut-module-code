<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Notification\Controller\NoticeChannelController;
use PeanutAdmin\Modules\Notification\Controller\NoticeLogController;
use PeanutAdmin\Modules\Notification\Controller\NoticeSceneController;
use PeanutAdmin\Modules\Notification\Controller\NotificationInboxController;
use app\api\controller\SmsController as ApiSmsController;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::group(function (): void {
        Route::get('peanut.notification.channel.detail', [NoticeChannelController::class, 'detail']);
        Route::post('peanut.notification.channel.save', [NoticeChannelController::class, 'save']);
        Route::get('peanut.notification.log.list', [NoticeLogController::class, 'lists']);
        Route::get('peanut.notification.log.detail', [NoticeLogController::class, 'detail']);
        Route::get('peanut.notification.scene.list', [NoticeSceneController::class, 'lists']);
        Route::get('peanut.notification.scene.detail', [NoticeSceneController::class, 'detail']);
        Route::post('peanut.notification.scene.save', [NoticeSceneController::class, 'save']);
        Route::get('api/v1/notifications', [NotificationInboxController::class, 'index'])
            ->option(['peanut_permission' => 'peanut.notification.inbox.read']);
        Route::post('api/v1/notifications/:messageKey/read', [NotificationInboxController::class, 'markRead'])
            ->option(['peanut_permission' => 'peanut.notification.inbox.manage']);
        Route::post('api/v1/notifications/bulk', [NotificationInboxController::class, 'bulk'])
            ->option(['peanut_permission' => 'peanut.notification.inbox.manage']);
    })->middleware(LoginMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'peanut.notification', 'http.admin')
        ->middleware(AuthMiddleware::class)
        ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) === 'api') {
    Route::post('sms/sendCode', [ApiSmsController::class, 'sendCode'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.notice.verification', 'peanut.notification', 'notice.verification.send');
}
