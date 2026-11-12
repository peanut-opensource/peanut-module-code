<?php

declare(strict_types=1);

use app\api\controller\IndexController as ApiIndexController;
use app\api\controller\SearchController as ApiSearchController;
use app\api\controller\PcController as ApiPcController;
use app\api\controller\DecorationController as ApiDecorationController;
use app\api\middleware\PublicTenantModuleMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'api') {
    return;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 用户端 API（/api/user/ 和 /api/  命名空间）
// 公开接口无中间件；需登录接口挂 CheckTokenMiddleware
// ═══════════════════════════════════════════════════════════════════════════════

// ─── 公开接口（无需 token） ────────────────────────────────────────────────────
Route::get('index/config', [ApiIndexController::class, 'config'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.config');
Route::get('index/policy', [ApiIndexController::class, 'policy'])
    ->middleware(PublicTenantModuleMiddleware::class, 'peanut.decoration.public-read', '', 'decoration.config');


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
