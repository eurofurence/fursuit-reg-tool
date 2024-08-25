<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function __invoke()
    {
        // States => closed, coutdown, preorder, late => closed
        // Get next event by ends_at
        $event = \App\Models\Event::getActiveEvent();
        return Inertia::render('Welcome', [
            'showState' => $event?->state ?? \App\Enum\EventStateEnum::CLOSED->value,
        ]);
    }

    // TODO remove this
    public function test()
    {
        return Inertia::render('POS/Dashboard');
    }
}
