<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Payment\Controller\PayConfigController;
use PeanutAdmin\Modules\Payment\Controller\RechargeSettingController;
use PeanutAdmin\Modules\Payment\Controller\RechargeController;
use PeanutAdmin\Modules\Payment\Controller\RefundController;
use app\api\controller\PaymentNotifyController as ApiPaymentNotifyController;
use app\api\controller\RechargeController as ApiRechargeController;
use app\api\middleware\CheckTokenMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::group(function (): void {
        Route::get('peanut.payment.settings.detail', [PayConfigController::class, 'getConfig']);
        Route::post('peanut.payment.settings.save', [PayConfigController::class, 'setConfig']);
        Route::get('peanut.payment.recharge-settings.detail', [RechargeSettingController::class, 'config']);
        Route::post('peanut.payment.recharge-settings.save', [RechargeSettingController::class, 'save']);
        Route::get('peanut.payment.recharge.list', [RechargeController::class, 'lists']);
        Route::post('peanut.payment.recharge.refund', [RechargeController::class, 'refund']);
        Route::post('peanut.payment.refund.retry', [RechargeController::class, 'refundAgain']);
        Route::get('peanut.payment.refund.stat', [RefundController::class, 'stat']);
        Route::get('peanut.payment.refund.list', [RefundController::class, 'record']);
        Route::get('peanut.payment.refund.log', [RefundController::class, 'log']);
    })->middleware(LoginMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'peanut.payment', 'http.admin')
        ->middleware(AuthMiddleware::class)
        ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) !== 'api') {
    return;
}

Route::post('payment/notify/wechat/:binding', [ApiPaymentNotifyController::class, 'wechat']);
Route::post('payment/notify/alipay/:binding', [ApiPaymentNotifyController::class, 'alipay']);

Route::group(function (): void {
    Route::get('recharge/config', [ApiRechargeController::class, 'config']);
    Route::post('recharge/create', [ApiRechargeController::class, 'create']);
    Route::post('recharge/prepay', [ApiRechargeController::class, 'prepay']);
    Route::get('recharge/detail', [ApiRechargeController::class, 'detail']);
    Route::get('recharge/lists', [ApiRechargeController::class, 'lists']);
})->middleware(CheckTokenMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.payment', 'http.member');
