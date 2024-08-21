<?php

use Inertia\Inertia;
use App\Badges\EF28_Badge;
use App\Models\Badge\Badge;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use App\Http\Controllers\ProfileController;
use App\Models\Fursuit\Fursuit;
use App\Services\BadgeGeneratorBaseService;


Route::get('/', \App\Http\Controllers\WelcomeController::class)->name('welcome');
Route::redirect('/auth-login', '/auth/login')->name('login');
Route::redirect('/auth-done', '/')->name('dashboard');

Route::middleware(\App\Http\Middleware\EventEndedMiddleware::class)->group(function() {
    Route::prefix('/auth')->name('auth.')->group(function () {
        Route::get('/login',[\App\Http\Controllers\AuthController::class,'show'])->middleware('guest')->name('login');
        Route::post('/login',[\App\Http\Controllers\AuthController::class,'login'])->middleware('guest')->name('login.redirect');
        Route::get('/callback', [\App\Http\Controllers\AuthController::class,'loginCallback'])->middleware('guest')->name('login.callback');
        Route::post('/logout', [\App\Http\Controllers\AuthController::class,'logout'])->middleware('auth')->name('logout');
        Route::get('/frontchannel-logout', [\App\Http\Controllers\AuthController::class,'logoutCallback'])->name('logout.callback');
    });

    Route::middleware('auth')->group(function () {
        Route::resource('badges', \App\Http\Controllers\BadgeController::class);
    });
});

Route::get('gen_test', function () {
    return (new EF28_Badge)->getImage(Fursuit::where('id', '=', 1)->first());
});
