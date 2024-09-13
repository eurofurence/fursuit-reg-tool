<?php

// Very basic controller just so that it exists for the purpose of showing UI

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function show():  Response
    {
        return Inertia::render('POS/Checkout/Show', []);
    }
}
