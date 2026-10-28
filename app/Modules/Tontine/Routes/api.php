<?php

use App\Modules\Tontine\Http\Controllers\MemberCampaignPayoutController;
use App\Modules\Tontine\Http\Controllers\MemberDashboardController;
use App\Modules\Tontine\Http\Controllers\MemberEarlyWithdrawalController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('member')->group(function () {
    Route::get('dashboard',                            [MemberDashboardController::class, 'dashboard']);
    Route::get('memberships',                          [MemberDashboardController::class, 'memberships']);
    Route::get('memberships/{membership}/installments', [MemberDashboardController::class, 'installments']);

    // Payouts de clôture — vue membre
    Route::get('campaign-payouts',           [MemberCampaignPayoutController::class, 'myPayouts']);
    Route::get('campaign-payouts/{payout}',  [MemberCampaignPayoutController::class, 'show']);

    // Retraits anticipés — vue membre (uniquement ses propres adhésions / demandes)
    Route::get('early-withdrawals',                       [MemberEarlyWithdrawalController::class, 'index']);
    Route::post('early-withdrawals',                      [MemberEarlyWithdrawalController::class, 'store']);
    Route::post('early-withdrawals/{withdrawal}/cancel',  [MemberEarlyWithdrawalController::class, 'cancel']);
    Route::post('early-withdrawals/{withdrawal}/respond', [MemberEarlyWithdrawalController::class, 'respond']);
    Route::get('memberships/{membership}/withdrawable',   [MemberEarlyWithdrawalController::class, 'withdrawable']);
});
