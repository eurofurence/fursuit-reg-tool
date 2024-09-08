<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('POS/Dashboard');
    }
}
