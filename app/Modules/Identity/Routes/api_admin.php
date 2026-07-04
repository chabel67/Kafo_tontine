<?php

use App\Modules\Identity\Http\Controllers\AdminAuthController;
use App\Modules\Identity\Http\Controllers\AdminUsersController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->group(function () {
    Route::post('otp/request', [AdminAuthController::class, 'requestOtp']);
    Route::post('login',       [AdminAuthController::class, 'login']);
    Route::post('pin/set',     [AdminAuthController::class, 'setupPin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('step-up', [AdminAuthController::class, 'stepUp']);
        Route::get('me',       [AdminAuthController::class, 'me']);
        Route::post('logout',  [AdminAuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('staff',                              [AdminUsersController::class, 'index']);
    Route::post('staff',                             [AdminUsersController::class, 'create']);
    Route::get('staff/roles',                        [AdminUsersController::class, 'roles']);
    Route::get('staff/{user}',                       [AdminUsersController::class, 'show']);
    Route::post('staff/{user}/assign-role',          [AdminUsersController::class, 'assignRole']);
    Route::delete('staff/{user}/roles/{role}',       [AdminUsersController::class, 'removeRole']);
    Route::post('staff/{user}/deactivate',           [AdminUsersController::class, 'deactivate']);
    Route::post('staff/{user}/reactivate',           [AdminUsersController::class, 'reactivate']);
});
