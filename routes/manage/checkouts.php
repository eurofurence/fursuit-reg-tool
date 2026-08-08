<?php

use App\Http\Controllers\Manage\CheckoutController;
use App\Http\Controllers\Manage\CheckoutReceiptPrintController;
use Illuminate\Support\Facades\Route;

/*
 * Checkouts (phase 8, audit 4.5). German fiscal records under DSFinV-K and a Fiskaly TSE.
 *
 * Four routes, and the shape of the set is the point: there is no create, no edit, no
 * update and no delete, because CheckoutResource hard-refuses all three today and
 * CheckoutPolicy now refuses them at the model layer as well. Adding a verb here would be
 * a new write path to a tamper-evident record, which plan part 3 does not sanction.
 *
 * `receipt` is a GET that streams the PDF. It is the re-homed link: the Filament actions
 * pointed at `pos.checkout.receipt`, which lives behind `pos-auth:machine` plus
 * `pos-auth:machine-user`, so an admin browsing /admin without an active till session was
 * bounced rather than shown the receipt (plan 2.10 #36, audit 13). Same PDF, served under
 * the manage guard.
 *
 * `print` is a POST on its own sub-resource, handled by its own controller, for the reason
 * the print-job retry endpoint is: it puts paper through a printer, so it can never be
 * reached by opening a page, by a link somebody pasted, or by a poll. There is no GET form
 * of it.
 *
 * Both sub-routes are declared after the numeric {checkout}, and {checkout} is constrained
 * to digits, so no literal segment can ever bind as a model.
 */
Route::prefix('checkouts')->name('checkouts.')->group(function () {
    Route::get('/', [CheckoutController::class, 'index'])->name('index');

    Route::get('{checkout}', [CheckoutController::class, 'show'])->whereNumber('checkout')->name('show');
    Route::get('{checkout}/receipt', [CheckoutController::class, 'receipt'])->whereNumber('checkout')->name('receipt');
    Route::post('{checkout}/print', [CheckoutReceiptPrintController::class, 'store'])->whereNumber('checkout')->name('print');
});
