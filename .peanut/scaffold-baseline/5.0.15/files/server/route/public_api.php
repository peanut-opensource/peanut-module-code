<?php

declare(strict_types=1);

use app\api\controller\IndexController as ApiIndexController;
use app\api\controller\LoginController as ApiLoginController;
use app\api\controller\ArticleController as ApiArticleController;
use app\api\controller\SearchController as ApiSearchController;
use app\api\controller\PcController as ApiPcController;
use app\api\controller\DecorationController as ApiDecorationController;
use app\api\middleware\CheckTokenMiddleware;
use app\api\middleware\PublicTenantModuleMiddleware;
use app\common\infrastructure\module\OfficialModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'api') {
    return;
}
$selectedModules = array_keys($peanutRouteModules);

// ═══════════════════════════════════════════════════════════════════════════════
// 用户端 API（/api/user/ 和 /api/  命名空间）
// 公开接口无中间件；需登录接口挂 CheckTokenMiddleware
// ═══════════════════════════════════════════════════════════════════════════════

// ─── 公开接口（无需 token） ────────────────────────────────────────────────────
if (in_array('peanut.article', $selectedModules, true)) {
    Route::get('index/index', [ApiIndexController::class, 'index'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.index');
}
Route::get('index/config', [ApiIndexController::class, 'config'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.config');
Route::get('index/policy', [ApiIndexController::class, 'policy'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.config');

// 会员退出是已认证会话的撤销，不再把它登记为无认证的空操作。
if (in_array('peanut.member', $selectedModules, true)) {
    Route::post('login/logout', [ApiLoginController::class, 'logout'])
        ->middleware(CheckTokenMiddleware::class);
}

if (in_array('peanut.article', $selectedModules, true)) {
    Route::get('article/cate', [ApiArticleController::class, 'cate'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.cate');
    Route::get('article/lists', [ApiArticleController::class, 'lists'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.lists');
    Route::get('article/detail', [ApiArticleController::class, 'detail'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.detail');
}

Route::get('search/hotLists', [ApiSearchController::class, 'hotLists'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.hot-search.public-read', '', 'hot-search.lists');

// 装修消费（匿名只读，保存后立即生效）
Route::get('decoration/mobile', [ApiDecorationController::class, 'mobilePage'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.mobile-page');
Route::get('decoration/tabbar', [ApiDecorationController::class, 'tabbar'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.config');
Route::get('decoration/pc', [ApiDecorationController::class, 'pcPage'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.pc-page');

// PC 端聚合（公开）
Route::get('pc/config', [ApiPcController::class, 'config'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.config');
if (in_array('peanut.article', $selectedModules, true)) {
    Route::get('pc/index', [ApiPcController::class, 'index'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.pc-index');
    Route::get('pc/infoCenter', [ApiPcController::class, 'infoCenter'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.info-center');
    Route::get('pc/articleDetail', [ApiPcController::class, 'articleDetail'])
        ->middleware(PublicTenantModuleMiddleware::class, 'peanut.article.public-read', 'peanut.article', 'article.pc-detail');
}

// ─── 需登录接口（挂 CheckTokenMiddleware） ──────────────────────────────────
if (in_array('peanut.article', $selectedModules, true) && in_array('peanut.member', $selectedModules, true)) {
    Route::group(function () {
        // 文章收藏
        Route::post('article/addCollect', [ApiArticleController::class, 'addCollect']);
        Route::post('article/cancelCollect', [ApiArticleController::class, 'cancelCollect']);
        Route::get('article/collect', [ApiArticleController::class, 'collect']);

    })->middleware(CheckTokenMiddleware::class)
        ->middleware(OfficialModuleMiddleware::class, 'peanut.article', 'http.member');
}
unset($selectedModules);
