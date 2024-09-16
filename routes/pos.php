<?php

use App\Http\Controllers\POS\Printing\QzCertController;
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
// QZ Tray
Route::get('/qz/sign', [QzCertController::class,'sign'])->name('qz.sign');
Route::get('/qz/cert', [QzCertController::class,'cert'])->name('qz.cert');
// Cashier / Checkout stuff
Route::post('/printers/store',[\App\Http\Controllers\POS\Printing\PrinterController::class,'store'])->name('printers.store');
Route::get('/printers/jobs',[\App\Http\Controllers\POS\Printing\PrinterController::class, 'jobIndex'])->name('printers.jobs');
Route::post('/printers/jobs/{job}/printed',[\App\Http\Controllers\POS\Printing\PrinterController::class, 'jobPrinted'])->name('printers.jobs.printed');
Route::post('/badges/{badge}/handout', [\App\Http\Controllers\POS\BadgeController::class,'handout'])->name('badges.handout');
Route::post('/badges/{badge}/handout/undo', [\App\Http\Controllers\POS\BadgeController::class, 'handoutUndo'])->name('badges.handout.undo');
Route::post('/badges/handout/bulk', [\App\Http\Controllers\POS\BadgeController::class, 'handoutBulk'])->name('badges.handout.bulk');
Route::resource('checkout', \App\Http\Controllers\POS\CheckoutController::class);
