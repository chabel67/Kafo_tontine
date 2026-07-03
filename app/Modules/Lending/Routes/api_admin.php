<?php

use App\Modules\Lending\Http\Controllers\AdminLoanController;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    // Eligibility check
    Route::get('/admin/memberships/{membership}/eligibility', [AdminLoanController::class, 'checkEligibility']);

    // Loan requests
    Route::prefix('admin/loan-requests')->group(function () {
        Route::get('/',                          [AdminLoanController::class, 'indexRequests']);
        Route::post('/',                         [AdminLoanController::class, 'createRequest']);
        Route::get('/{loanRequest}',             [AdminLoanController::class, 'showRequest']);
        Route::post('/{loanRequest}/approve',    [AdminLoanController::class, 'approve']);
        Route::post('/{loanRequest}/countersign',[AdminLoanController::class, 'countersign']);
        Route::post('/{loanRequest}/reject',     [AdminLoanController::class, 'reject']);
        Route::post('/{loanRequest}/disburse',   [AdminLoanController::class, 'disburse']);
    });

    // Loans
    Route::prefix('admin/loans')->group(function () {
        Route::get('/',                    [AdminLoanController::class, 'indexLoans']);
        Route::get('/{loan}',              [AdminLoanController::class, 'showLoan']);
        Route::post('/{loan}/repay',       [AdminLoanController::class, 'recordRepayment']);
        Route::post('/{loan}/write-off',   [AdminLoanController::class, 'writeOff']);
    });
});
