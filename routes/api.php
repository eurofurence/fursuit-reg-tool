<?php

use App\Http\Controllers\API\FursuitController;
use App\Http\Middleware\API\AuthenticationMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/fursuits', [FursuitController::class, 'index'])->middleware(AuthenticationMiddleware::class)->name('api.fursuits.index');
