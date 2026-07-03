<?php

use App\Modules\Notifications\Http\Controllers\AdminNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin/notifications')->group(function () {
    Route::get('/',             [AdminNotificationController::class, 'index']);
    Route::get('/count',        [AdminNotificationController::class, 'count']);
    Route::post('/read-all',    [AdminNotificationController::class, 'markAllRead']);
    Route::post('/{id}/read',   [AdminNotificationController::class, 'markRead']);
});
