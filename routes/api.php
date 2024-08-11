<?php

use App\Http\Middleware\API\AuthenticationMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/fursuits', [\App\Http\Controllers\API\FursuitController::class, 'index'])->middleware(AuthenticationMiddleware::class)->name('api.fursuits.index');
