<?php

use Illuminate\Support\Facades\Route;
use App\Domain\CatchEmAll\Controllers\GameController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PWAController;

/**
 * CATCH EM ALL GAME ROUTES - Mobile-first fursuiter hunting game
 */

// PWA routes (no auth middleware)
Route::get('/manifest.json', [PWAController::class, 'manifest'])->name('manifest');
Route::get('/sw.js', [PWAController::class, 'serviceWorker'])->name('service-worker');

// Authentication routes (no auth middleware)
Route::prefix('/auth')->name('auth.')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->middleware('guest')->name('login');
    Route::get('/callback', [AuthController::class, 'loginCallback'])->name('login.callback');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
    Route::get('/frontchannel-logout', [AuthController::class, 'logoutCallback'])->name('logout.callback');
});

// Introduction routes (auth required but no introduction middleware)
Route::middleware('catch-auth:web')->group(function () {
    Route::get('/introduction', [GameController::class, 'introduction'])->name('introduction');
    Route::post('/introduction/complete', [GameController::class, 'completeIntroduction'])->name('introduction.complete');
});

// Main game routes (auth + introduction middleware)
Route::middleware(['catch-auth:web', 'catch-introduction'])->group(function () {
    Route::get('/', [GameController::class, 'index'])->name('catch');
    Route::get('/leaderboard', [GameController::class, 'leaderboard'])->name('leaderboard');
    Route::get('/achievements', [GameController::class, 'achievements'])->name('achievements');
    Route::get('/collection', [GameController::class, 'collection'])->name('collection');
    Route::post('/catch', [GameController::class, 'catch'])->name('catch.submit');
});
