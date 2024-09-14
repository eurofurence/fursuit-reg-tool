<?php

// Very basic controller just so that it exists for the purpose of showing UI

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public function show():  Response
    {
        return Inertia::render('POS/Checkout/Show', []);
    }

    /**
     * Start a new checkout
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'badge_ids.*' => 'nullable|int',
            'user_id' => 'nullable|int',
        ]);
        dd($data);

    }
}
