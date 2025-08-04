<?php

use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR FCEA (Fursuit Catch Em All) SYSTEM
 */
Route::get('/', [\App\Http\Controllers\FCEA\DashboardController::class, 'index'])->middleware('auth')->name('dashboard');
Route::post('/', [\App\Http\Controllers\FCEA\DashboardController::class, 'catch'])->middleware('auth')->name('dashboard.catch');
