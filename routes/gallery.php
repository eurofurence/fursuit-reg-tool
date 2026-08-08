<?php

use App\Http\Controllers\GALLERY\GalleryController;
use Illuminate\Support\Facades\Route;

/**
 * CONTAINS ALL ROUTES FOR THE GALLERY
 *
 * `/` is the folder overview (one card per event); the grid lives behind `/all` or
 * `/event/{event}`.
 */
Route::get('/count', [GalleryController::class, 'getTotalFursuitCount'])->name('count');
Route::get('/load-more', [GalleryController::class, 'loadMore'])->name('load-more');
Route::get('/all', [GalleryController::class, 'index'])->name('all');
Route::get('/event/{event}', [GalleryController::class, 'index'])->name('event');
Route::get('/', [GalleryController::class, 'folders'])->name('index');
