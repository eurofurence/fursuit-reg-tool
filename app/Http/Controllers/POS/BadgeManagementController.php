<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BadgeManagementController extends Controller
{
    public function index(Request $request)
    {
        $currentEvent = Event::latest('starts_at')->first();

        if (! $currentEvent) {
            return redirect()->route('pos.dashboard')->with('error', 'No current event found');
        }

        $badges = Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        })
            ->with([
                'fursuit.user.eventUsers',
                'fursuit.species',
            ])
            ->orderBy('custom_id')
            ->get();

        return Inertia::render('POS/Badges/Index', [
            'badges' => $badges,
            'currentEvent' => $currentEvent,
        ]);
    }
}
