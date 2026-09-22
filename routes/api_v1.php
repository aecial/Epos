<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\ShiftTransactionController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('api.v1.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

    Route::post('shifts', [ShiftController::class, 'openShift'])->name('api.v1.shifts.open');
    Route::get('shifts/active', [ShiftController::class, 'getActiveShift'])->name('api.v1.shifts.active');
    Route::get('shifts/{shift}', [ShiftController::class, 'getShift'])->name('api.v1.shifts.show');
    Route::put('shifts/{shift}/close', [ShiftController::class, 'closeShift'])->name('api.v1.shifts.close');

    Route::get('shifts/{shift}/transactions', [ShiftTransactionController::class, 'getTransactions'])->name('api.v1.shift-transactions.index');
    Route::post('shifts/{shift}/transactions', [ShiftTransactionController::class, 'createTransaction'])->name('api.v1.shift-transactions.store');
    Route::put('shifts/{shift}/transactions/{transaction}', [ShiftTransactionController::class, 'updateTransaction'])->name('api.v1.shift-transactions.update');
    Route::delete('shifts/{shift}/transactions/{transaction}', [ShiftTransactionController::class, 'deleteTransaction'])->name('api.v1.shift-transactions.destroy');

    Route::get('tickets', [TicketController::class, 'getTickets'])->name('api.v1.tickets.index');
    Route::post('tickets', [TicketController::class, 'createTicket'])->name('api.v1.tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'getTicket'])->name('api.v1.tickets.show');
    Route::post('tickets/{ticket}/items', [TicketController::class, 'addItem'])->name('api.v1.tickets.items.store');
    Route::delete('tickets/{ticket}/items/{ticketItem}', [TicketController::class, 'voidItem'])->name('api.v1.tickets.items.void');
    Route::patch('tickets/{ticket}/discount', [TicketController::class, 'setDiscount'])->name('api.v1.tickets.discount');
    Route::post('tickets/{ticket}/cancel', [TicketController::class, 'cancelTicket'])->name('api.v1.tickets.cancel');

    Route::post('tickets/{ticket}/charges', [PaymentController::class, 'chargeTicket'])->name('api.v1.tickets.charges.store');

    Route::get('refunds', [RefundController::class, 'getRefunds'])->name('api.v1.refunds.index');
    Route::get('refunds/{refund}', [RefundController::class, 'getRefund'])->name('api.v1.refunds.show');
    Route::post('refunds', [RefundController::class, 'requestRefund'])->name('api.v1.refunds.store');
    Route::put('refunds/{refund}/approve', [RefundController::class, 'approveRefund'])->name('api.v1.refunds.approve');
    Route::put('refunds/{refund}/reject', [RefundController::class, 'rejectRefund'])->name('api.v1.refunds.reject');
});
