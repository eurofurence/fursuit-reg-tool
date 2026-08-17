<?php

namespace App\Domain\CatchEmAll\Controllers;

use App\Domain\CatchEmAll\Achievements\Utils\AchievementFactory;
use App\Domain\CatchEmAll\Services\FursuitRankingService;
use App\Domain\CatchEmAll\Services\GameStatsService;
use App\Domain\CatchEmAll\Services\SpeciesPopulationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\SpecialCodeController;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\FCEA\UserCatchRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\UserProfile\States\Approved;
use App\Models\UserProfile\States\Rejected;
use App\Models\UserProfile\UserProfile;
use App\Models\UserProfile\UserProfileLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserProfileController extends Controller
{
    public function __construct(
        private GameStatsService $gameStatsService,
        private SpeciesPopulationService $speciesPopulation,
        private FursuitRankingService $fursuitRanking,
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
        $eventUser = $event
            ? $userProfile->user->eventUsers()->where('event_id', $event->id)->first()
            : null;

        return Inertia::render('CatchEmAll/UserProfile', [
            'profile' => [
                'uuid' => $userProfile->uuid,
                'name' => $userProfile->user->name,
                'avatar' => $userProfile->user->avatar_url,
                'description' => $userProfile->description,
                'colour' => $userProfile->colourKey(),
                'colourHex' => $userProfile->colourHex(),
                'links' => $userProfile->links->pluck('url')->values(),
                'status' => $isOwner ? $userProfile->status::$name : null,
                'rejection_reason' => $isOwner && $userProfile->status instanceof Rejected
                    ? $userProfile->rejection_reason
                    : null,
                'specialCodes' => $isOwner ? $this->specialCodes($eventUser) : [],
            ],
            'fursuits' => $this->fursuits($userProfile, $event),
            'stats' => $this->stats($eventUser),
            'achievements' => $this->achievements($eventUser),
            'achievementStats' => $eventUser
                ? AchievementFactory::getUserAchievementStats($eventUser)
                : [
                    'total' => 0,
                    'earned' => 0,
                    'earnedOptional' => 0,
                    'totalWithOptional' => 0,
                ],
            'palette' => UserProfile::PALETTE,
            'fromFursuit' => (int) $request->query('from') ?: null,
            'canEdit' => $isOwner,
        ]);
    }

    /**
     * The user's catcher stats
     */
    private function stats(?EventUser $eventUser): ?array
    {
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
     * Earned achievements, for the badge case on the profile.
     *
     * Only completed ones, and never the secret or hidden entries: a profile is
     * a public page, so it must not leak what somebody has left to find.
     */
    private function achievements(?EventUser $eventUser): array
    {
        if (! $eventUser) {
            return [];
        }

        return collect(AchievementFactory::getUserAchievementData($eventUser))
            ->filter(fn ($achievement) => $achievement['completed'] && ! $achievement['hiddenByLock'])
            ->map(fn ($achievement) => [
                'id' => $achievement['id'],
                'title' => $achievement['title'],
                'maxProgress' => $achievement['maxProgress'],
                'isOptional' => $achievement['isOptional'],
                'earnedAt' => $achievement['earnedAt'],
                'tier' => $achievement['tier'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{typeName: string|null, code: string, url: string}>
     */
    private function specialCodes(?EventUser $eventUser): array
    {
        if (! $eventUser) {
            return [];
        }

        return $eventUser->specialCodes()
            ->orderByDesc('special_code_connection.created_at')
            ->get(['special_codes.code', 'special_codes.type'])
            ->map(fn ($specialCode) => [
                'typeName' => SpecialCodeController::typeLabel($specialCode->type),
                'code' => $specialCode->code,
                'url' => SpecialCodeController::catchUrl($specialCode->code),
            ])
            ->values()
            ->all();
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
            $ranking = $this->fursuitRanking->forFursuit($event, $fursuit);

            return [
                'id' => $fursuit->id,
                'name' => $fursuit->name,
                'species' => $fursuit->species?->name,
                'image' => $fursuit->image_webp_url,
                'caught' => $fursuit->catched_by_users_count,
                'rank' => $ranks->get($fursuit->id),
                'ranking' => [
                    'level' => $ranking->value,
                    'label' => $ranking->getLabel(),
                    'color' => $ranking->getColor(),
                    'icon' => $ranking->getIcon(),
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
            // optional: a profile is given a colour when it is created, so an
            // update that only touches the text or the links need not send one
            'colour' => ['sometimes', 'string', Rule::in(array_keys(UserProfile::PALETTE))],
            'links' => ['array', 'max:10'],
            'links.*' => ['required', 'string', 'url:http,https', 'max:255', 'distinct'],
        ]);

        $userProfile->update([
            'description' => $validated['description'] ?? null,
            'colour' => $validated['colour'] ?? $userProfile->colourKey(),
        ]);

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
