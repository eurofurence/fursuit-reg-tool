<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\States\PickedUp;
use App\Models\Badge\States\ReadyForPickup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use function Symfony\Component\String\b;

class BadgeController extends Controller
{
    public function list() {

        return Inertia::render('POS/Badge/List', [
            'badges' => Badge::with('fursuit.user')->get()->all(),
            'backToRoute' => 'pos.dashboard',
        ]);
    }

    public function handoutBulk(Request $request)
    {
        $someError = false;
        $badgeIds = $request->input('badge_ids');
        $badges = Badge::whereIn('id', $badgeIds)->get();
        $badges->each(function ($badge) {
            if($badge->status->canTransitionTo(PickedUp::class)) {
                $badge->status->transitionTo(PickedUp::class);
            } else {
                $someError = true;
            }
        });
        if($someError) {
            return back()->with('error', 'Some badges could not be handed out');
        }
        return back()->with('success', 'All Badges handed out successfully');
    }
    public function handout(Badge $badge)
    {
        if($badge->status->canTransitionTo(PickedUp::class)) {
            $badge->status->transitionTo(PickedUp::class);
            return back()->with('success', 'Badge handed out');
        } else {
            return back()->with('error', 'Badge cannot be handed out');
        }
    }

    public function handoutUndo(Badge $badge)
    {
        if($badge->status->canTransitionTo(ReadyForPickup::class)) {
            $badge->status->transitionTo(ReadyForPickup::class);
            return back()->with('success', 'Badge handout undone');
        }
        return back()->with('error', 'Badge handout cannot be undone');
    }
}
