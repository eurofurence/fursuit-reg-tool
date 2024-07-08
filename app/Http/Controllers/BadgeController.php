<?php

namespace App\Http\Controllers;

use App\Http\Requests\BadgeCreateRequest;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\States\Pending;
use App\Models\Species;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BadgeController extends Controller
{
    public function index()
    {
        return Inertia::render('Badges/BadgesIndex', [
            'badges' => auth()->user()->badges()->with('fursuit.species')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Badges/BadgesCreate', [
            'species' => Species::has('fursuits',count: 5)->orWhere('checked',true)->get('name'),
        ]);
    }

    public function store(BadgeCreateRequest $request)
    {
        DB::transaction(function () use ($request) {
            // Lock user for update
            User::where('id', auth()->id())->lockForUpdate()->first();
            $event = Event::getActiveEvent();
            if ($event === null) {
                abort(404);
            }
            $validated = $request->validated();
            // Create Fursuit
            $fursuit = $request->user()->fursuits()->create([
                'status' => Pending::$name,
                'event_id' => $event->id,
                'species_id' => Species::firstOrCreate([
                    'name' => $validated['species'],
                ], [
                    'name' => $validated['species'],
                    'checked' => false,
                ])->id,
                'name' => $validated['name'],
                'image' => $request->file('image')->store('fursuits'),
                'published' => $validated['publish'],
                'catch_em_all' => $validated['catchEmAll'] ?? false,
            ]);

            // Price calculation
            // Base fee is 0 for first badge, 2 for any thereafter
            $baseFee = $request->user()->badges()->count() === 0 ? 0 : 2;
            $lateFee = 2;
            $dualSidePrintUpgrade = 1;
            $extraCopyUpgrade = 2;

            $total = $baseFee;
            if ($event->preorder_ends_at->isPast()) {
                $total += $lateFee;
            }

            if ($validated['upgrades']['doubleSided']) {
                $total += $dualSidePrintUpgrade;
            }
            $total = round($total,2);

            // Tax is 19% in Germany
            $subtotal = round($total / 1.19,2);
            $tax = round($total - $subtotal,2);

            $badge = $fursuit->badges()->create([
                'status' => \App\Models\Badge\States\Pending::$name,
                'subtotal' => round($subtotal * 100),
                'tax_rate' => 0.19,
                'tax' => round($tax * 100),
                'total' => round($total * 100),
                'dual_side_print' => $validated['upgrades']['doubleSided'],
            ]);

            if ($validated['upgrades']['spareCopy']) {
                $clone = $badge->replicate();
                $clone->extra_copy = true;
                $clone->total = round($extraCopyUpgrade * 100);
                $clone->subtotal = round(($extraCopyUpgrade / 1.19) * 100);
                $clone->tax = round($clone->total - $clone->subtotal);
                $clone->extra_copy_of = $badge->id;
                $clone->save();
            }
        });


        return redirect()->route('badges.index');
    }

    public function show(Badge $badge)
    {
    }

    public function edit(Badge $badge)
    {
        return Inertia::render('Badges/BadgesEdit', [
            'badge' => $badge->load('fursuit.species'),
            'species' => Species::has('fursuits',count: 5)->orWhere('checked',true)->get('name'),
        ]);
    }

    public function update(Request $request, Badge $badge)
    {
    }

    public function destroy(Badge $badge)
    {
    }
}
