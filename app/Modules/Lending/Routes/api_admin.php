<?php

use App\Modules\Lending\Http\Controllers\AdminLoanController;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    // Eligibility check
    Route::get('/admin/memberships/{membership}/eligibility', [AdminLoanController::class, 'checkEligibility'])
        ->middleware('permission:loan.view');

    // Loan requests
    Route::prefix('admin/loan-requests')->group(function () {
        Route::get('/',                          [AdminLoanController::class, 'indexRequests'])
            ->middleware('permission:loan.view');
        Route::post('/',                         [AdminLoanController::class, 'createRequest'])
            ->middleware('permission:loan.review');
        Route::get('/{loanRequest}',             [AdminLoanController::class, 'showRequest'])
            ->middleware('permission:loan.view');
        Route::post('/{loanRequest}/approve',    [AdminLoanController::class, 'approve'])
            ->middleware('permission:loan.approve');
        Route::post('/{loanRequest}/countersign',[AdminLoanController::class, 'countersign'])
            ->middleware('permission:loan.countersign');
        Route::post('/{loanRequest}/reject',     [AdminLoanController::class, 'reject'])
            ->middleware('permission:loan.reject');
        Route::post('/{loanRequest}/disburse',   [AdminLoanController::class, 'disburse'])
            ->middleware(['permission:loan.disburse', 'step_up']);
    });

    // Loans
    Route::prefix('admin/loans')->group(function () {
        Route::get('/',                    [AdminLoanController::class, 'indexLoans'])
            ->middleware('permission:loan.view');
        Route::get('/{loan}',              [AdminLoanController::class, 'showLoan'])
            ->middleware('permission:loan.view');
        Route::get('/{loan}/schedule',     [AdminLoanController::class, 'schedule'])
            ->middleware('permission:loan.view');
        Route::post('/{loan}/schedule/{item}/waive', [AdminLoanController::class, 'waiveScheduleItem'])
            ->middleware(['permission:loan.review', 'step_up']);
        Route::post('/{loan}/repay',       [AdminLoanController::class, 'recordRepayment'])
            ->middleware('permission:loan.review');
        // Write-off = super_admin uniquement (R-LOAN-07). Aucun rôle ci-dessus
        // n'a `loan.writeoff` → seul le wildcard `*` du super_admin passe.
        Route::post('/{loan}/write-off',   [AdminLoanController::class, 'writeOff'])
            ->middleware(['permission:loan.writeoff', 'step_up']);
    });
});
