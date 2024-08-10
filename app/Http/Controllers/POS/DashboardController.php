<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('POS/Dashboard');
    }
}
