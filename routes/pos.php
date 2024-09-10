<?php

use App\Http\Controllers\POS\Printing\QzCertController;
use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR POS SYSTEM - AUTHENTICATED
 */

Route::get('/', \App\Http\Controllers\POS\DashboardController::class)->name('dashboard');
Route::prefix('/attendees')->name('attendee.')->group(function () {
    Route::get('/lookup', [\App\Http\Controllers\POS\AttendeeController::class, 'lookupForm'])->name('lookup');
    Route::post('/lookup', [\App\Http\Controllers\POS\AttendeeController::class, 'lookupSubmit'])->name('lookup.submit');
    Route::get('/show/{attendeeId}', [\App\Http\Controllers\POS\AttendeeController::class, 'show'])->name('show');
});
// Print Badge
Route::post('/badges/{badge}/print', \App\Http\Controllers\POS\Printing\PrintBadgeController::class)->name('badges.print');
// QZ Tray
Route::get('/qz/sign', [QzCertController::class,'sign'])->name('qz.sign');
Route::get('/qz/cert', [QzCertController::class,'cert'])->name('qz.cert');
Route::post('/printers/store',[\App\Http\Controllers\POS\Printing\PrinterController::class,'store'])->name('printers.store');
