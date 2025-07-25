<?php

namespace App\Http\Controllers;

use App\Http\Requests\BadgeCreateRequest;
use App\Http\Requests\BadgeUpdateRequest;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Event;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgeCreatedNotification;
use App\Services\BadgeCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class BadgeController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Badges/BadgesIndex', [
            'badges' => auth()->user()->badges()->with('fursuit.species')->get(),
            'canCreate' => Gate::allows('create', Badge::class),
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Badge::class);
        return Inertia::render('Badges/BadgesCreate', [
            'species' => Species::has('fursuits', count: 5)->orWhere('checked', true)->get('name'),
            'isFree' => auth()->user()->hasFreeBadge(),
            'freeBadgeCopies' => auth()->user()->hasFreeBadge() ? auth()->user()->free_badge_copies : 0,
        ]);
    }

    public function store(BadgeCreateRequest $request)
    {
        Gate::authorize('create', Badge::class);
        $badge = DB::transaction(function () use ($request) {
            // Lock Wallet Balance
            $request->user()->balanceInt;
            // Lock user for update
            User::where('id', auth()->id())->lockForUpdate()->first();
            Badge::whereHas('fursuit', function ($query) {
                $query->where('user_id', auth()->id());
            })->lockForUpdate()->get();

            $event = Event::getActiveEvent();
            if ($event === null) {
                abort(404);
            }
            $validated = $request->validated();
            // Create Fursuit
            $fursuit = $request->user()->fursuits()->create([
                'status' => \App\Models\Fursuit\States\Pending::$name,
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

            // is Free Badge
            $isFreeBadge = $request->user()->hasFreeBadge();

            // Returns in cents
            $total = BadgeCalculationService::calculate(
                isFreeBadge: $isFreeBadge,
                isLate: $event->preorder_ends_at->isPast(),
            );

            // Tax is 19% in Germany
            $subtotal = round($total / 1.19, );
            $tax = round($total - $subtotal);

            $badge = $fursuit->badges()->create([
                'status_fulfillment' => \App\Models\Badge\State_Fulfillment\Pending::$name,
                'status_payment' => $total === 0 ? Paid::$name : Unpaid::class,
                'subtotal' => round($subtotal),
                'tax_rate' => 0.19,
                'tax' => round($tax),
                'total' => round($total),
                'dual_side_print' => true,
                'is_free_badge' => $isFreeBadge,
                'apply_late_fee' => $event->preorder_ends_at->isPast(),
                'paid_at' => $total === 0 ? now() : null,
            ]);
            // Pay for Badge (force pay as we allow negative balance)
            $request->user()->forcePay($badge);

            if ($isFreeBadge) {
                $total = BadgeCalculationService::calculate(isSpareCopy: true);
                for ($i = 0; $i < $request->user()->free_badge_copies; $i++) {
                    $clone = $badge->replicate();
                    $clone->is_free_badge = false;
                    $clone->extra_copy = true;
                    $clone->total = round($total);
                    $clone->subtotal = round($total / 1.19);
                    $clone->tax = round($clone->total - $clone->subtotal);
                    $clone->extra_copy_of = $badge->id;
                    $clone->save();
                    $request->user()->forcePay($clone->fresh());
                }
                $request->user()->wallet->deposit($total * $request->user()->free_badge_copies, ['title' => 'Fuirsuit Badge', 'description' => 'Already paid with the EF registration system']);
                $request->user()->free_badge_copies = 0;
                $request->user()->has_free_badge = false;
                $request->user()->save();

                // Mark fursuitbadge as created
                \Illuminate\Support\Facades\Http::attsrv()
                    ->withToken($request->user()->token)
                    ->post('/attendees/' . $request->user()->attendee_id . '/additional-info/fursuitbadge', [
                        'created' => true,
                    ]);

            } elseif ($validated['upgrades']['spareCopy']) {
                $total = BadgeCalculationService::calculate(isSpareCopy: true);
                $clone = $badge->replicate();
                $clone->is_free_badge = false;
                $clone->extra_copy = true;
                $clone->total = round($total);
                $clone->subtotal = round($total / 1.19);
                $clone->tax = round($clone->total - $clone->subtotal);
                $clone->extra_copy_of = $badge->id;
                $clone->status_payment = Unpaid::class;
                $clone->paid_at = null; // Spare copies are not paid immediately
                $clone->save();
                $request->user()->forcePay($clone->fresh());
            }

            return $badge;
        });

        // send notification for new fursuit
        $badge->fursuit->user->notify(new BadgeCreatedNotification($badge));

        return redirect()->route('badges.index');
    }

    public function edit(Badge $badge, Request $request)
    {
        Gate::authorize('view', $badge);
        return Inertia::render('Badges/BadgesEdit', [
            'canEdit' => $request->user()->can('update', $badge),
            'canDelete' => $request->user()->can('delete', $badge),
            'badge' => $badge->load('fursuit.species'),
            'species' => Species::has('fursuits', count: 5)->orWhere('checked', true)->get('name'),
            'hasExtraCopies' => $badge->where('extra_copy_of', $badge->id)->exists(),
        ]);
    }

    public function update(BadgeUpdateRequest $request, Badge $badge)
    {
        Gate::authorize('update', $badge);
        $badge = DB::transaction(function () use ($request, $badge) {
            $request->user()->can('update', $badge);
            // Lock Wallet Balance
            $request->user()->balanceInt;
            // Lock Badge
            $badge->where('id', $badge->id)->orWhere('extra_copy_of', $badge->id)->lockForUpdate()->get();
            // Update Badge
            $validated = $request->validated();
            $fursuit = $badge->fursuit;
            $fursuit->fill([
                'species_id' => Species::firstOrCreate([
                    'name' => $validated['species'],
                ], [
                    'name' => $validated['species'],
                    'checked' => false,
                ])->id,
                'name' => $validated['name'],
                'published' => $validated['publish'],
                'catch_em_all' => $validated['catchEmAll'] ?? false,
            ]);
            if ($request->hasFile('image')) {
                Storage::delete($fursuit->image);
                $fursuit->image = $request->file('image')->store('fursuits');
            }
            // if species_id, name or image changed, status goes back to pending review
            if ($fursuit->isDirty(['species_id', 'name', 'image'])) {
                $fursuit->status = \App\Models\Badge\State_Fulfillment\Pending::$name;
            }
            $fursuit->save();
            /**
             * Badge
             */
            $previousTotal = $badge->total;
            $total = BadgeCalculationService::calculate(
                isFreeBadge: $badge->is_free_badge,
                isLate: $badge->apply_late_fee,
            );
            if ($previousTotal !== $total) {
                $badge->fursuit->user->refund($badge);
            }
            $badge->total = round($total);
            $badge->subtotal = round($total / 1.19);
            $badge->tax = round($badge->total - $badge->subtotal);
            $badge->saveQuietly();
            // Difference needs to be paid
            if ($previousTotal !== $total) {
                $request->user()->forcePay($badge);
            }
            return $badge;
        });
        return redirect()->route('badges.index');
    }

    public function destroy(Request $request, Badge $badge)
    {
        Gate::authorize('delete', $badge);
        DB::transaction(function () use ($request, $badge) {
            // Lock Wallet Balance
            $request->user()->balanceInt;
            // Lock Badge
            $badge->where('id', $badge->id)->orWhere('extra_copy_of', $badge->id)->lockForUpdate()->get();
            // Delete Badge and Refund
            if ($badge->extra_copy_of === null) {
                $copies = $badge->where('extra_copy_of', $badge->id)->get();
                // Delete all copies and refund each one
                foreach ($copies as $copy) {
                    $request->user()->refund($copy);
                    $copy->delete();
                }
            }
            // Refund Badge
            $request->user()->refund($badge);
            $badge->delete();
            // Delete Fursuit if no badges left
            if ($badge->fursuit->badges()->count() === 0) {
                $badge->fursuit->delete();
            }
        });
        // if user has no badges left redirect to welcome
        if ($request->user()->badges()->count() === 0) {
            return redirect()->route('welcome');
        }
        return redirect()->route('badges.index');
    }
}
