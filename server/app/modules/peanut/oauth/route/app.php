<?php

declare(strict_types=1);

use app\api\controller\OAuthController as ApiOAuthController;
use app\api\controller\OfficialAccountController as ApiOfficialAccountController;
use app\api\middleware\CheckTokenMiddleware;
use PeanutAdmin\Modules\OAuth\Controller\WebPageController;
use PeanutAdmin\Modules\OAuth\Controller\MiniProgramController;
use PeanutAdmin\Modules\OAuth\Controller\OfficialAccountController;
use PeanutAdmin\Modules\OAuth\Controller\OfficialAccountMenuController;
use PeanutAdmin\Modules\OAuth\Controller\OfficialAccountReplyController;
use PeanutAdmin\Modules\OAuth\Controller\OpenPlatformController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::group(function (): void {
        Route::get('peanut.oauth.web-page.config', [WebPageController::class, 'getConfig']);
        Route::post('peanut.oauth.web-page.save', [WebPageController::class, 'setConfig']);
        Route::get('peanut.oauth.mini-program.config', [MiniProgramController::class, 'getConfig']);
        Route::post('peanut.oauth.mini-program.save', [MiniProgramController::class, 'setConfig']);
        Route::get('peanut.oauth.official-account.config', [OfficialAccountController::class, 'getConfig']);
        Route::post('peanut.oauth.official-account.save', [OfficialAccountController::class, 'setConfig']);
        Route::get('peanut.oauth.official-account.menu.detail', [OfficialAccountMenuController::class, 'detail']);
        Route::post('peanut.oauth.official-account.menu.save', [OfficialAccountMenuController::class, 'save']);
        Route::post('peanut.oauth.official-account.menu.publish', [OfficialAccountMenuController::class, 'saveAndPublish']);
        Route::get('peanut.oauth.official-account.reply.list', [OfficialAccountReplyController::class, 'lists']);
        Route::get('peanut.oauth.official-account.reply.detail', [OfficialAccountReplyController::class, 'detail']);
        Route::post('peanut.oauth.official-account.reply.add', [OfficialAccountReplyController::class, 'add']);
        Route::post('peanut.oauth.official-account.reply.edit', [OfficialAccountReplyController::class, 'edit']);
        Route::post('peanut.oauth.official-account.reply.delete', [OfficialAccountReplyController::class, 'delete']);
        Route::post('peanut.oauth.official-account.reply.update-status', [OfficialAccountReplyController::class, 'updateStatus']);
        Route::get('peanut.oauth.open-platform.config', [OpenPlatformController::class, 'getConfig']);
        Route::post('peanut.oauth.open-platform.save', [OpenPlatformController::class, 'setConfig']);
    })->middleware(LoginMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'peanut.oauth', 'http.admin')
        ->middleware(AuthMiddleware::class)
        ->middleware(OperationLogMiddleware::class);
}

if (($peanutRouteApplication ?? null) !== 'api') {
    return;
}

Route::post('oauth/wechat/begin', [ApiOAuthController::class, 'begin']);
Route::post('oauth/wechat/callback', [ApiOAuthController::class, 'callback']);
Route::post('oauth/wechat/mini-program', [ApiOAuthController::class, 'miniProgram']);
Route::post('oauth/wechat/complete', [ApiOAuthController::class, 'complete']);
Route::get('oauth/wechat/redirect/pc', [ApiOAuthController::class, 'redirectPc']);
Route::get('oauth/wechat/redirect/official-account', [ApiOAuthController::class, 'redirectOfficialAccount']);
Route::post('oauth/wechat/bind', [ApiOAuthController::class, 'bind'])
    ->middleware(CheckTokenMiddleware::class)
    ->middleware(OfficialModuleMiddleware::class, 'peanut.oauth', 'http.member-bind');
Route::get('wechat/official-account/callback/:binding', [ApiOfficialAccountController::class, 'verify']);
Route::post('wechat/official-account/callback/:binding', [ApiOfficialAccountController::class, 'callback']);
