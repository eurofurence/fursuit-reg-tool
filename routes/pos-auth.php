<?php

use Illuminate\Support\Facades\Route;

// Signed route machine login
Route::get('/machine-login', [\App\Http\Controllers\POS\Auth\MachineLoginController::class, '__invoke'])
    ->middleware('signed')
    ->withoutMiddleware(['auth:machine', 'auth:machine-user'])
    ->name('machine.login');

/**
 * AUTHENTICATION ROUTES
 */
Route::get('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'login'])
    ->name('user.login');
