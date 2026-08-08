<?php

use App\Http\Controllers\Manage\SpecialCodeController;
use Illuminate\Support\Facades\Route;

/*
 * Special codes (phase 1). Same shape and the same reason: ManageSpecialCodes was an
 * index page with create and edit in modals, so neither had a URL (plan 1.2).
 *
 * `bulk` is declared before `{code}` for the same reason as in users.php, and the parameter
 * is constrained to digits so a stray word cannot reach the binder at all.
 */
Route::prefix('special-codes')->name('special-codes.')->middleware('can:manage-admin')->group(function () {
    Route::get('/', [SpecialCodeController::class, 'index'])->name('index');
    Route::get('create', [SpecialCodeController::class, 'create'])->name('create');
    Route::post('/', [SpecialCodeController::class, 'store'])->name('store');
    Route::delete('bulk', [SpecialCodeController::class, 'bulkDestroy'])->name('bulk.destroy');
    Route::get('{code}/edit', [SpecialCodeController::class, 'edit'])->whereNumber('code')->name('edit');
    Route::put('{code}', [SpecialCodeController::class, 'update'])->whereNumber('code')->name('update');
    Route::delete('{code}', [SpecialCodeController::class, 'destroy'])->whereNumber('code')->name('destroy');
});
