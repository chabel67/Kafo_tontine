<?php

use App\Modules\Payments\Http\Controllers\MemberPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('member')->group(function () {
    Route::post('payments/initiate', [MemberPaymentController::class, 'initiate']);
    Route::get('transactions',       [MemberPaymentController::class, 'transactions']);
});
