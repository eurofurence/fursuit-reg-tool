<?php

namespace App\Http\Controllers\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Http\Controllers\Controller;
use App\Models\Fursuit\States\Rejected;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendeeController extends Controller
{
    public function lookupForm(): Response
    {
        return Inertia::render('POS/Attendee/Lookup', [
            'backToRoute' => 'pos.dashboard'
        ]);
    }

    public function lookupSubmit(Request $request): RedirectResponse
    {
        $user = User::where('attendee_id', $request->get('attendeeId'))->first()?->exists();
        if (!$user) return redirect()->back()->withErrors(['attendeeId' => 'Could not find attendee']);

        else return redirect()->route('pos.attendee.show', ['attendeeId' => $request->get('attendeeId')]);
    }

    public function show(string $attendeeId, Request $request):  Response
    {
        $user = User::where('attendee_id', $attendeeId)->first();
        $badges = $user->badges()
            ->whereHas('fursuit', function ($query) {
                $query->where('status','!=',Rejected::$name);
            })
            ->with('fursuit.species')->get();

        return Inertia::render('POS/Attendee/Show', [
            'attendee' => $user->load('wallet'),
            //'badges' => $user->badges()->select('fursuit_id', 'printed_at', 'total', 'picked_up_at', 'badges.updated_at' )->get()
            'badges' => $badges->load('wallet'),
            'transactions' => $user->wallet->transactions()->where('amount', '<', 0)->orWhere('amount', '>', 0)->limit(50)->get(),
            'fursuits' => $badges->map(function ($badge) {
                return $badge->fursuit;
            })->unique('fursuit'),
            'checkouts' => Checkout::whereBelongsTo($user)->with('items')->get()->all(),
        ]);
    }
}
