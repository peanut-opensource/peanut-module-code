<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Member\Controller\MemberController;
use PeanutAdmin\Modules\Member\Controller\MemberTagController;
use PeanutAdmin\Modules\Member\Controller\AccountLogController;
use app\api\controller\LoginController as ApiLoginController;
use app\api\controller\UserController as ApiUserController;
use app\api\controller\AccountLogController as ApiAccountLogController;
use app\api\middleware\CheckTokenMiddleware;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::group(function (): void {
        Route::get('peanut.member.list', [MemberController::class, 'lists']);
        Route::get('peanut.member.detail', [MemberController::class, 'detail']);
        Route::post('peanut.member.add', [MemberController::class, 'add']);
        Route::post('peanut.member.edit', [MemberController::class, 'edit']);
        Route::post('peanut.member.update-status', [MemberController::class, 'updateStatus']);
        Route::post('peanut.member.balance.adjust', [MemberController::class, 'adjustMoney']);
        Route::get('peanut.member.tag.list', [MemberTagController::class, 'lists']);
        Route::post('peanut.member.tag.add', [MemberTagController::class, 'add']);
        Route::post('peanut.member.tag.edit', [MemberTagController::class, 'edit']);
        Route::post('peanut.member.tag.delete', [MemberTagController::class, 'delete']);
        Route::get('peanut.member.account-log.list', [AccountLogController::class, 'lists']);
        Route::get('peanut.member.account-log.change-types', [AccountLogController::class, 'getUmChangeType']);
    })->middleware(LoginMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'peanut.member', 'http.admin')
        ->middleware(AuthMiddleware::class)
        ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) !== 'api') {
    return;
}

Route::post('login/register', [ApiLoginController::class, 'register'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.member.public-auth', 'peanut.member', 'member.register');
Route::post('login/account', [ApiLoginController::class, 'account'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.member.public-auth', 'peanut.member', 'member.login');
if (isset($peanutRouteModules['peanut.notification'])) {
    foreach ([
        ['login/mobile', 'mobile', 'notice.verification.verify', 'http.mobile-login'],
        ['login/resetPassword', 'resetPassword', 'notice.verification.verify', 'http.reset-password'],
    ] as [$path, $action, $noticeOperation, $memberOperation]) {
        Route::post($path, [ApiLoginController::class, $action])
            ->middleware(PublicTenantModuleMiddleware::class, 'peanut.notice.verification', 'peanut.notification', $noticeOperation)
            ->middleware(OfficialModuleMiddleware::class, 'peanut.member', $memberOperation);
    }
}

Route::group(function () use ($peanutRouteModules): void {
    Route::get('user/center', [ApiUserController::class, 'center']);
    Route::get('user/info', [ApiUserController::class, 'info']);
    Route::post('user/setInfo', [ApiUserController::class, 'setInfo']);
    Route::post('user/changePassword', [ApiUserController::class, 'changePassword']);
    if (isset($peanutRouteModules['peanut.notification'])) {
        Route::post('user/bindMobile', [ApiUserController::class, 'bindMobile']);
    }
    Route::get('account_log/lists', [ApiAccountLogController::class, 'lists']);
})->middleware(CheckTokenMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.member', 'http.member');
