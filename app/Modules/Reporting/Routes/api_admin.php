<?php

use App\Modules\Reporting\Http\Controllers\AdminExportController;
use App\Modules\Reporting\Http\Controllers\AdminStatsController;
use Illuminate\Support\Facades\Route;

// B-13 — permissions (matrice RolesSeeder).
//  - Tableaux de bord et file de travail : `reporting.view` (admin) OU `payment.view`
//    (trésorier, gestionnaire) — vues opérationnelles quotidiennes (EF-DASH-01/02/04).
//  - Réconciliation (lecture) : `treasury.view`, comme le grand livre.
//  - Exports CSV : `reporting.view` (admin, super-admin) — données nominatives.
Route::middleware('auth:sanctum')->group(function () {
    // Dashboard stats
    Route::middleware('permission:reporting.view,payment.view')->group(function () {
        Route::get('/admin/stats/dashboard',               [AdminStatsController::class, 'dashboard']);
        Route::get('/admin/stats/work-queue',              [AdminStatsController::class, 'workQueue']);
        Route::get('/admin/stats/recent-payments',         [AdminStatsController::class, 'recentPayments']);
        Route::get('/admin/stats/members-by-campaign',     [AdminStatsController::class, 'membersByCampaign']);
        Route::get('/admin/stats/collections-by-campaign', [AdminStatsController::class, 'collectionsByCampaign']);
        Route::get('/admin/stats/trends',                  [AdminStatsController::class, 'trends']);
    });
    Route::get('/admin/stats/reconciliation', [AdminStatsController::class, 'reconciliation'])
        ->middleware('permission:treasury.view');

    // CSV exports
    Route::middleware('permission:reporting.view')->group(function () {
        Route::get('/admin/exports/ledger',   [AdminExportController::class, 'ledger']);
        Route::get('/admin/exports/members',  [AdminExportController::class, 'members']);
        Route::get('/admin/exports/loans',    [AdminExportController::class, 'loans']);
        Route::get('/admin/exports/journal',  [AdminExportController::class, 'journal']);
    });
});
