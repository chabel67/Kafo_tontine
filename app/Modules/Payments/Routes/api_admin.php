<?php

use App\Modules\Payments\Http\Controllers\AdminPaymentController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks PSP (sans auth — signature PSP à valider en prod)
Route::post('/webhooks/psp/{channel}', [WebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('admin/payments')->group(function () {
        Route::get('/',                        [AdminPaymentController::class, 'index']);
        Route::post('/cash',                   [AdminPaymentController::class, 'recordCash']);
        Route::post('/momo',                   [AdminPaymentController::class, 'initiateMomo']);
        Route::get('/{payment}',               [AdminPaymentController::class, 'show']);
        Route::post('/{payment}/confirm',      [AdminPaymentController::class, 'confirm']);
        Route::post('/{payment}/cancel',       [AdminPaymentController::class, 'cancel']);
    });
});
