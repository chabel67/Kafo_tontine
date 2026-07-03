<?php

use App\Modules\Identity\Http\Controllers\MemberAuthController;
use App\Modules\Identity\Http\Controllers\MemberKycController;
use App\Modules\Identity\Http\Controllers\MemberProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('otp/request', [MemberAuthController::class, 'requestOtp']);
    Route::post('login',       [MemberAuthController::class, 'login']);
    Route::post('pin/set',     [MemberAuthController::class, 'setPin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me',      [MemberAuthController::class, 'me']);
        Route::post('logout', [MemberAuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('member/profile')->group(function () {
    Route::post('photo',       [MemberProfileController::class, 'uploadPhoto']);
    Route::post('pin',         [MemberProfileController::class, 'changePin']);
});

Route::middleware('auth:sanctum')->prefix('member/kyc')->group(function () {
    Route::get('status',  [MemberKycController::class, 'status']);
    Route::post('submit', [MemberKycController::class, 'submit']);
});