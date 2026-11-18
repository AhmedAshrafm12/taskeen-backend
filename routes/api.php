<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DueController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\OtherIncomeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/reports/print', [ReportController::class, 'print']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'update']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/buildings/{building}/units', [BuildingController::class, 'units']);
    Route::get('/building-options', [BuildingController::class, 'options']);
    Route::get('/contracts/{contract}/payment-context', [PaymentController::class, 'context']);
    Route::post('/payments/preview', [PaymentController::class, 'preview'])->name('payments.preview');
    Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse']);
    Route::post('/payments/{payment}/correct', [PaymentController::class, 'correct'])->name('payments.correct');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt']);
    Route::apiResource('payments', PaymentController::class)->only(['index', 'store', 'show']);
    Route::get('/dues/by-contract', [DueController::class, 'byContract']);
    Route::get('/dues', [DueController::class, 'index']);
    Route::get('/contracts/{contract}/schedule', [ScheduleController::class, 'show']);
    Route::post('/contracts/{contract}/schedule/preview', [ScheduleController::class, 'preview'])->name('schedule.preview');
    Route::post('/contracts/{contract}/schedule', [ScheduleController::class, 'store'])->name('schedule.store');
    Route::post('/contracts/{contract}/transition', [ContractController::class, 'transition']);
    Route::post('/contracts/preview', [ContractController::class, 'preview']);
    Route::apiResource('contracts', ContractController::class)->only(['index', 'store', 'show']);
    Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
    Route::apiResource('units', UnitController::class)->only(['index', 'store', 'show', 'update']);
    Route::apiResource('buildings', BuildingController::class);

    Route::get('/finance/summary', [FinanceController::class, 'summary']);
    Route::get('/finance/cash-transactions', [FinanceController::class, 'cashTransactions']);
    Route::get('/finance/capital', [FinanceController::class, 'capitalIndex']);
    Route::post('/finance/opening-balance', [FinanceController::class, 'openingBalance']);
    Route::post('/finance/capital-additions', [FinanceController::class, 'capitalAddition']);
    Route::post('/finance/owner-withdrawals', [FinanceController::class, 'ownerWithdrawal']);

    Route::get('/land-options', [LandController::class, 'options']);
    Route::get('/lands', [LandController::class, 'index']);
    Route::post('/lands', [LandController::class, 'store']);
    Route::get('/lands/{land}', [LandController::class, 'show']);
    Route::put('/lands/{land}', [LandController::class, 'update']);
    Route::post('/lands/{land}/purchase-payments', [LandController::class, 'purchasePayment']);
    Route::post('/lands/{land}/link-building', [LandController::class, 'linkBuilding']);

    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
    Route::post('/expenses/{expense}/payments', [ExpenseController::class, 'pay']);

    Route::get('/other-incomes', [OtherIncomeController::class, 'index']);
    Route::post('/other-incomes', [OtherIncomeController::class, 'store']);
});
