<?php

use App\Modules\Ledger\Http\Controllers\AdminLedgerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin/ledger')->group(function () {
    Route::get('/accounts',                          [AdminLedgerController::class, 'accounts']);
    Route::get('/accounts/{key}/entries',            [AdminLedgerController::class, 'entries'])->where('key', '.+');
    Route::get('/transactions/{id}',                 [AdminLedgerController::class, 'transaction']);
    Route::post('/transactions/{id}/reverse',        [AdminLedgerController::class, 'reverse']);
});
