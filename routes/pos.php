<?php
use Illuminate\Support\Facades\Route;
/**
 * CONTAINS ALL ROUTES FOR POS SYSTEM
 */

/**
 * LOGIN
 */
// Signed route machine login

Route::get('/machine-login', [\App\Http\Controllers\POS\Auth\MachineLoginController::class, '__invoke'])
    ->middleware('signed')
    ->withoutMiddleware(['auth:machine','auth:machine-user'])
    ->name('auth.machine.login');
/**
 * AUTHENTICATION ROUTES
 */
Route::withoutMiddleware(['auth:machine-user'])->group(function () {

});

Route::post('/login', [\App\Http\Controllers\POS\Auth\MachineUserAuthController::class, 'login'])
    ->withoutMiddleware(['auth:machine-user'])
    ->name('auth.user.login');

/**
 * PUT AUTHENTICATED ROUTES BELOW
 */
Route::middleware(['auth:machine','auth:machine-user'])->group(function () {
    Route::get('/', [\App\Http\Controllers\POS\DashboardController::class, 'index'])->name('dashboard');




});
