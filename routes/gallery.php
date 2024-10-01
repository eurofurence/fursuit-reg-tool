<?php

use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR THE GALLERY
 */

Route::get('/{site}', [\App\Http\Controllers\GALLERY\GalleryController::class, 'index'])->middleware('auth')->name('site');


Route::redirect('/', '/gallery/1/')->name('index'); // TODO: Make Permanent
