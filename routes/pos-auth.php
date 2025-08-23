<?php

use App\Http\Controllers\POS\Printing\PrinterController;
use App\Http\Controllers\POS\Printing\QzCertController;
use Illuminate\Support\Facades\Route;

// Signed route machine login
Route::get('/machine-login', [\App\Http\Controllers\POS\Auth\MachineLoginController::class, '__invoke'])
    ->middleware('signed')
    ->name('machine.login');

/**
 * AUTHENTICATION ROUTES
 */
Route::middleware('auth:machine')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'logout'])
        ->name('user.logout');
    Route::get('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'selectUser'])
        ->name('user.select');
    Route::post('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'submitPinLogin'])
        ->name('user.pin.submit');
    Route::get('/login/{user}', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'showLogin'])
        ->name('user.login.show');
    Route::post('/login/{user}', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'submitLogin'])
        ->name('user.login.submit');

    /**
     * CONTAINS ALL ROUTES FOR POS SYSTEM - AUTHENTICATED
     */
    // QZ Tray
    Route::get('/qz/sign', [QzCertController::class, 'sign'])->name('qz.sign');
    Route::get('/qz/cert', [QzCertController::class, 'cert'])->name('qz.cert');
    // Cashier / Checkout stuff
    Route::post('/printers/store', [PrinterController::class, 'store'])->name('printers.store');
    Route::get('/printers/jobs', [PrinterController::class, 'jobIndex'])->name('printers.jobs');
    Route::post('/printers/jobs/{job}/printed', [PrinterController::class, 'jobPrinted'])->name('printers.jobs.printed');
    Route::post('/printers/jobs/{job}/failed', [PrinterController::class, 'jobFailed'])->name('printers.jobs.failed');
    // Machine Status API
    Route::get('/machine/status', [\App\Http\Controllers\POS\MachineStatusController::class, 'status'])->name('machine.status');
    Route::post('/machine/status/update', [\App\Http\Controllers\POS\MachineStatusController::class, 'updateStatus'])->name('machine.status.update');

});
