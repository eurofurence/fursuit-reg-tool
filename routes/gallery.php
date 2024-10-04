<?php

use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR THE GALLERY
 */

Route::get('/count' , [\App\Http\Controllers\GALLERY\GalleryController::class, 'getTotalFursuitCount'])->middleware('auth')->name('count');
Route::get('/{site}', [\App\Http\Controllers\GALLERY\GalleryController::class, 'index'])->middleware('auth')->name('site');

Route::redirect('/', '/gallery/1/')->name('index'); // TODO: Make Permanent
