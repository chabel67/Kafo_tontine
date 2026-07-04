<?php

use App\Modules\Audit\Http\Controllers\AdminAuditController;
use App\Modules\Audit\Http\Controllers\AdminSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('audit-logs',          [AdminAuditController::class, 'index'])
        ->middleware('permission:audit.view');
    Route::get('audit-logs/actions',  [AdminAuditController::class, 'distinctActions'])
        ->middleware('permission:audit.view');
    Route::get('audit-logs/{id}',     [AdminAuditController::class, 'show'])
        ->middleware('permission:audit.view');
    Route::get('exports/audit',       [AdminAuditController::class, 'export'])
        ->middleware('permission:audit.view');

    Route::get('settings',            [AdminSettingsController::class, 'index'])
        ->middleware('permission:settings.edit');
    Route::patch('settings',          [AdminSettingsController::class, 'update'])
        ->middleware(['permission:settings.edit', 'step_up']);

    Route::get('cash/summary',        [AdminSettingsController::class, 'cashSummary'])
        ->middleware('permission:cash.validate');
    Route::post('cash/close',         [AdminSettingsController::class, 'closeDay'])
        ->middleware('permission:cash.validate');
});
