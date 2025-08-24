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
    Route::post('/lock', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'lock'])
        ->name('lock');
    Route::get('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'selectUser'])
        ->name('user.select');
    Route::post('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'submitPinLogin'])
        ->name('user.pin.submit');
    Route::get('/setup', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'showSetup'])
        ->name('setup');
    Route::post('/setup', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'completeSetup'])
        ->name('setup.complete');

    /**
     * CONTAINS ALL ROUTES FOR POS SYSTEM - AUTHENTICATED
     */
    // QZ Tray
    Route::get('/qz/sign', [QzCertController::class, 'sign'])->name('qz.sign');
    Route::get('/qz/cert', [QzCertController::class, 'cert'])->name('qz.cert');
    // Cashier / Checkout stuff
    Route::post('/printers/store', [PrinterController::class, 'store'])->name('printers.store');
    Route::get('/printers/jobs', [PrinterController::class, 'jobIndex'])->name('printers.jobs');
    Route::get('/printers/jobs/{job}', [PrinterController::class, 'jobShow'])->name('printers.jobs.show');
    Route::post('/printers/jobs/{job}/printed', [PrinterController::class, 'jobPrinted'])->name('printers.jobs.printed');
    Route::post('/printers/jobs/{job}/failed', [PrinterController::class, 'jobFailed'])->name('printers.jobs.failed');
    Route::post('/printers/jobs/{job}/status', [PrinterController::class, 'jobStatusUpdate'])->name('printers.jobs.status');
    Route::post('/printers/jobs/{job}/qz-status', [PrinterController::class, 'qzJobStatusUpdate'])->name('printers.jobs.qz-status');
    Route::post('/printers/status', [PrinterController::class, 'printerStatusUpdate'])->name('printers.status');
    Route::post('/printers/events', [PrinterController::class, 'printerEventWebhook'])->name('printers.events');

    // Printer State Management API (Machine-level only - used by QZPrintService)
    Route::get('/printer-states/api', [\App\Http\Controllers\POS\Printing\PrinterStateController::class, 'getStates'])->name('printer-states.api');
    Route::post('/printer-states/update', [\App\Http\Controllers\POS\Printing\PrinterStateController::class, 'updateState'])->name('printer-states.update');

    // Machine Status API
    Route::post('/machine/status/update', [\App\Http\Controllers\POS\MachineStatusController::class, 'updateStatus'])->name('machine.status.update');

});
