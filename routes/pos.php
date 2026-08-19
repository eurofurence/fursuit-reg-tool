<?php

use App\Http\Controllers\POS\AttendeeController;
use App\Http\Controllers\POS\BadgeController;
use App\Http\Controllers\POS\BadgeEditController;
use App\Http\Controllers\POS\BadgeManagementController;
use App\Http\Controllers\POS\BadgeVerificationController;
use App\Http\Controllers\POS\CheckoutController;
use App\Http\Controllers\POS\DashboardController;
use App\Http\Controllers\POS\MachineController;
use App\Http\Controllers\POS\MyPrintsController;
use App\Http\Controllers\POS\Printing\PrintBadgeController;
use App\Http\Controllers\POS\PrintQueueController;
use App\Http\Controllers\POS\StatisticsController;
use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR POS SYSTEM - AUTHENTICATED
 */
Route::get('/', DashboardController::class)->name('dashboard');
// Attendees
Route::prefix('/attendees')->name('attendee.')->group(function () {
    // The dashboard is the lookup screen: it owns the field and keeps it
    // focused, so there is no separate form to GET.
    Route::post('/lookup', [AttendeeController::class, 'lookupSubmit'])->name('lookup.submit');
    Route::get('/show/{attendeeId}', [AttendeeController::class, 'show'])->name('show');
});
// Badge Management
Route::get('/badges', [BadgeManagementController::class, 'index'])->name('badges.index');
// Print Badge
Route::post('/badges/{badge}/print', PrintBadgeController::class)->name('badges.print');
Route::post('/badges/print/bulk', [BadgeController::class, 'printBulk'])->name('badges.print.bulk');
Route::post('/badges/{badge}/handout', [BadgeController::class, 'handout'])->name('badges.handout');
Route::post('/badges/{badge}/handout/undo', [BadgeController::class, 'handoutUndo'])->name('badges.handout.undo');
Route::post('/badges/handout/bulk', [BadgeController::class, 'handoutBulk'])->name('badges.handout.bulk');
// Desk corrections. The details are open to any cashier; the price needs a manager,
// which BadgeEditController enforces rather than a middleware, because an approval can
// also come from the manager signed in at the till without a code.
Route::put('/badges/{badge}', [BadgeEditController::class, 'update'])->name('badges.update');
Route::post('/badges/prices', [BadgeEditController::class, 'updatePrices'])->name('badges.prices');
Route::resource('checkout', CheckoutController::class);
Route::get('/checkout/{checkout}/status', [CheckoutController::class, 'status'])->name('checkout.status');
Route::post('/checkout/{checkout}/startCardPayment', [CheckoutController::class, 'startCardPayment'])->name('checkout.startCardPayment');
Route::post('/checkout/{checkout}/payWithCash', [CheckoutController::class, 'payWithCash'])->name('checkout.payWithCash');
Route::get('/checkout/{checkout}/receipt', [ReceiptController::class, 'show'])->name('checkout.receipt');
Route::post('/checkout/{checkout}/receipt/print', [ReceiptController::class, 'printReceipt'])->name('checkout.receipt.print');
Route::post('/checkout/{checkout}/receipt/email', [ReceiptController::class, 'sendEmail'])->name('checkout.receipt.email');
// Print Queue
Route::prefix('/print-queue')->name('print-queue.')->group(function () {
    Route::get('/', [PrintQueueController::class, 'index'])->name('index');
    Route::post('/{printJob}/mark-printed', [PrintQueueController::class, 'markAsPrinted'])->name('mark-printed');
    Route::post('/{printJob}/retry', [PrintQueueController::class, 'retry'])->name('retry');
    Route::delete('/{printJob}', [PrintQueueController::class, 'delete'])->name('delete');
});
// The runs this clerk started, and the notifications they raise on the dashboard
Route::prefix('/my-prints')->name('my-prints.')->group(function () {
    Route::get('/', [MyPrintsController::class, 'index'])->name('index');
    Route::post('/dismiss-all', [MyPrintsController::class, 'dismissAll'])->name('dismiss-all');
    Route::post('/{printBatch}/dismiss', [MyPrintsController::class, 'dismiss'])->name('dismiss');
});
// Checking the printed crate off card by card. See BadgeVerificationController.
Route::prefix('/verification')->name('verification.')->group(function () {
    Route::get('/', [BadgeVerificationController::class, 'index'])->name('index');
    Route::post('/', [BadgeVerificationController::class, 'store'])->name('store');
    Route::post('/{badge}/revert', [BadgeVerificationController::class, 'revert'])->name('revert');
});
// Statistics
Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics');
// Machine Settings
Route::put('/machine/{machine}/timeout', [MachineController::class, 'updateTimeout'])->name('machine.timeout');
Route::put('/machine/{machine}/badge-range', [MachineController::class, 'updateBadgeRange'])->name('machine.badge-range');
