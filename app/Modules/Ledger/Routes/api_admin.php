<?php

use App\Modules\Ledger\Http\Controllers\AdminLedgerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin/ledger')->group(function () {
    Route::get('/accounts',                          [AdminLedgerController::class, 'accounts'])
        ->middleware('permission:treasury.view');
    Route::get('/accounts/{key}/entries',            [AdminLedgerController::class, 'entries'])
        ->where('key', '.+')
        ->middleware('permission:treasury.view');
    // Journal des opérations — vue chronologique cross-compte (R-LEDGER-06)
    Route::get('/journal',                           [AdminLedgerController::class, 'journal'])
        ->middleware('permission:treasury.view');
    Route::get('/transactions/{id}',                 [AdminLedgerController::class, 'transaction'])
        ->middleware('permission:treasury.view');
    // Contre-passation = super_admin uniquement (R-LEDGER-01).
    Route::post('/transactions/{id}/reverse',        [AdminLedgerController::class, 'reverse'])
        ->middleware(['permission:ledger.reverse', 'step_up']);
});
