<?php

use App\Modules\Reporting\Http\Controllers\AdminExportController;
use App\Modules\Reporting\Http\Controllers\AdminStatsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Dashboard stats
    Route::get('/admin/stats/dashboard',       [AdminStatsController::class, 'dashboard']);
    Route::get('/admin/stats/work-queue',      [AdminStatsController::class, 'workQueue']);
    Route::get('/admin/stats/recent-payments', [AdminStatsController::class, 'recentPayments']);
    Route::get('/admin/stats/reconciliation',        [AdminStatsController::class, 'reconciliation']);
    Route::get('/admin/stats/members-by-campaign',     [AdminStatsController::class, 'membersByCampaign']);
    Route::get('/admin/stats/collections-by-campaign', [AdminStatsController::class, 'collectionsByCampaign']);

    // CSV exports
    Route::get('/admin/exports/ledger',   [AdminExportController::class, 'ledger']);
    Route::get('/admin/exports/members',  [AdminExportController::class, 'members']);
    Route::get('/admin/exports/loans',    [AdminExportController::class, 'loans']);
});
