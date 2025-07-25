<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use Illuminate\Http\Request;
use function Symfony\Component\String\b;

class BadgeController extends Controller
{
    public function handoutBulk(Request $request)
    {
        $someError = false;
        $badgeIds = $request->input('badge_ids');
        $badges = Badge::whereIn('id', $badgeIds)->get();
        $badges->each(function ($badge) {
            if($badge->status_fulfillment->canTransitionTo(PickedUp::class)) {
                $badge->status_fulfillment->transitionTo(PickedUp::class);
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
        if($badge->status_fulfillment->canTransitionTo(PickedUp::class)) {
            $badge->status_fulfillment->transitionTo(PickedUp::class);
            return back()->with('success', 'Badge handed out');
        } else {
            return back()->with('error', 'Badge cannot be handed out');
        }
    }

    public function handoutUndo(Badge $badge)
    {
        if($badge->status_fulfillment->canTransitionTo(ReadyForPickup::class)) {
            $badge->status_fulfillment->transitionTo(ReadyForPickup::class);
            return back()->with('success', 'Badge handout undone');
        }
        return back()->with('error', 'Badge handout cannot be undone');
    }
}
