<?php

namespace App\Domain\CatchEmAll\Controllers;

use App\Domain\CatchEmAll\Services\GameStatsService;
use App\Domain\CatchEmAll\Services\SpeciesRarityService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FCEA\UserCatchRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Models\UserProfile\States\Approved;
use App\Models\UserProfile\States\Rejected;
use App\Models\UserProfile\UserProfile;
use App\Models\UserProfile\UserProfileLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    public function __construct(
        private GameStatsService $gameStatsService,
        private SpeciesRarityService $speciesRarity,
    ) {}

    /**
     * Public profile page
     *
     * Access by UUID (privacy concerns @ Rusty)
     * Displays name, description, profile pic, and links
     * Not tied to event (how often do people change their socials?)
     * Displays current event fursuits and their stats
     */
    public function show(Request $request, UserProfile $userProfile): Response|RedirectResponse
    {
        $isOwner = (bool) $request->user()?->is($userProfile->user);

        if (! $isOwner && ! $userProfile->status instanceof Approved) {
            return to_route('catch-em-all.catch');
        }

        $userProfile->load(['user', 'links']);
        $event = Event::getActiveEvent();

        return Inertia::render('CatchEmAll/UserProfile', [
            'profile' => [
                'uuid' => $userProfile->uuid,
                'name' => $userProfile->user->name,
                'avatar' => $userProfile->user->avatar_url,
                'description' => $userProfile->description,
                'links' => $userProfile->links->pluck('url')->values(),
                'status' => $isOwner ? $userProfile->status::$name : null,
                'rejection_reason' => $isOwner && $userProfile->status instanceof Rejected
                    ? $userProfile->rejection_reason
                    : null,
            ],
            'fursuits' => $this->fursuits($userProfile, $event),
            'stats' => $this->stats($userProfile->user, $event),
            'canEdit' => $isOwner,
        ]);
    }

    /**
     * The user's catcher stats
     */
    private function stats(User $user, ?Event $event): ?array
    {
        $eventUser = $event ? $user->eventUsers()->where('event_id', $event->id)->first() : null;

        if (! $eventUser) {
            return null;
        }

        $stats = $this->gameStatsService->getUserStats($eventUser);

        return [
            'caught' => $stats['totalCatches'],
            'rank' => $stats['totalCatches'] > 0 ? $stats['rank'] : null,
        ];
    }

    /**
     * The user's approved fursuits participating in Catch-Em-All
     */
    private function fursuits(UserProfile $userProfile, ?Event $event)
    {
        if (! $event) {
            return collect();
        }

        $fursuits = $userProfile->user->fursuits()
            ->where('event_id', $event->id)
            ->where('catch_em_all', true)
            ->where('status', 'approved')
            ->with('species')
            ->withCount('catchedByUsers')
            ->orderByDesc('catched_by_users_count')
            ->get();

        $ranks = UserCatchRanking::whereIn('fursuit_id', $fursuits->pluck('id'))
            ->whereNotNull('fursuit_id')
            ->pluck('rank', 'fursuit_id');

        return $fursuits->map(function (Fursuit $fursuit) use ($ranks, $event) {
            $rarity = $this->speciesRarity->forFursuit($event, $fursuit);

            return [
                'id' => $fursuit->id,
                'name' => $fursuit->name,
                'species' => $fursuit->species?->name,
                'image' => $fursuit->image_webp_url,
                'caught' => $fursuit->catched_by_users_count,
                'rank' => $ranks->get($fursuit->id),
                'rarity' => [
                    'level' => $rarity->value,
                    'label' => $rarity->getLabel(),
                    'color' => $rarity->getColor(),
                    'icon' => $rarity->getIcon(),
                ],
            ];
        })->values();
    }

    public function update(Request $request, UserProfile $userProfile): RedirectResponse
    {
        abort_unless($request->user()?->is($userProfile->user), 403);

        if (is_array($request->input('links'))) {
            $request->merge([
                'links' => array_map(
                    fn ($url) => is_string($url) ? UserProfileLink::normalizeUrl($url) : $url,
                    $request->input('links'),
                ),
            ]);
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'links' => ['array', 'max:10'],
            'links.*' => ['required', 'string', 'url:http,https', 'max:255', 'distinct'],
        ]);

        $userProfile->update(['description' => $validated['description'] ?? null]);

        // Sync links by URL
        $urls = collect($validated['links'] ?? []);
        $userProfile->links()->whereNotIn('url', $urls)->get()->each->delete();
        $existing = $userProfile->links()->pluck('url');
        $urls->diff($existing)->each(
            fn (string $url) => $userProfile->links()->create(['url' => $url])
        );

        $message = $userProfile->refresh()->status instanceof Approved
            ? 'Profile updated.'
            : 'Profile updated. Your changes will be publicly visible once they have been reviewed.';

        return back()->with('success', $message);
    }
}
