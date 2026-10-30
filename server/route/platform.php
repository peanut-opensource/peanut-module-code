<?php

declare(strict_types=1);

use app\platform\controller\PlatformSessionController;
use app\platform\controller\PlatformAccessController;
use app\platform\controller\PlatformTenantBoundaryController;
use app\platform\controller\PlatformTenantController;
use app\platform\controller\PlatformControlPlaneQueryController;
use app\platform\controller\PlatformOpsController;
use app\platform\controller\PlatformTenantInvitationController;
use app\platform\controller\PlatformTenantEntryBindingController;
use app\platform\controller\PlatformModuleLifecycleController;
use app\platform\controller\PlatformDeveloperCenterController;
use app\platform\http\middleware\PlatformLoginMiddleware;
use app\platform\http\middleware\PlatformHostMiddleware;
use app\platform\http\middleware\PlatformPermissionMiddleware;
use app\platform\http\middleware\PlatformInstanceToolMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'platform') {
    return;
}

// Instance-local platform control plane. It never shares admin sessions, RBAC or routes.
Route::get('permission/menus', [\app\platform\controller\PermissionApiController::class, 'menus'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.read');
Route::put('permission/menus/binding', [\app\platform\controller\PermissionApiController::class, 'replaceMenuBinding'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.api.manage');
Route::get('permission/bundles', [\app\platform\controller\PermissionApiController::class, 'bundles'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.read');
Route::post('permission/bundles', [\app\platform\controller\PermissionApiController::class, 'create'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.api.manage');
Route::put('permission/bundles', [\app\platform\controller\PermissionApiController::class, 'update'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.api.manage');
Route::get('permission/apis/bindings', [\app\platform\controller\PermissionApiController::class, 'detail'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.read');
Route::put('permission/apis/bindings', [\app\platform\controller\PermissionApiController::class, 'replace'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.api.manage');
Route::get('permission/apis', [\app\platform\controller\PermissionApiController::class, 'apis'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.read');

Route::post('session/login', [PlatformSessionController::class, 'login'])
    ->middleware(PlatformHostMiddleware::class);
Route::post('session/refresh', [PlatformSessionController::class, 'refresh'])
    ->middleware(PlatformHostMiddleware::class);
Route::post('session/logout', [PlatformSessionController::class, 'logout'])
    ->middleware(PlatformHostMiddleware::class);
Route::get('session/info', [PlatformSessionController::class, 'info'])
    ->middleware(PlatformLoginMiddleware::class);
Route::get('tenants/capabilities', [PlatformTenantBoundaryController::class, 'capabilities'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::get('tenants/detail', [PlatformTenantController::class, 'detail'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::post('tenants/provision', [PlatformTenantInvitationController::class, 'provision'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.create');
Route::post('tenants/invitations/resend', [PlatformTenantInvitationController::class, 'resend'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.provision-owner');
Route::post('tenants/invitations/revoke', [PlatformTenantInvitationController::class, 'revoke'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.provision-owner');
// ThinkPHP routes are prefix-sensitive: register invitation actions before the collection route.
Route::post('tenants/invitations', [PlatformTenantInvitationController::class, 'invite'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.provision-owner');
Route::get('tenants/invitations', [PlatformTenantInvitationController::class, 'lists'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.provision-owner');
Route::get('tenants/owner', [PlatformControlPlaneQueryController::class, 'owner'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::get('operators', [PlatformControlPlaneQueryController::class, 'operators'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.read');
Route::get('roles', [PlatformControlPlaneQueryController::class, 'roles'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.role.read');
Route::get('permissions', [PlatformControlPlaneQueryController::class, 'permissions'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.permission.read');
Route::get('audit', [PlatformControlPlaneQueryController::class, 'audit'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.audit.read');
Route::get('tenants/modules', [PlatformControlPlaneQueryController::class, 'moduleStates'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::get('instance-tools/modules', [PlatformModuleLifecycleController::class, 'lists'])
    ->completeMatch()
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.read')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::get('developer-center/catalog', [PlatformDeveloperCenterController::class, 'catalog'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.read');
Route::post('instance-tools/modules/create', [PlatformModuleLifecycleController::class, 'create'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.create')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::post('instance-tools/modules/install', [PlatformModuleLifecycleController::class, 'install'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.install')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::post('instance-tools/modules/update', [PlatformModuleLifecycleController::class, 'update'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.update')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::get('instance-tools/modules/tasks/:task_key', [PlatformModuleLifecycleController::class, 'task'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.read');
Route::post('instance-tools/modules/uninstall', [PlatformModuleLifecycleController::class, 'uninstall'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.uninstall')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::post('instance-tools/modules/disable', [PlatformModuleLifecycleController::class, 'disable'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.disable')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::post('instance-tools/modules/sync', [PlatformModuleLifecycleController::class, 'sync'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.module.sync')
    ->middleware(PlatformInstanceToolMiddleware::class);
Route::get('tenant-entry-bindings', [PlatformTenantEntryBindingController::class, 'lists'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::post('tenant-entry-bindings/enable', [PlatformTenantEntryBindingController::class, 'enable'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.update');
Route::get('v1/ops/status', [PlatformOpsController::class, 'status'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/upgrade-readiness', [PlatformOpsController::class, 'upgradeReadiness'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/providers', [PlatformOpsController::class, 'providers'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/maintenance', [PlatformOpsController::class, 'maintenance'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/logs', [PlatformOpsController::class, 'logs'])
    ->option(['peanut_permission' => 'platform.ops.logs.read'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read')
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.logs.read');
Route::put('v1/ops/maintenance', [PlatformOpsController::class, 'scheduleMaintenance'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
Route::post('v1/ops/maintenance/:maintenance_key/close', [PlatformOpsController::class, 'closeMaintenance'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.maintenance.manage');
Route::get('v1/ops/diagnostics', [PlatformOpsController::class, 'diagnostics'])
    ->option(['peanut_permission' => 'platform.ops.logs.read'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read')
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.logs.read');
Route::post('v1/ops/tasks/backup', [PlatformOpsController::class, 'backup'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.backup.manage');
Route::post('v1/ops/tasks/restore', [PlatformOpsController::class, 'restore'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.restore.manage');
Route::post('v1/ops/tasks/upgrade', [PlatformOpsController::class, 'upgrade'])
    ->option(['peanut_permission' => 'platform.ops.upgrade.manage'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read')
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.upgrade.manage');
Route::post('v1/ops/tasks/module', [PlatformOpsController::class, 'moduleOperation'])
    ->option(['peanut_permission' => 'platform.ops.module.manage'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read')
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.module.manage');
Route::get('v1/ops/modules', [PlatformOpsController::class, 'moduleOperations'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/upgrades', [PlatformOpsController::class, 'upgrades'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/backups', [PlatformOpsController::class, 'backups'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::get('v1/ops/tasks/:task_key', [PlatformOpsController::class, 'task'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.ops.read');
Route::post('tenant-entry-bindings/disable', [PlatformTenantEntryBindingController::class, 'disable'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.update');
Route::post('tenants/activate', [PlatformTenantController::class, 'activate'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.lifecycle');
Route::post('tenants/suspend', [PlatformTenantController::class, 'suspend'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.lifecycle');
Route::post('tenants/close', [PlatformTenantController::class, 'close'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.lifecycle');
// ThinkPHP routes are prefix-sensitive: register the generic Tenant list after every /tenants/* route.
Route::get('plans', [\app\platform\controller\PlanLicenseController::class, 'lists'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.plan.read');
Route::post('plans', [\app\platform\controller\PlanLicenseController::class, 'create'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.plan.manage');
Route::put('plans', [\app\platform\controller\PlanLicenseController::class, 'update'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.plan.manage');
Route::get('tenants/license', [\app\platform\controller\PlanLicenseController::class, 'license'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::put('tenants/license', [\app\platform\controller\PlanLicenseController::class, 'updateLicense'])
    ->completeMatch()->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.license.manage');
Route::get('tenants', [PlatformTenantController::class, 'lists'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.tenant.read');
Route::post('operators/create', [PlatformAccessController::class, 'createOperator'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.create');
Route::post('operators/update', [PlatformAccessController::class, 'updateOperator'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.update');
Route::post('operators/roles/replace', [PlatformAccessController::class, 'replaceOperatorRoles'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.role.assign');
Route::post('operators/activate', [PlatformAccessController::class, 'activateOperator'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.lifecycle');
Route::post('operators/suspend', [PlatformAccessController::class, 'suspendOperator'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.lifecycle');
Route::post('operators/close', [PlatformAccessController::class, 'closeOperator'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.operator.lifecycle');
Route::post('roles/create', [PlatformAccessController::class, 'createRole'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.role.create');
Route::post('roles/update', [PlatformAccessController::class, 'updateRole'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.role.update');
Route::post('roles/archive', [PlatformAccessController::class, 'archiveRole'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.role.archive');
Route::post('roles/permissions/replace', [PlatformAccessController::class, 'replaceRolePermissions'])
    ->middleware(PlatformLoginMiddleware::class)
    ->middleware(PlatformPermissionMiddleware::class, 'platform.role.permission.assign');
