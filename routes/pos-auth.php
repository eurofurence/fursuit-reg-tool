<?php

use Illuminate\Support\Facades\Route;

// Signed route machine login
Route::get('/machine-login', [\App\Http\Controllers\POS\Auth\MachineLoginController::class, '__invoke'])
    ->middleware('signed')
    ->name('machine.login');

/**
 * AUTHENTICATION ROUTES
 */

Route::post('/logout', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'logout'])
    ->name('user.logout');
Route::get('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'selectUser'])
    ->name('user.select');
Route::get('/login/{user}', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'showLogin'])
    ->name('user.login.show');
Route::post('/login/{user}', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'submitLogin'])
    ->name('user.login.submit');
