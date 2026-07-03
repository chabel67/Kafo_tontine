<?php

use App\Modules\Tontine\Http\Controllers\MemberDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('member')->group(function () {
    Route::get('dashboard',                            [MemberDashboardController::class, 'dashboard']);
    Route::get('memberships',                          [MemberDashboardController::class, 'memberships']);
    Route::get('memberships/{membership}/installments', [MemberDashboardController::class, 'installments']);
});
