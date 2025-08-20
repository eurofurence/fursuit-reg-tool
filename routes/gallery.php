<?php

use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR THE GALLERY
 */
Route::get('/count', [\App\Http\Controllers\GALLERY\GalleryController::class, 'getTotalFursuitCount'])->name('count');
Route::get('/load-more', [\App\Http\Controllers\GALLERY\GalleryController::class, 'loadMore'])->name('load-more');
Route::get('/', [\App\Http\Controllers\GALLERY\GalleryController::class, 'index'])->name('index');
