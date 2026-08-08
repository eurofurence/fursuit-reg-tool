<?php

use App\Http\Controllers\POS\Auth\MachineLoginController;
use App\Http\Controllers\POS\Auth\MachineUserAuthController;
use App\Http\Controllers\POS\Printing\PrinterStateController;
use Illuminate\Support\Facades\Route;

// Signed route machine login
Route::get('/machine-login', [MachineLoginController::class, '__invoke'])
    ->middleware('signed')
    ->name('machine.login');

/**
 * AUTHENTICATION ROUTES
 */
Route::middleware('auth:machine')->group(function () {
    Route::post('/logout', [MachineUserAuthController::class, 'logout'])
        ->name('user.logout');
    Route::post('/lock', [MachineUserAuthController::class, 'lock'])
        ->name('lock');
    Route::get('/login', [MachineUserAuthController::class, 'selectUser'])
        ->name('user.select');
    Route::post('/login', [MachineUserAuthController::class, 'submitPinLogin'])
        ->name('user.pin.submit');
    Route::get('/setup', [MachineUserAuthController::class, 'showSetup'])
        ->name('setup');
    Route::post('/setup', [MachineUserAuthController::class, 'completeSetup'])
        ->name('setup.complete');

    /**
     * CONTAINS ALL ROUTES FOR POS SYSTEM - AUTHENTICATED
     */
    // Printing moved out of the browser entirely. The Zebra card printer is
    // driven by the native print agent over /api/print-agent (routes/print-agent.php),
    // which reads the hardware over SNMP instead of trusting a driver that
    // reports "online" no matter what. See docs/printing.md.

    // Printer state, read-only, so the POS can show staff what the printers are
    // doing. Written by the agent, never by the browser.
    Route::get('/printer-states/api', [PrinterStateController::class, 'getStates'])->name('printer-states.api');
});
