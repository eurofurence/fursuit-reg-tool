<?php

namespace App\Http\Controllers;

use App\Http\Requests\BadgeCreateRequest;
use App\Http\Requests\BadgeUpdateRequest;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\States\Pending;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgeCreatedNotification;
use App\Services\BadgeCalculationService;
use App\Services\FursuitImageService;
use App\Services\TokenRefreshService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class BadgeController extends Controller
{
    public function show(Request $request, Badge $badge)
    {
        $user = $request->user();

        // Check if user can view this badge
        Gate::authorize('view', $badge);

        // Load relationships
        $badge->load(['fursuit.species', 'fursuit.event', 'fursuit.user']);

        // Add edit permission. `updateAsOwner` and not `update`: this is the attendee
        // editor, and the panel override on `update` would offer an operator an edit
        // button that skips the print-lock and event-ended rules.
        $badge->canEdit = Gate::allows('updateAsOwner', $badge);

        return Inertia::render('Badges/BadgeShow', [
            'badge' => $badge,
            'canEdit' => $badge->canEdit,
            // The page states the reviewer's finding itself instead of sending the attendee
            // back to their mail to find out what to change. Null unless a rejection stands.
            'rejectionReason' => $badge->fursuit->rejectionReason(),
        ]);
    }

    public function index(Request $request)
    {
        $activeEvent = Event::getActiveEvent();
        $user = $request->user();

        // Current event badges
        $badges = $user->badges()
            ->whereHas('fursuit.event', function ($query) use ($activeEvent) {
                $query->where('id', $activeEvent?->id);
            })
            ->with(['fursuit.species', 'fursuit.event'])
            ->get();

        // Add edit permissions for each badge
        $badges->each(function ($badge) {
            $badge->canEdit = Gate::allows('update', $badge);
        });

        // Previous years badges that are not picked up yet
        $unpickedBadges = $user->badges()
            ->whereHas('fursuit.event', function ($query) use ($activeEvent) {
                if ($activeEvent) {
                    $query->where('id', '!=', $activeEvent->id);
                }
            })
            ->where('status_fulfillment', 'ready_for_pickup')
            ->with(['fursuit.species', 'fursuit.event'])
            ->get();

        // Calculate prepaid badges available
        $eventUser = $activeEvent ? $user->eventUser($activeEvent->id) : null;
        $prepaidBadges = $eventUser ? $eventUser->prepaid_badges : 0;
        $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($activeEvent?->id);

        return Inertia::render('Badges/BadgesIndex', [
            'badges' => $badges,
            'badgeCount' => $badges->count(),
            'unpickedBadges' => $unpickedBadges,
            'canCreate' => Gate::allows('create', Badge::class),
            'prepaidBadges' => $prepaidBadges,
            'prepaidBadgesLeft' => $prepaidBadgesLeft,
            'attendeeId' => $eventUser?->attendee_id,
            // No booth split and no opening hours here. This page used to render its own
            // copy of both, which meant the desk retiming itself had to be reflected in
            // two templates; the pickup card now links to /pickup, which owns them.
            'event' => $activeEvent ? [
                'id' => $activeEvent->id,
                'name' => $activeEvent->name,
                'state' => $activeEvent->state,
                'allowsOrders' => $activeEvent->allowsOrders(),
                'orderStartsAt' => $activeEvent->order_starts_at,
                'orderEndsAt' => $activeEvent->order_ends_at,
                'startsAt' => $activeEvent->starts_at,
                'massPrintedAt' => $activeEvent->mass_printed_at,
            ] : null,
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', Badge::class);

        $user = $request->user();
        $activeEvent = Event::getActiveEvent();
        $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($activeEvent?->id);

        return Inertia::render('Badges/BadgeForm', [
            'species' => Species::has('fursuits', count: 5)->orWhere('checked', true)->get('name'),
            'prepaidBadgesLeft' => $prepaidBadgesLeft,
        ]);
    }

    public function store(BadgeCreateRequest $request)
    {
        Gate::authorize('create', Badge::class);
        $badge = DB::transaction(function () use ($request) {
            // Lock user for update
            User::where('id', $request->user()->id)->lockForUpdate()->first();
            Badge::whereHas('fursuit', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })->lockForUpdate()->get();

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
                'image' => app(FursuitImageService::class)->store(
                    $request->file('image'),
                    $validated['crop'] ?? null,
                ),
                'published' => $validated['publish'],
                'catch_em_all' => $validated['catchEmAll'] ?? false,
            ]);

            // is Free Badge or Prepaid Badge
            // Use the same method as the create() action to ensure consistency
            $prepaidBadgesLeft = $request->user()->getPrepaidBadgesLeft($event->id);

            $isPrepaidBadge = $prepaidBadgesLeft > 0;

            // Returns in cents - the event's badge price unless prepaid
            $total = BadgeCalculationService::calculate(
                isFreeBadge: $isPrepaidBadge, // Use prepaid logic for "free" calculation
                isLate: false, // No late fees in new system
                event: $event,
            );

            // Tax is 19% in Germany
            $subtotal = round($total / 1.19);
            $tax = round($total - $subtotal);

            $badge = $fursuit->badges()->create([
                'status_fulfillment' => \App\Models\Badge\State_Fulfillment\Pending::$name,
                'status_payment' => $total === 0 ? Paid::$name : Unpaid::$name,
                'subtotal' => round($subtotal),
                'tax_rate' => 0.19,
                'tax' => round($tax),
                'total' => round($total),
                'dual_side_print' => true,
                'is_free_badge' => $isPrepaidBadge,
                'apply_late_fee' => false, // No late fees in new system
                'paid_at' => $total === 0 ? now() : null,
            ]);

            // Handle spare copy if requested
            if ($validated['upgrades']['spareCopy']) {
                $total = BadgeCalculationService::calculate(isSpareCopy: true, event: $event);
                $clone = $badge->replicate();
                $clone->is_free_badge = false;
                $clone->extra_copy = true;
                $clone->total = round($total);
                $clone->subtotal = round($total / 1.19);
                $clone->tax = round($clone->total - $clone->subtotal);
                $clone->extra_copy_of = $badge->id;
                $clone->status_payment = Unpaid::$name;
                $clone->paid_at = null; // Spare copies are not paid immediately
                $clone->save();
            }

            return $badge;
        });

        // send notification for new fursuit
        $badge->fursuit->user->notify(new BadgeCreatedNotification($badge));

        return redirect()->route('badges.index');
    }

    public function edit(Badge $badge, Request $request)
    {
        Gate::authorize('updateAsOwner', $badge);

        return Inertia::render('Badges/BadgeForm', [
            'canEdit' => $request->user()->can('updateAsOwner', $badge),
            'canDelete' => $request->user()->can('delete', $badge),
            'badge' => $badge->load('fursuit.species'),
            'species' => Species::has('fursuits', count: 5)->orWhere('checked', true)->get('name'),
            'hasExtraCopies' => $badge->where('extra_copy_of', $badge->id)->exists(),
        ]);
    }

    public function update(BadgeUpdateRequest $request, Badge $badge)
    {
        // `updateAsOwner`, not `update`: the panel override on `update` is request
        // independent since rebuild-plan 2.2, and this write path resets the fursuit to
        // pending review and recalculates the total, so it has to keep answering to the
        // extra-copy, print-lock, event-ended and "still Pending" rules.
        Gate::authorize('updateAsOwner', $badge);
        $badge = DB::transaction(function () use ($request, $badge) {
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
                /*
                 * The replaced photo is deliberately kept. FursuitObserver writes a
                 * submission revision pointing at this path, and a history entry with no
                 * picture cannot answer the question a reviewer is asking on a resubmission -
                 * "is this actually a different image?". The derived gallery variants are
                 * still cleaned up by the observer; only the master survives.
                 */
                $fursuit->image = app(FursuitImageService::class)->store(
                    $request->file('image'),
                    $validated['crop'] ?? null,
                );
            }
            /*
             * A changed submission goes back to the queue, and takes its publication
             * verdict with it.
             *
             * Clearing the block is the attendee's way back into the gallery: a badge that
             * was approved but barred from the public surfaces - digital art rather than a
             * photo of the suit - is told it may resubmit, and that promise is worthless if
             * the block outlives the photo it was about. The record is pending again, so
             * nothing prints until a reviewer has looked at the new submission and decided
             * both questions afresh.
             *
             * The status column is written directly rather than through a transition: the
             * machine has no approved -> pending edge, and this is not a review verdict, it
             * is the withdrawal of the thing a verdict was about.
             */
            if ($fursuit->isDirty(['species_id', 'name', 'image', 'catch_em_all', 'published'])) {
                $fursuit->status = Pending::$name;
                $fursuit->clearPublicationBlock();
            }
            $fursuit->save();
            /**
             * Badge
             */
            // The badge's own event, not the active one: editing an order must reprice it
            // at the price it was placed under, even if a later event has moved on.
            $total = BadgeCalculationService::calculate(
                isFreeBadge: $badge->is_free_badge,
                isLate: $badge->apply_late_fee,
                event: $fursuit->event,
            );
            $badge->total = round($total);
            $badge->subtotal = round($total / 1.19);
            $badge->tax = round($badge->total - $badge->subtotal);
            $badge->saveQuietly();

            return $badge;
        });

        return redirect()->route('badges.index');
    }

    public function destroy(Request $request, Badge $badge)
    {
        Gate::authorize('delete', $badge);
        DB::transaction(function () use ($badge) {
            // Lock Badge
            Badge::where('id', $badge->id)->orWhere('extra_copy_of', $badge->id)->lockForUpdate()->get();
            // Deleting a badge removes it from what the user owes: amountDue() only
            // counts badges that are unpaid and not soft deleted.
            if ($badge->extra_copy_of === null) {
                Badge::where('extra_copy_of', $badge->id)->get()->each->delete();
            }
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

    public function refreshPrepaidBadges(Request $request)
    {
        $user = $request->user();
        $activeEvent = Event::getActiveEvent();

        if (! $activeEvent) {
            return response()->json(['error' => 'No active event found'], 400);
        }

        try {
            // Get fresh token or use existing one
            $tokenService = new TokenRefreshService($user);
            $accessToken = $tokenService->getValidAccessToken();

            if (! $accessToken) {
                return response()->json(['error' => 'Unable to get authentication token'], 401);
            }

            // Get or create EventUser relationship
            $eventUser = EventUser::firstOrCreate([
                'user_id' => $user->id,
                'event_id' => $activeEvent->id,
            ], [
                'attendee_id' => null,
                'valid_registration' => false,
                'prepaid_badges' => 0,
            ]);

            // Get attendee info
            $attendeeListResponse = Http::attsrv()
                ->withToken($accessToken)
                ->get('/attendees')
                ->json();

            $regId = $attendeeListResponse['ids'][0] ?? null;

            if (! $regId) {
                return response()->json(['error' => 'No registration found'], 404);
            }

            // Get registration status
            $statusResponse = Http::attsrv()
                ->withToken($accessToken)
                ->get('/attendees/'.$regId.'/status');

            // Update EventUser with attendee info
            $eventUser->update([
                'attendee_id' => $regId,
                'valid_registration' => in_array($statusResponse->json()['status'], ['paid', 'checked in']),
            ]);

            // Check for fursuit packages
            $fursuit = Http::attsrv()
                ->withToken($accessToken)
                ->get('/attendees/'.$regId.'/packages/fursuit')
                ->json();

            $totalPrepaidBadges = 0;

            if ($fursuit['present'] && $fursuit['count'] > 0) {
                // Get additional fursuit badges
                $fursuitAdditional = Http::attsrv()
                    ->withToken($accessToken)
                    ->get('/attendees/'.$regId.'/packages/fursuitadd')
                    ->json();

                $additionalCopies = $fursuitAdditional['present'] ? $fursuitAdditional['count'] : 0;
                $totalPrepaidBadges = $fursuit['count'] + $additionalCopies;

                // Update the prepaid badges count
                $eventUser->update([
                    'prepaid_badges' => $totalPrepaidBadges,
                ]);

                // Mark as not created in reg system
                Http::attsrv()
                    ->withToken($accessToken)
                    ->post('/attendees/'.$regId.'/additional-info/fursuitbadge', [
                        'created' => false,
                    ]);
            } else {
                // No fursuit packages found
                $eventUser->update([
                    'prepaid_badges' => 0,
                ]);
            }

            return response()->json([
                'success' => true,
                'prepaid_badges' => $totalPrepaidBadges,
                'prepaid_badges_left' => $user->getPrepaidBadgesLeft($activeEvent->id),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to refresh prepaid badges', [
                'user_id' => $user->id,
                'event_id' => $activeEvent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to refresh prepaid badges'], 500);
        }
    }
}
