<?php

declare(strict_types=1);

use app\adminapi\controller\auth\TenantSessionController;
use app\platform\controller\TenantOwnerInvitationPublicController;
use app\platform\http\middleware\PlatformHostMiddleware;
use think\facade\Route;

if (($peanutRouteApplication ?? null) !== 'adminapi') {
    return;
}

// Multi-tenant Admin session boundary. Core owns selection challenges and atomic old-session revocation.
Route::post('tenant/session/login', [TenantSessionController::class, 'login']);
Route::post('tenant/session/select', [TenantSessionController::class, 'select']);
Route::post('tenant/session/switch', [TenantSessionController::class, 'switchChallenge']);
Route::post('tenant/session/refresh', [TenantSessionController::class, 'refresh']);
Route::post('tenant/session/logout', [TenantSessionController::class, 'logout']);
Route::get('tenant/owner-invitations/inspect', [TenantOwnerInvitationPublicController::class, 'inspect'])
    ->middleware(PlatformHostMiddleware::class);
Route::post('tenant/owner-invitations/accept', [TenantOwnerInvitationPublicController::class, 'accept'])
    ->middleware(PlatformHostMiddleware::class);
