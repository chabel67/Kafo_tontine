<?php

use App\Modules\Payments\Http\Controllers\AdminPaymentController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks PSP (sans auth — signature vérifiée dans le handler)
Route::post('/webhooks/psp/{channel}', [WebhookController::class, 'handle']);
Route::post('/webhooks/kkiapay',       [WebhookController::class, 'handleKkiapay']);
Route::post('/webhooks/fedapay',       [WebhookController::class, 'handleFedapay']);

// B-13 — permissions (matrice RolesSeeder). Pas de route « unblock » à ce jour
// (`payment.unblock` est seedée mais sans endpoint).
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('admin/payments')->group(function () {
        Route::get('/',                        [AdminPaymentController::class, 'index'])
            ->middleware('permission:payment.view');
        // Encaissement cash : même permission que la caisse du jour et sa clôture.
        Route::post('/cash',                   [AdminPaymentController::class, 'recordCash'])
            ->middleware('permission:cash.validate');
        Route::post('/momo',                   [AdminPaymentController::class, 'initiateMomo'])
            ->middleware('permission:payment.validate');
        Route::get('/{payment}',               [AdminPaymentController::class, 'show'])
            ->middleware('permission:payment.view');
        Route::post('/{payment}/confirm',      [AdminPaymentController::class, 'confirm'])
            ->middleware('permission:payment.confirm');
        Route::post('/{payment}/cancel',       [AdminPaymentController::class, 'cancel'])
            ->middleware('permission:payment.cancel');
    });
});
