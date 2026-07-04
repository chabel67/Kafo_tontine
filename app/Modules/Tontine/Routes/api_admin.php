<?php

use App\Modules\Tontine\Http\Controllers\AdminCampaignController;
use App\Modules\Tontine\Http\Controllers\AdminCampaignPayoutController;
use App\Modules\Tontine\Http\Controllers\AdminMarketCalendarController;
use App\Modules\Tontine\Http\Controllers\AdminMemberController;
use App\Modules\Tontine\Http\Controllers\AdminMembershipController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    // Campagnes
    Route::prefix('admin/campaigns')->group(function () {
        Route::get('/',                       [AdminCampaignController::class, 'index']);
        Route::post('/',                      [AdminCampaignController::class, 'store']);
        Route::get('/{campaign}',             [AdminCampaignController::class, 'show']);
        Route::patch('/{campaign}',           [AdminCampaignController::class, 'update']);
        Route::patch('/{campaign}/status',    [AdminCampaignController::class, 'transition']);
        Route::get('/{campaign}/preview',     [AdminCampaignController::class, 'installmentPreview']);

        // Clôture campagne — génère les payouts (R-CAMP-08).
        Route::get('/{campaign}/close/preview', [AdminCampaignPayoutController::class, 'preview']);
        Route::post('/{campaign}/close',        [AdminCampaignPayoutController::class, 'close'])
            ->middleware('step_up');
    });

    // Payouts de clôture
    Route::prefix('admin/campaign-payouts')->group(function () {
        Route::get('/',                    [AdminCampaignPayoutController::class, 'index']);
        Route::get('/{payout}',            [AdminCampaignPayoutController::class, 'show']);
        Route::post('/{payout}/settle',    [AdminCampaignPayoutController::class, 'settle'])
            ->middleware('step_up');
        Route::post('/{payout}/cancel',    [AdminCampaignPayoutController::class, 'cancel'])
            ->middleware(['permission:loan.writeoff', 'step_up']);
    });

    // Calendriers de marché
    Route::prefix('admin/market-calendars')->group(function () {
        Route::get('/',                          [AdminMarketCalendarController::class, 'index']);
        Route::post('/',                         [AdminMarketCalendarController::class, 'store']);
        Route::get('/{marketCalendar}',          [AdminMarketCalendarController::class, 'show']);
        Route::patch('/{marketCalendar}',        [AdminMarketCalendarController::class, 'update']);
    });

    // Adhésions
    Route::prefix('admin/memberships')->group(function () {
        Route::get('/',                               [AdminMembershipController::class, 'index']);
        Route::post('/campaigns/{campaign}/enroll',   [AdminMembershipController::class, 'enroll']);
        Route::get('/{membership}',                   [AdminMembershipController::class, 'show']);
        Route::post('/{membership}/validate-l1',      [AdminMembershipController::class, 'validateL1']);
        Route::post('/{membership}/validate-l2',      [AdminMembershipController::class, 'validateL2']);
        Route::post('/{membership}/suspend',          [AdminMembershipController::class, 'suspend']);
        Route::post('/{membership}/reactivate',       [AdminMembershipController::class, 'reactivate']);
        Route::get('/{membership}/installments',      [AdminMembershipController::class, 'installments']);
    });

    // Membres (utilisateurs)
    Route::prefix('admin/members')->group(function () {
        Route::get('/',                                            [AdminMemberController::class, 'index']);
        Route::post('/',                                           [AdminMemberController::class, 'store']);
        Route::get('/{user}',                                      [AdminMemberController::class, 'show']);
        Route::get('/{user}/kyc',                                  [AdminMemberController::class, 'kycDocuments']);
        Route::get('/{user}/kyc/document/{level}/{type}',          [AdminMemberController::class, 'kycServeDocument'])
            ->name('admin.kyc.document');
        Route::post('/{user}/kyc/approve',                         [AdminMemberController::class, 'approveKyc']);
        Route::post('/{user}/kyc/reject',                          [AdminMemberController::class, 'rejectKyc']);
    });
});
