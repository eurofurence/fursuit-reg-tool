<?php

use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR THE GALLERY
 */

Route::get('/', [\App\Http\Controllers\GALLERY\GalleryController::class, 'index'])->middleware('auth')->name('dashboard');
