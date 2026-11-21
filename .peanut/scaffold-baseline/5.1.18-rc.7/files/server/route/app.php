<?php

declare(strict_types=1);

use app\adminapi\controller\auth\LoginController;
use app\installation\controller\InstallationController;
use think\facade\Route;

if (($peanutRouteApplication ?? null) === 'installation') {
    Route::get('page', [InstallationController::class, 'page']);
    Route::get('environment', [InstallationController::class, 'environment']);
    Route::get('database-check', [InstallationController::class, 'databaseCheck']);
    Route::get('password-policy', [InstallationController::class, 'passwordPolicy']);
    Route::get('configuration', [InstallationController::class, 'configuration']);
    Route::post('configure', [InstallationController::class, 'configure']);
    Route::get('status', [InstallationController::class, 'status']);
    Route::get('entry-status', [InstallationController::class, 'entryStatus']);
    Route::post('execute', [InstallationController::class, 'execute']);
}

if (($peanutRouteApplication ?? null) === 'adminapi') {
    Route::post('user/login', [LoginController::class, 'login']);
    Route::post('user/logout', [LoginController::class, 'logout']);
}
