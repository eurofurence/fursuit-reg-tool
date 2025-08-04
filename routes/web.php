<?php

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('welcome');
Route::redirect('/auth-login', '/auth/login')->name('login');
Route::redirect('/auth-done', '/')->name('dashboard');

Route::middleware(\App\Http\Middleware\EventEndedMiddleware::class)->group(function () {
    Route::prefix('/auth')->name('auth.')->group(function () {
        Route::get('/login', [\App\Http\Controllers\AuthController::class, 'show'])->middleware('guest')->name('login');
        Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login'])->middleware('guest')->name('login.redirect');
        Route::get('/callback', [\App\Http\Controllers\AuthController::class, 'loginCallback'])->middleware('guest')->name('login.callback');
        Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth')->name('logout');
        Route::get('/frontchannel-logout', [\App\Http\Controllers\AuthController::class, 'logoutCallback'])->name('logout.callback');
    });

    Route::middleware('auth')->group(function () {
        Route::resource('badges', \App\Http\Controllers\BadgeController::class);
        Route::get('/statistics', [\App\Http\Controllers\StatisticsController::class, 'index'])->name('statistics');
    });
});

Route::permanentRedirect('/catch-em-all', '/fcea/');
