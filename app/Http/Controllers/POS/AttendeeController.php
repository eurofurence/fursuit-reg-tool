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
        print($request->get('attendeeId'));
        $user = User::where('attendee_id', $request->get('attendeeId'))->first();
        if (!$user) return redirect()->back()->withErrors(['attendeeId' => 'Could not find attendee']);
        else return redirect()->back()->withErrors(['attendeeId' => 'Found User']);
    }
}
