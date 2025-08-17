<?php

use App\Domain\CatchEmAll\Controllers\GameController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PWAController;
use Illuminate\Support\Facades\Route;

/**
 * CATCH EM ALL GAME ROUTES - Mobile-first fursuiter hunting game
 */

Route::middleware('auth')->group(function () {
    // Introduction routes (no middleware)
    Route::get('/introduction', [GameController::class, 'introduction'])->name('introduction');
    Route::post('/introduction/complete', [GameController::class, 'completeIntroduction'])->name('introduction.complete');
});

    // Main game routes (with introduction middleware)
    Route::middleware('catch-introduction')->group(function () {
        Route::get('/', [GameController::class, 'index'])->name('catch');
        Route::get('/leaderboard', [GameController::class, 'leaderboard'])->name('leaderboard');
        Route::get('/achievements', [GameController::class, 'achievements'])->name('achievements');
        Route::get('/collection', [GameController::class, 'collection'])->name('collection');
        Route::post('/catch', [GameController::class, 'catch'])->name('catch.submit');
    });
});

