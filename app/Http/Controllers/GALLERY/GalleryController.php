<?php

namespace App\Http\Controllers\GALLERY;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class GalleryController extends Controller
{

    public function index() {
        return Inertia::render('Gallery/Index');
    }

}
