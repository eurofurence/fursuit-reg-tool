<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Inertia\Response;

class AttendeeController extends Controller
{
    public function lookupForm(): Response
    {
        return Inertia::render('POS/Attendee/Lookup');
    }

    public function lookupSubmit(Request $request): RedirectResponse
    {
        $user = User::where('attendee_id', $request->get('attendeeId'))->first()->exists();
        if (!$user) return redirect()->back()->withErrors(['attendeeId' => 'Could not find attendee']);

        else return redirect()->route('pos.attendee.show', ['attendeeId' => $request->get('attendeeId')]);
    }

    public function attendeeShow(string $attendeeId, Request $request):  Response
    {
        $user = User::where('attendee_id', $attendeeId)->first();
        print_r($user);

        return Inertia::render('POS/Attendee/Show', ['attendee' => $user]);
    }
}
