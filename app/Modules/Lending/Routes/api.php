<?php

use App\Modules\Lending\Http\Controllers\MemberLoanController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('member')->group(function () {
    Route::get('loans',                               [MemberLoanController::class, 'myLoans']);
    Route::get('loan-requests',                       [MemberLoanController::class, 'myRequests']);
    Route::post('loan-requests',                      [MemberLoanController::class, 'request']);
    Route::get('eligibility/{membership}',            [MemberLoanController::class, 'eligibility']);
});
