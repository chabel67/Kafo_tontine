<?php

use App\Modules\Notifications\Http\Controllers\AdminNotificationController;
use Illuminate\Support\Facades\Route;

// B-13 — centre de notifications du personnel. Aucune permission « staff » générique n'est
// seedée : `member.view` OU `payment.view` couvre tous les rôles internes (trésorier,
// gestionnaire, admin ; super-admin par wildcard) et exclut les membres, qui ont leur
// propre route /member/notifications (les diffusions staff, user_id NULL, ne les concernent pas).
Route::middleware(['auth:sanctum', 'permission:member.view,payment.view'])->prefix('admin/notifications')->group(function () {
    Route::get('/',             [AdminNotificationController::class, 'index']);
    Route::get('/count',        [AdminNotificationController::class, 'count']);
    Route::post('/read-all',    [AdminNotificationController::class, 'markAllRead']);
    Route::post('/{id}/read',   [AdminNotificationController::class, 'markRead']);
});
