<?php

use App\Modules\Audit\Http\Controllers\AdminAuditController;
use App\Modules\Audit\Http\Controllers\AdminSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('audit-logs',          [AdminAuditController::class, 'index']);
    Route::get('audit-logs/actions',  [AdminAuditController::class, 'distinctActions']);
    Route::get('audit-logs/{id}',     [AdminAuditController::class, 'show']);
    Route::get('exports/audit',       [AdminAuditController::class, 'export']);

    Route::get('settings',            [AdminSettingsController::class, 'index']);
    Route::patch('settings',          [AdminSettingsController::class, 'update']);

    Route::get('cash/summary',        [AdminSettingsController::class, 'cashSummary']);
    Route::post('cash/close',         [AdminSettingsController::class, 'closeDay']);
});
