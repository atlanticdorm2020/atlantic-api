<?php

use App\Http\Controllers\BedsController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationsController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\RatesController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\TransactionWalkinController;
use App\Http\Controllers\TransactionsController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'login']);
Route::post('/register', [RegisterController::class, 'register']);
Route::post('/register/customer', [RegisterController::class, 'registerCustomer']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/me', [LoginController::class, 'me']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/transaction-details', [DashboardController::class, 'transactionDetails']);
    Route::get('/dashboard/walkin-transaction-details', [DashboardController::class, 'walkinTransactionDetails']);

    Route::apiResource('locations', LocationsController::class);
    Route::apiResource('rates', RatesController::class);
    Route::apiResource('rooms', RoomsController::class);
    Route::apiResource('beds', BedsController::class);
    Route::put('/customers/{customer}/credentials', [CustomersController::class, 'updateCredentials']);
    Route::get('/customers/{customer}/files', [CustomersController::class, 'files']);
    Route::post('/customers/{customer}/files', [CustomersController::class, 'storeFile']);
    Route::get('/customers/{customer}/files/{file}/download', [CustomersController::class, 'downloadFile']);
    Route::delete('/customers/{customer}/files/{file}', [CustomersController::class, 'destroyFile']);
    Route::get('/customers/{customer}/balances', [CustomersController::class, 'balances']);
    Route::put('/customers/{customer}/balances', [CustomersController::class, 'updateBalance']);
    Route::get('/customers/{customer}/history', [CustomersController::class, 'history']);
    Route::apiResource('customers', CustomersController::class);
    Route::get('/transactions/form-options', [TransactionsController::class, 'formOptions']);
    Route::get('/transactions/form-preview', [TransactionsController::class, 'formPreview']);
    Route::get('/transactions/walkin/form-options', [TransactionWalkinController::class, 'formOptions']);
    Route::get('/transactions/walkin/form-preview', [TransactionWalkinController::class, 'formPreview']);
    Route::get('/transactions/walkin/rates', [TransactionWalkinController::class, 'rateOptions']);
    Route::get('/transactions/walkin', [TransactionWalkinController::class, 'index']);
    Route::post('/transactions/walkin', [TransactionWalkinController::class, 'store']);
    Route::post('/transactions/walkin/{transactionWalkin}/checkout', [TransactionWalkinController::class, 'checkout']);
    Route::put('/transactions/walkin/{transactionWalkin}/extend', [TransactionWalkinController::class, 'extend']);
    Route::apiResource('transactions', TransactionsController::class);
    Route::post('/transactions/{transaction}/checkout', [TransactionsController::class, 'checkout']);
    Route::put('/transactions/{transaction}/extend', [TransactionsController::class, 'extend']);
    Route::get('/transactions/{transaction}/switch-options', [TransactionsController::class, 'switchOptions']);
    Route::put('/transactions/{transaction}/switch', [TransactionsController::class, 'switch']);

    Route::get('/reservations/form-options', [ReservationController::class, 'formOptions']);
    Route::get('/reservations/rates', [ReservationController::class, 'rateOptions']);
    Route::get('/reservations/preview', [ReservationController::class, 'preview']);
    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::get('/reservations/manage', [ReservationController::class, 'adminIndex']);
    Route::get('/reservations/{reservation}/approval-preview', [ReservationController::class, 'approvalPreview']);
    Route::post('/reservations/{reservation}/approve', [ReservationController::class, 'approve']);
    Route::post('/reservations/{reservation}/reject', [ReservationController::class, 'reject']);

    Route::get('/account', [CustomerAccountController::class, 'show']);
    Route::put('/account/profile', [CustomerAccountController::class, 'updateProfile']);
    Route::get('/account/transactions', [CustomerAccountController::class, 'transactions']);
    Route::get('/account/balance', [CustomerAccountController::class, 'balance']);
    Route::get('/account/files', [CustomerAccountController::class, 'files']);
    Route::post('/account/files', [CustomerAccountController::class, 'storeFile']);
    Route::get('/account/files/{file}/download', [CustomerAccountController::class, 'downloadFile']);
    Route::delete('/account/files/{file}', [CustomerAccountController::class, 'destroyFile']);
});
