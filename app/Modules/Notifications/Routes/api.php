<?php

use App\Modules\Notifications\Http\Controllers\MemberNotificationController;
use Illuminate\Support\Facades\Route;

// Notifications de l'app membre — uniquement celles adressées au membre connecté
Route::middleware('auth:sanctum')->prefix('member/notifications')->group(function () {
    Route::get('/',           [MemberNotificationController::class, 'index']);
    Route::get('/count',      [MemberNotificationController::class, 'count']);
    Route::post('/read-all',  [MemberNotificationController::class, 'markAllRead']);
    Route::post('/{id}/read', [MemberNotificationController::class, 'markRead']);
});
