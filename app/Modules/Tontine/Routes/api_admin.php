<?php

use App\Modules\Tontine\Http\Controllers\AdminCampaignController;
use App\Modules\Tontine\Http\Controllers\AdminCampaignPayoutController;
use App\Modules\Tontine\Http\Controllers\AdminEarlyWithdrawalController;
use App\Modules\Tontine\Http\Controllers\AdminMarketCalendarController;
use App\Modules\Tontine\Http\Controllers\AdminMemberController;
use App\Modules\Tontine\Http\Controllers\AdminMembershipController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    /*
     * B-13 — permissions (matrice RolesSeeder, inchangée). OU logique entre permissions
     * séparées par une virgule (EnsurePermission).
     *  - Lecture des campagnes : `campaign.view` (gestionnaire, admin) OU `payment.view` :
     *    le trésorier n'a pas `campaign.view` mais lit les campagnes au quotidien (filtres,
     *    encaissement, file des retraits).
     *  - Transitions (ouverture, activation) : `campaign.transition`, réservée au super-admin
     *    (wildcard, cf. RolesSeeder ; R-CAMP-01).
     *  - Clôture (POST /close, step-up) : `campaign.transition` OU `campaign.close`
     *    (admin, trésorier — décision produit du 2026-10-29).
     */

    // Campagnes
    Route::prefix('admin/campaigns')->group(function () {
        Route::middleware('permission:campaign.view,payment.view')->group(function () {
            Route::get('/',                   [AdminCampaignController::class, 'index']);
            Route::get('/{campaign}',         [AdminCampaignController::class, 'show']);
            Route::get('/{campaign}/preview', [AdminCampaignController::class, 'installmentPreview']);
        });
        Route::post('/',                      [AdminCampaignController::class, 'store'])
            ->middleware('permission:campaign.create');
        Route::patch('/{campaign}',           [AdminCampaignController::class, 'update'])
            ->middleware('permission:campaign.manage');
        // R-WDR-08 : autoriser / suspendre les retraits anticipés (motif obligatoire, audité).
        Route::patch('/{campaign}/withdrawal-policy', [AdminCampaignController::class, 'updateWithdrawalPolicy'])
            ->middleware('permission:campaign.manage');
        // `closed` y est refusé (lot A-bis) : la clôture passe par POST /close.
        Route::patch('/{campaign}/status',    [AdminCampaignController::class, 'transition'])
            ->middleware('permission:campaign.transition');

        // Clôture campagne — génère les payouts (R-CAMP-08).
        Route::get('/{campaign}/close/preview', [AdminCampaignPayoutController::class, 'preview'])
            ->middleware('permission:campaign.manage,campaign.transition,campaign.close');
        Route::post('/{campaign}/close',        [AdminCampaignPayoutController::class, 'close'])
            ->middleware(['permission:campaign.transition,campaign.close', 'step_up']);
    });

    // Payouts de clôture
    Route::prefix('admin/campaign-payouts')->group(function () {
        Route::get('/',                    [AdminCampaignPayoutController::class, 'index'])
            ->middleware('permission:campaign.view,payment.view');
        Route::get('/{payout}',            [AdminCampaignPayoutController::class, 'show'])
            ->middleware('permission:campaign.view,payment.view');
        Route::post('/{payout}/settle',    [AdminCampaignPayoutController::class, 'settle'])
            ->middleware(['permission:campaign.manage', 'step_up']);
        Route::post('/{payout}/cancel',    [AdminCampaignPayoutController::class, 'cancel'])
            ->middleware(['permission:loan.writeoff', 'step_up']);
    });

    // Calendriers de marché
    Route::prefix('admin/market-calendars')->group(function () {
        Route::get('/',                          [AdminMarketCalendarController::class, 'index'])
            ->middleware('permission:campaign.view,market_calendar.manage');
        Route::post('/',                         [AdminMarketCalendarController::class, 'store'])
            ->middleware('permission:market_calendar.manage');
        Route::get('/{marketCalendar}',          [AdminMarketCalendarController::class, 'show'])
            ->middleware('permission:campaign.view,market_calendar.manage');
        Route::patch('/{marketCalendar}',        [AdminMarketCalendarController::class, 'update'])
            ->middleware('permission:market_calendar.manage');
    });

    // Adhésions — lecture : member.view (tout le personnel, dont le trésorier à l'encaissement)
    Route::prefix('admin/memberships')->group(function () {
        Route::get('/',                               [AdminMembershipController::class, 'index'])
            ->middleware('permission:member.view');
        Route::post('/campaigns/{campaign}/enroll',   [AdminMembershipController::class, 'enroll'])
            ->middleware('permission:member.manage');
        Route::get('/{membership}',                   [AdminMembershipController::class, 'show'])
            ->middleware('permission:member.view');
        Route::post('/{membership}/validate-l1',      [AdminMembershipController::class, 'validateL1'])
            ->middleware('permission:membership.validate_l1');
        Route::post('/{membership}/validate-l2',      [AdminMembershipController::class, 'validateL2'])
            ->middleware('permission:membership.validate_l2');
        Route::post('/{membership}/suspend',          [AdminMembershipController::class, 'suspend'])
            ->middleware('permission:membership.suspend');
        Route::post('/{membership}/reactivate',       [AdminMembershipController::class, 'reactivate'])
            ->middleware('permission:membership.reactivate');
        Route::get('/{membership}/installments',      [AdminMembershipController::class, 'installments'])
            ->middleware('permission:member.view');
    });

    // Membres (utilisateurs) — lecture : member.view ; gestion : member.manage ; pièces KYC : member.kyc
    Route::prefix('admin/members')->group(function () {
        Route::middleware('permission:member.view')->group(function () {
            Route::get('/',                        [AdminMemberController::class, 'index']);
            Route::get('/{user}',                  [AdminMemberController::class, 'show']);
            Route::get('/{user}/overview',         [AdminMemberController::class, 'overview']);
            Route::get('/{user}/transactions',     [AdminMemberController::class, 'transactions']);
            Route::get('/{user}/finance',          [AdminMemberController::class, 'finance']);
            Route::get('/{user}/history',          [AdminMemberController::class, 'history']);
            Route::get('/{user}/notes',            [AdminMemberController::class, 'notes']);
        });

        Route::middleware('permission:member.manage')->group(function () {
            Route::post('/',                       [AdminMemberController::class, 'store']);
            Route::patch('/{user}',                [AdminMemberController::class, 'update']);
            Route::post('/{user}/suspend',         [AdminMemberController::class, 'suspend']);
            Route::post('/{user}/activate',        [AdminMemberController::class, 'activate']);
            Route::post('/{user}/reactivate',      [AdminMemberController::class, 'reactivate']);
            Route::post('/{user}/notes',           [AdminMemberController::class, 'addNote']);
        });

        Route::middleware('permission:member.kyc')->group(function () {
            Route::get('/{user}/kyc',                         [AdminMemberController::class, 'kycDocuments']);
            Route::get('/{user}/kyc/document/{level}/{type}', [AdminMemberController::class, 'kycServeDocument'])
                ->name('admin.kyc.document');
            Route::post('/{user}/kyc/approve',                [AdminMemberController::class, 'approveKyc']);
            Route::post('/{user}/kyc/reject',                 [AdminMemberController::class, 'rejectKyc']);
        });
    });

    // Retraits anticipés (R-WDR-*)
    Route::prefix('admin/early-withdrawals')->group(function () {
        Route::get('/',       [AdminEarlyWithdrawalController::class, 'index'])->middleware('permission:withdrawal.view');
        Route::get('/stats',  [AdminEarlyWithdrawalController::class, 'stats'])->middleware('permission:withdrawal.view');
        Route::get('/counts', [AdminEarlyWithdrawalController::class, 'counts'])->middleware('permission:withdrawal.view');
        Route::post('/',      [AdminEarlyWithdrawalController::class, 'store'])->middleware('permission:withdrawal.request');
        Route::get('/{withdrawal}', [AdminEarlyWithdrawalController::class, 'show'])->middleware('permission:withdrawal.view');

        // Instruction
        Route::middleware('permission:withdrawal.review')->group(function () {
            Route::post('/{withdrawal}/review',       [AdminEarlyWithdrawalController::class, 'review']);
            Route::post('/{withdrawal}/request-info', [AdminEarlyWithdrawalController::class, 'requestInfo']);
            Route::post('/{withdrawal}/approve',      [AdminEarlyWithdrawalController::class, 'approve']);
            Route::post('/{withdrawal}/reject',       [AdminEarlyWithdrawalController::class, 'reject']);
            Route::post('/{withdrawal}/cancel',       [AdminEarlyWithdrawalController::class, 'cancel']);
        });

        // Versement (manuel) : lancement sous step-up, puis confirmation ou échec
        Route::middleware('permission:withdrawal.disburse')->group(function () {
            Route::post('/{withdrawal}/payout/initiate', [AdminEarlyWithdrawalController::class, 'initiatePayout'])->middleware('step_up');
            Route::post('/{withdrawal}/payout/confirm',  [AdminEarlyWithdrawalController::class, 'confirmPayout']);
            Route::post('/{withdrawal}/payout/fail',     [AdminEarlyWithdrawalController::class, 'failPayout']);
        });
    });
    Route::get('admin/memberships/{membership}/withdrawable', [AdminEarlyWithdrawalController::class, 'withdrawable'])
        ->middleware('permission:withdrawal.view,withdrawal.request');
});
