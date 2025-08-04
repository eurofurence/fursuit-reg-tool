<?php

use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR POS SYSTEM - AUTHENTICATED
 */
Route::get('/', \App\Http\Controllers\POS\DashboardController::class)->name('dashboard');
// Attendees
Route::prefix('/attendees')->name('attendee.')->group(function () {
    Route::get('/lookup', [\App\Http\Controllers\POS\AttendeeController::class, 'lookupForm'])->name('lookup');
    Route::post('/lookup', [\App\Http\Controllers\POS\AttendeeController::class, 'lookupSubmit'])->name('lookup.submit');
    Route::get('/show/{attendeeId}', [\App\Http\Controllers\POS\AttendeeController::class, 'show'])->name('show');
});
// Cash Register
Route::prefix('/wallet')->name('wallet.')->group(function () {
    Route::get('/', \App\Http\Controllers\POS\CashRegisterController::class)->name('show');
    Route::get('/add', [\App\Http\Controllers\POS\CashRegisterController::class, 'moneyAddForm'])->name('money.add');
    Route::post('/add', [\App\Http\Controllers\POS\CashRegisterController::class, 'moneyAdd'])->name('money.add.submit');
    Route::get('/remove', [\App\Http\Controllers\POS\CashRegisterController::class, 'moneyRemoveForm'])->name('money.remove');
    Route::post('/remove', [\App\Http\Controllers\POS\CashRegisterController::class, 'moneyRemove'])->name('money.remove.submit');
});
// Print Badge
Route::post('/badges/{badge}/print', \App\Http\Controllers\POS\Printing\PrintBadgeController::class)->name('badges.print');
// Print QZ Cert
Route::post('/badges/{badge}/handout', [\App\Http\Controllers\POS\BadgeController::class, 'handout'])->name('badges.handout');
Route::post('/badges/{badge}/handout/undo', [\App\Http\Controllers\POS\BadgeController::class, 'handoutUndo'])->name('badges.handout.undo');
Route::post('/badges/handout/bulk', [\App\Http\Controllers\POS\BadgeController::class, 'handoutBulk'])->name('badges.handout.bulk');
Route::resource('checkout', \App\Http\Controllers\POS\CheckoutController::class);
Route::post('/checkout/{checkout}/startCardPayment', [\App\Http\Controllers\POS\CheckoutController::class, 'startCardPayment'])->name('checkout.startCardPayment');
Route::post('/checkout/{checkout}/payWithCash', [\App\Http\Controllers\POS\CheckoutController::class, 'payWithCash'])->name('checkout.payWithCash');
Route::get('/checkout/{checkout}/receipt', [\App\Http\Controllers\ReceiptController::class, 'show'])->name('checkout.receipt');
Route::post('/checkout/{checkout}/receipt/print', [\App\Http\Controllers\ReceiptController::class, 'printReceipt'])->name('checkout.receipt.print');
Route::post('/checkout/{checkout}/receipt/email', [\App\Http\Controllers\ReceiptController::class, 'sendEmail'])->name('checkout.receipt.email');
// Print Queue
Route::prefix('/print-queue')->name('print-queue.')->group(function () {
    Route::get('/', [\App\Http\Controllers\POS\PrintQueueController::class, 'index'])->name('index');
    Route::post('/{printJob}/mark-printed', [\App\Http\Controllers\POS\PrintQueueController::class, 'markAsPrinted'])->name('mark-printed');
    Route::post('/{printJob}/retry', [\App\Http\Controllers\POS\PrintQueueController::class, 'retry'])->name('retry');
    Route::delete('/{printJob}', [\App\Http\Controllers\POS\PrintQueueController::class, 'delete'])->name('delete');
});
// Statistics
Route::get('/statistics', [\App\Http\Controllers\POS\StatisticsController::class, 'index'])->name('statistics');
