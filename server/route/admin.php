<?php

declare(strict_types=1);

use app\adminapi\controller\auth\AdminController;
use app\adminapi\controller\auth\LoginController;
use app\adminapi\controller\auth\MenuController;
use app\adminapi\controller\auth\RoleController;
use app\adminapi\controller\WorkbenchController;
use app\adminapi\controller\config\ConfigController;
use app\adminapi\controller\config\ReadinessController;
use app\adminapi\controller\dept\DeptController;
use app\adminapi\controller\dept\JobsController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictTypeController;
use PeanutAdmin\Modules\ReferenceCodes\Controller\DictDataController;
use app\adminapi\controller\generator\GeneratorController;
use app\adminapi\controller\system\SystemController;
use app\adminapi\controller\setting\HotSearchController;
use app\adminapi\controller\setting\TransactionSettingsController;
use app\adminapi\controller\decoration\DecorationPageController;
use app\adminapi\controller\decoration\DecorationTabbarController;
use app\adminapi\controller\log\OperationLogController;
use app\adminapi\http\middleware\AuthMiddleware;
use app\adminapi\http\middleware\LoginMiddleware;
use app\adminapi\http\middleware\OperationLogMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

// Anonymous Admin branding uses its own Host boundary, separate from Member APIs.
Route::get('config/brand', [ConfigController::class, 'brand']);

// ─── 管理端会话与菜单路由（仅需登录，不做 RBAC） ───────────────────────────
Route::group(function () {
    Route::post('user/info', [LoginController::class, 'info']);
    Route::post('user/menu', [MenuController::class, 'route']);
})->middleware(LoginMiddleware::class);

// ─── 管理后台完整 API（Login → Auth 两层中间件） ─────────────────────────────
Route::group(function () use ($peanutRouteModules) {
    Route::get('core.tenant.license', [\app\adminapi\controller\auth\TenantLicenseController::class, 'detail'])
        ->completeMatch();
    Route::get('login/info', [LoginController::class, 'info']);

    // 工作台
    Route::get('workbench/index', [WorkbenchController::class, 'index']);

    // 菜单
    Route::get('menu/route', [MenuController::class, 'route']);
    Route::get('menu/lists', [MenuController::class, 'lists']);
    Route::get('menu/all', [MenuController::class, 'all']);
    Route::get('menu/detail', [MenuController::class, 'detail']);
    Route::post('menu/add', [MenuController::class, 'add']);
    Route::post('menu/edit', [MenuController::class, 'edit']);
    Route::post('menu/delete', [MenuController::class, 'delete']);
    Route::post('menu/status', [MenuController::class, 'updateStatus']);

    // 角色
    Route::get('role/lists', [RoleController::class, 'lists'])->option(['peanut_permission' => 'core.role.read']);
    Route::get('role/all', [RoleController::class, 'all'])->option(['peanut_permission' => 'core.role.read']);
    Route::get('role/detail', [RoleController::class, 'detail'])->option(['peanut_permission' => 'core.role.read']);
    Route::post('role/add', [RoleController::class, 'add'])->option(['peanut_permission' => 'core.role.create']);
    Route::post('role/edit', [RoleController::class, 'edit'])->option(['peanut_permission' => 'core.role.update']);
    Route::post('role/delete', [RoleController::class, 'delete'])->option(['peanut_permission' => 'core.role.archive']);
    Route::get('role/permissions', [\app\adminapi\controller\auth\RolePermissionController::class, 'detail'])
        ->completeMatch()->option(['peanut_permission' => 'core.role.read']);
    Route::put('role/permissions', [\app\adminapi\controller\auth\RolePermissionController::class, 'replace'])
        ->completeMatch()->option(['peanut_permission' => 'core.role.permission.assign']);
    Route::get('role/permissions/catalog', [\app\adminapi\controller\auth\RolePermissionController::class, 'catalog'])
        ->completeMatch()->option(['peanut_permission' => 'core.permission.read']);
    Route::get('permission/bundles/apis', [\app\adminapi\controller\auth\RolePermissionController::class, 'apis'])
        ->completeMatch()->option(['peanut_permission' => 'core.permission.read']);
    Route::get('role/data-policy', [\app\adminapi\controller\auth\DataPolicyController::class, 'detail'])
        ->completeMatch()->option(['peanut_permission' => 'core.role.data-policy.read']);
    Route::put('role/data-policy', [\app\adminapi\controller\auth\DataPolicyController::class, 'replace'])
        ->completeMatch()->option(['peanut_permission' => 'core.role.data-policy.manage']);
    Route::get('role/data-policy/catalog', [\app\adminapi\controller\auth\DataPolicyController::class, 'catalog'])
        ->completeMatch()->option(['peanut_permission' => 'core.role.data-policy.read']);

    // 管理员
    Route::get('admin/lists', [AdminController::class, 'lists'])->option(['peanut_permission' => 'core.member.read']);
    Route::get('admin/detail', [AdminController::class, 'detail'])->option(['peanut_permission' => 'core.member.read']);
    Route::get('admin/self', [AdminController::class, 'self']);
    Route::post('admin/editSelf', [AdminController::class, 'editSelf']);
    Route::post('admin/add', [AdminController::class, 'add'])->option(['peanut_permission' => 'core.member.create']);
    Route::post('admin/edit', [AdminController::class, 'edit'])->option(['peanut_permission' => 'core.member.update']);
    Route::post('admin/delete', [AdminController::class, 'delete'])->option(['peanut_permission' => 'core.member.leave']);
    Route::post('admin/status', [AdminController::class, 'updateStatus'])->option(['peanut_permission' => 'core.member.update']);

    // 部门
    Route::get('dept/lists', [DeptController::class, 'lists'])->option(['peanut_permission' => 'core.department.read']);
    Route::get('dept/all', [DeptController::class, 'all'])->option(['peanut_permission' => 'core.department.read']);
    Route::get('dept/leaderDept', [DeptController::class, 'leaderDept'])->option(['peanut_permission' => 'core.department.read']);
    Route::get('dept/leader-candidates', [DeptController::class, 'leaderCandidates'])->completeMatch()->option(['peanut_permission' => 'core.department.update']);
    Route::get('dept/detail', [DeptController::class, 'detail'])->option(['peanut_permission' => 'core.department.read']);
    Route::post('dept/add', [DeptController::class, 'add'])->option(['peanut_permission' => 'core.department.create']);
    Route::post('dept/edit', [DeptController::class, 'edit'])->option(['peanut_permission' => 'core.department.update']);
    Route::post('dept/delete', [DeptController::class, 'delete'])->option(['peanut_permission' => 'core.department.archive']);
    Route::post('dept/status', [DeptController::class, 'updateStatus'])->option(['peanut_permission' => 'core.department.update']);

    // 岗位
    Route::get('jobs/lists', [JobsController::class, 'lists'])->option(['peanut_permission' => 'core.job.read']);
    Route::get('jobs/all', [JobsController::class, 'all'])->option(['peanut_permission' => 'core.job.read']);
    Route::get('jobs/detail', [JobsController::class, 'detail'])->option(['peanut_permission' => 'core.job.read']);
    Route::post('jobs/add', [JobsController::class, 'add'])->option(['peanut_permission' => 'core.job.create']);
    Route::post('jobs/edit', [JobsController::class, 'edit'])->option(['peanut_permission' => 'core.job.update']);
    Route::post('jobs/delete', [JobsController::class, 'delete'])->option(['peanut_permission' => 'core.job.archive']);
    Route::post('jobs/status', [JobsController::class, 'updateStatus'])->option(['peanut_permission' => 'core.job.update']);

    // 字典类型
    Route::get('dict/type/lists', [DictTypeController::class, 'lists'])->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::get('dict/type/all', [DictTypeController::class, 'all'])->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::get('dict/type/detail', [DictTypeController::class, 'detail'])->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::post('dict/type/add', [DictTypeController::class, 'add'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('dict/type/edit', [DictTypeController::class, 'edit'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('dict/type/delete', [DictTypeController::class, 'delete'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('dict/type/status', [DictTypeController::class, 'updateStatus'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);

    // 字典数据
    Route::get('dict/data/lists', [DictDataController::class, 'lists'])->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::get('dict/data/byType', [DictDataController::class, 'byType'])->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::get('dict/data/detail', [DictDataController::class, 'detail'])->option(['peanut_permission' => 'peanut.reference-codes.read']);
    Route::post('dict/data/add', [DictDataController::class, 'add'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('dict/data/edit', [DictDataController::class, 'edit'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('dict/data/delete', [DictDataController::class, 'delete'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);
    Route::post('dict/data/status', [DictDataController::class, 'updateStatus'])->option(['peanut_permission' => 'peanut.reference-codes.manage']);

    // 开发工具 - 安全代码生成器
    Route::get('generator/source-tables', [GeneratorController::class, 'sourceTables']);
    Route::get('generator/lists', [GeneratorController::class, 'lists']);
    Route::get('generator/detail', [GeneratorController::class, 'detail']);
    Route::post('generator/import', [GeneratorController::class, 'import']);
    Route::post('generator/sync', [GeneratorController::class, 'sync']);
    Route::post('generator/update', [GeneratorController::class, 'update']);
    Route::post('generator/delete', [GeneratorController::class, 'delete']);
    Route::post('generator/preview', [GeneratorController::class, 'preview']);
    Route::post('generator/generate', [GeneratorController::class, 'generate']);
    Route::get('generator/download', [GeneratorController::class, 'download']);
    Route::get('generator/models', [GeneratorController::class, 'models']);

    // 系统维护
    Route::get('system/info', [SystemController::class, 'info']);
    Route::post('system/clearCache', [SystemController::class, 'clearCache']);

    // 操作日志
    Route::get('log/lists', [OperationLogController::class, 'lists'])->option(['peanut_permission' => 'core.audit.read']);
    Route::post('log/clear', [OperationLogController::class, 'clear']);
    // 系统配置 - 网站设置
    Route::get('config/website', [ConfigController::class, 'getWebsite'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('config/website/save', [ConfigController::class, 'saveWebsite'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('config/copyright', [ConfigController::class, 'getCopyright'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('config/copyright/save', [ConfigController::class, 'saveCopyright'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('config/agreement', [ConfigController::class, 'getAgreement'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('config/agreement/save', [ConfigController::class, 'saveAgreement'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('config/statistics', [ConfigController::class, 'getStatistics'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('config/statistics/save', [ConfigController::class, 'saveStatistics'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('config/user', [ConfigController::class, 'getUser'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('config/user/save', [ConfigController::class, 'saveUser'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('config/login', [ConfigController::class, 'getLogin'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('config/login/save', [ConfigController::class, 'saveLogin'])->option(['peanut_permission' => 'peanut.settings.manage']);

    // 应用设置 - 首次运行生产准备清单（只读，不执行外部探测）
    Route::get('readiness/checklist', [ReadinessController::class, 'checklist'])->option(['peanut_permission' => 'peanut.settings.read']);

    // 应用设置 - 热门搜索
    Route::get('setting/hot-search/config', [HotSearchController::class, 'getConfig'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('setting/hot-search/save', [HotSearchController::class, 'setConfig'])->option(['peanut_permission' => 'peanut.settings.manage']);

    // 应用设置 - 交易设置
    Route::get('setting/transaction/config', [TransactionSettingsController::class, 'getConfig'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('setting/transaction/save', [TransactionSettingsController::class, 'setConfig'])->option(['peanut_permission' => 'peanut.settings.manage']);

    // 装修管理：移动端、Tabbar 与 PC 权限域严格分离
    Route::get('decoration/mobile/page/lists', [DecorationPageController::class, 'mobileLists'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::get('decoration/mobile/page/detail', [DecorationPageController::class, 'mobileDetail'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('decoration/mobile/page/save', [DecorationPageController::class, 'mobileSave'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('decoration/tabbar/detail', [DecorationTabbarController::class, 'detail'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('decoration/tabbar/save', [DecorationTabbarController::class, 'save'])->option(['peanut_permission' => 'peanut.settings.manage']);
    Route::get('decoration/pc/page/lists', [DecorationPageController::class, 'pcLists'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::get('decoration/pc/page/detail', [DecorationPageController::class, 'pcDetail'])->option(['peanut_permission' => 'peanut.settings.read']);
    Route::post('decoration/pc/page/save', [DecorationPageController::class, 'pcSave'])->option(['peanut_permission' => 'peanut.settings.manage']);
    if (isset($peanutRouteModules['peanut.article'])) {
        Route::get('decoration/mobile/article', [DecorationPageController::class, 'article']);
    }

})->middleware([LoginMiddleware::class, AuthMiddleware::class, OperationLogMiddleware::class]);
