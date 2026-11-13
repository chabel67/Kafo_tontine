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

    // Tontines rotatives — lot 4 : couverture d'un tour, suivi d'un membre, pénalités (D-R10).
    Route::get('admin/rotation-rounds/{round}/coverage', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'coverage'])
        ->middleware('permission:campaign.view,campaign.manage');
    Route::get('admin/memberships/{membership}/rotation', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'membership'])
        ->middleware('permission:campaign.view,member.view');
    // Lot 5 : distributions (validation, versement en deux temps sous step-up).
    Route::prefix('admin/rotation-rounds/{round}')->group(function () {
        $c = \App\Modules\Tontine\Http\Controllers\AdminRotationController::class;
        Route::get('payout', [$c, 'payoutPreview'])->middleware('permission:rotation.approve,rotation.disburse,campaign.view');
        Route::post('approve', [$c, 'approveRound'])->middleware('permission:rotation.approve');
        Route::post('payout/initiate', [$c, 'initiatePayout'])->middleware(['permission:rotation.disburse', 'step_up']);
        Route::post('payout/confirm', [$c, 'confirmPayout'])->middleware('permission:rotation.disburse');
        Route::post('payout/fail', [$c, 'failPayout'])->middleware('permission:rotation.disburse');
    });
    // Reconduction des campagnes (R1–R4).
    $rn = \App\Modules\Tontine\Http\Controllers\AdminCampaignRenewalController::class;
    Route::get('admin/campaigns/{campaign}/renewal/candidates', [$rn, 'candidates'])->middleware('permission:campaign.create');
    Route::post('admin/campaigns/{campaign}/renewal/preview', [$rn, 'preview'])->middleware('permission:campaign.create');
    // Confirmer ouvre directement le nouveau cycle aux adhésions : transition réservée (R-CAMP-01).
    Route::post('admin/campaigns/{campaign}/renewal', [$rn, 'confirm'])->middleware('permission:campaign.transition');
    Route::get('admin/campaigns/{campaign}/cycles', [$rn, 'cycles'])->middleware('permission:campaign.view,campaign.manage');

    // Lot 8 : départs, remplaçants, sorties de caisse en deux temps, clôture (D-R7, D-R11).
    $lc = \App\Modules\Tontine\Http\Controllers\AdminRotationLifecycleController::class;
    Route::get('admin/campaigns/{campaign}/rotation/departures', [$lc, 'departures'])->middleware('permission:campaign.view,campaign.manage');
    Route::post('admin/memberships/{membership}/rotation/departure', [$lc, 'startDeparture'])->middleware('permission:campaign.manage');
    Route::post('admin/rotation-departures/{departure}/replacement', [$lc, 'replace'])->middleware('permission:campaign.manage');
    Route::post('admin/rotation-departures/{departure}/refund/initiate', [$lc, 'initiateRefund'])->middleware(['permission:rotation.disburse', 'step_up']);
    Route::post('admin/campaigns/{campaign}/rotation/surplus/payouts/initiate', [$lc, 'initiateSurplus'])->middleware(['permission:rotation.disburse', 'step_up']);
    Route::post('admin/rotation-cash-outs/{attempt}/confirm', [$lc, 'confirmCashOut'])->middleware('permission:rotation.disburse');
    Route::post('admin/rotation-cash-outs/{attempt}/fail', [$lc, 'failCashOut'])->middleware('permission:rotation.disburse');
    Route::get('admin/campaigns/{campaign}/rotation/closure', [$lc, 'closurePreview'])->middleware('permission:campaign.view,campaign.manage');
    Route::post('admin/campaigns/{campaign}/rotation/surplus/revenue', [$lc, 'surplusToRevenue'])->middleware(['permission:campaign.transition,campaign.close', 'step_up']);
    Route::post('admin/campaigns/{campaign}/rotation/close', [$lc, 'close'])->middleware(['permission:campaign.transition,campaign.close', 'step_up']);

    Route::post('admin/installments/{installment}/penalty', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'applyPenalty'])
        ->middleware('permission:campaign.manage');
    Route::post('admin/installments/{installment}/penalty/cancel', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'cancelPenalty'])
        ->middleware('permission:campaign.manage');

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
        // Tontines rotatives — lot 2 : aperçu et confirmation du calendrier (D-R1, D-R2).
        Route::get('/{campaign}/rotation/schedule', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'schedulePreview'])
            ->middleware('permission:campaign.view,campaign.manage');
        Route::post('/{campaign}/rotation/schedule/confirm', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'confirmSchedule'])
            ->middleware('permission:campaign.manage');
        // Lot 3 : tours (attribution manuelle ou par tirage, figée au démarrage — D-R6).
        Route::get('/{campaign}/rotation/rounds', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'rounds'])
            ->middleware('permission:campaign.view,campaign.manage');
        Route::post('/{campaign}/rotation/draw', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'draw'])
            ->middleware('permission:campaign.manage');
        Route::post('/{campaign}/rotation/assign', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'assign'])
            ->middleware('permission:campaign.manage');
        // Lot 4 : suivi (vue d'ensemble, tours, membres).
        Route::get('/{campaign}/rotation/overview', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'overview'])
            ->middleware('permission:campaign.view,campaign.manage');
        // Lot 6 : rapprochement de la caisse commune.
        Route::get('/{campaign}/rotation/reconciliation', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'reconciliation'])
            ->middleware('permission:campaign.view,treasury.view');
        Route::post('/{campaign}/rotation/start', [\App\Modules\Tontine\Http\Controllers\AdminRotationController::class, 'start'])
            ->middleware('permission:campaign.transition');
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
