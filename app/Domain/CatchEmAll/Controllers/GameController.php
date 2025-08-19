<?php

namespace App\Domain\CatchEmAll\Controllers;

use App\Domain\CatchEmAll\Models\UserAchievement;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Domain\CatchEmAll\Services\AchievementService;
use App\Domain\CatchEmAll\Services\GameStatsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserCatchRequest;
use App\Models\Event;
use App\Models\FCEA\UserCatchLog;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class GameController extends Controller
{
    public function __construct(
        private AchievementService $achievementService,
        private GameStatsService $gameStatsService
    ) {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedEventId = $request->get('event');
        $isGlobal = $selectedEventId === 'global';

        // Get current event
        $currentEvent = $this->getCurrentEvent();
        $filterEvent = $this->getFilterEvent($selectedEventId, $isGlobal, $currentEvent);

        // Get user's game stats
        $gameStats = $this->gameStatsService->getUserStats($user, $filterEvent, $isGlobal);

        // Get leaderboard data
        $leaderboard = $this->gameStatsService->getLeaderboard($filterEvent, $isGlobal);

        // Get user's collection progress
        $collection = $this->gameStatsService->getUserCollection($user, $filterEvent, $isGlobal);

        // Get user's achievements
        $achievements = $this->getUserAchievements($user);

        // Get events for filter dropdown
        $eventsWithEntries = $this->getEventsWithEntries();

        // Check for recent catch
        $recentCatch = null;
        if (session()->has('caught_fursuit')) {
            $recentCatch = $this->getRecentCatchData(session()->get('caught_fursuit'));
        }

        return Inertia::render('CatchEmAll/Catch', [
            'gameStats' => $gameStats,
            'leaderboard' => $leaderboard,
            'collection' => $collection,
            'achievements' => $achievements,
            'eventsWithEntries' => $eventsWithEntries,
            'selectedEvent' => $selectedEventId ?: ($filterEvent?->id ?? 'global'),
            'isGlobal' => $isGlobal,
            'recentCatch' => $recentCatch,
        ]);
    }

    public function catch(UserCatchRequest $request)
    {
        $event = $this->getCurrentEvent();
        if (!$event) {
            return to_route('catch-em-all.catch')->with('error', 'No Event Available for Catch Em All');
        }

        // Rate limiting
        if ($seconds = $this->isRateLimited(Auth::id())) {
            return to_route('catch-em-all.catch')->with('error', "You may try again in {$seconds} seconds.");
        }

        $catchCode = strtoupper($request->validated('catch_code'));
        $user = Auth::user();

        // Log the attempt
        $logEntry = $this->createCatchLog($event, $user, $catchCode);

        // Validate fursuit exists
        $fursuit = $this->findFursuitByCode($catchCode);
        if (!$fursuit) {
            $logEntry->save();
            return to_route('catch-em-all.catch')->with('error', 'Invalid Code - Try Again!');
        }

        // Check if user is trying to catch themselves
        if ($user->id === $fursuit->user_id) {
            $logEntry->save();
            return to_route('catch-em-all.catch')->with('error', "You can't catch yourself!");
        }

        // Check if already caught
        $alreadyCaught = UserCatch::where('user_id', $user->id)
            ->where('fursuit_id', $fursuit->id)
            ->exists();

        $logEntry->already_caught = $alreadyCaught;

        if ($alreadyCaught) {
            $logEntry->save();
            return to_route('catch-em-all.catch')->with('error', 'Already caught this fursuiter!');
        }

        // Success! Create the catch record
        $logEntry->is_successful = true;
        $logEntry->save();

        $userCatch = new UserCatch([
            'user_id' => $user->id,
            'fursuit_id' => $fursuit->id,
            'event_id' => $event->id,
        ]);
        $userCatch->save();

        // Process achievements
        $this->achievementService->processAchievements($user, $userCatch);

        // Clear caches
        $this->clearGameCaches($event->id, $user->id);

        return to_route('catch-em-all.catch')->with('caught_fursuit', $fursuit->id);
    }

    public function leaderboard(Request $request)
    {
        $selectedEventId = $request->get('event');
        $isGlobal = $selectedEventId === 'global';

        $currentEvent = $this->getCurrentEvent();
        $filterEvent = $this->getFilterEvent($selectedEventId, $isGlobal, $currentEvent);

        // Get leaderboard data
        $leaderboard = $this->gameStatsService->getLeaderboard($filterEvent, $isGlobal, 50); // Show more players

        // Get events for filter dropdown
        $eventsWithEntries = $this->getEventsWithEntries();

        return Inertia::render('CatchEmAll/Leaderboard', [
            'leaderboard' => $leaderboard,
            'eventsWithEntries' => $eventsWithEntries,
            'selectedEvent' => $selectedEventId ?: ($filterEvent?->id ?? 'global'),
            'isGlobal' => $isGlobal,
        ]);
    }

    public function collection(Request $request)
    {
        $user = Auth::user();
        $selectedEventId = $request->get('event');
        $isGlobal = $selectedEventId === 'global';

        $currentEvent = $this->getCurrentEvent();
        $filterEvent = $this->getFilterEvent($selectedEventId, $isGlobal, $currentEvent);

        $collection = $this->gameStatsService->getUserCollection($user, $filterEvent, $isGlobal);
        $eventsWithEntries = $this->getEventsWithEntries();

        return Inertia::render('CatchEmAll/Collection', [
            'collection' => $collection,
            'eventsWithEntries' => $eventsWithEntries,
            'selectedEvent' => $selectedEventId ?: ($filterEvent?->id ?? 'global'),
            'isGlobal' => $isGlobal,
        ]);
    }

    public function achievements()
    {
        $user = Auth::user();
        $achievements = $this->getUserAchievements($user, true); // Include progress details

        return Inertia::render('CatchEmAll/Achievements', [
            'achievements' => $achievements,
        ]);
    }

    public function introduction()
    {
        return Inertia::render('CatchEmAll/Introduction');
    }

    public function completeIntroduction(Request $request)
    {
        $user = Auth::user();
        $currentEvent = Event::latest('starts_at')->first();

        if (!$currentEvent) {
            return redirect()->route('catch-em-all.introduction')->with('error', 'No active event found.');
        }

        // Get or create event user relationship
        $eventUser = $user->eventUsers()->where('event_id', $currentEvent->id)->first();

        // Mark as introduced
        $eventUser->update(['catch_em_all_introduced' => true]);

        // Log for debugging
        \Log::info('User introduction completed', [
            'user_id' => $user->id,
            'event_id' => $currentEvent->id,
            'event_user_id' => $eventUser->id,
            'introduced' => $eventUser->fresh()->catch_em_all_introduced
        ]);

        return redirect()->route('catch-em-all.catch')->with('success', 'Welcome to Fursuit Catch em All! Happy hunting!');
    }

    private function getCurrentEvent(): ?Event
    {
        return Event::latest('starts_at')->first();
    }

    private function getFilterEvent($selectedEventId, bool $isGlobal, $currentEvent)
    {
        if ($isGlobal || !$selectedEventId) {
            return $isGlobal ? null : $currentEvent;
        }

        return Event::find($selectedEventId);
    }

    private function getUserAchievements(User $user, bool $detailed = false)
    {
        $query = UserAchievement::where('user_id', $user->id);

        if (!$detailed) {
            $query->where('earned_at', '!=', null);
        }

        $achievements = $query->get();
        $result = [];

        foreach ($achievements as $achievement) {
            $result[] = [
                'id' => $achievement->id,
                'achievement' => $achievement->achievement->value,
                'title' => $achievement->achievement->getTitle(),
                'description' => $achievement->achievement->getDescription(),
                'icon' => $achievement->achievement->getIcon(),
                'completed' => $achievement->isCompleted(),
                'progress' => $achievement->progress,
                'maxProgress' => $achievement->max_progress,
                'progressPercentage' => $achievement->getProgressPercentage(),
                'earnedAt' => $achievement->earned_at,
            ];
        }

        return $result;
    }

    private function getEventsWithEntries()
    {
        return Event::whereHas('fursuits.catchedByUsers')
            ->orderByDesc('starts_at')
            ->get(['id', 'name', 'starts_at']);
    }

    private function getRecentCatchData($fursuitId)
    {
        $fursuit = Fursuit::with(['species', 'user'])->find($fursuitId);
        if (!$fursuit)
            return null;

        $userCatch = new UserCatch(['fursuit_id' => $fursuitId]);
        $rarity = $userCatch->getSpeciesRarity();

        return [
            'id' => $fursuit->id,
            'name' => $fursuit->name,
            'species' => $fursuit->species->name ?? 'Unknown',
            'user' => $fursuit->user->name ?? 'Anonymous',
            'image' => $fursuit->image_webp_url,
            'rarity' => [
                'level' => $rarity->value,
                'label' => $rarity->getLabel(),
                'color' => $rarity->getColor(),
                'gradient' => $rarity->getGradient(),
                'icon' => $rarity->getIcon(),
            ],
        ];
    }

    private function createCatchLog($event, $user, $catchCode): UserCatchLog
    {
        return new UserCatchLog([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'catch_code' => $catchCode,
            'is_successful' => false,
            'already_caught' => false,
        ]);
    }

    private function findFursuitByCode(string $code): ?Fursuit
    {
        return Fursuit::where('catch_code', $code)
            ->where('catch_em_all', true)
            ->first();
    }

    private function isRateLimited(int $userId): int
    {
        $key = "fursuit_catch:{$userId}";
        $maxAttempts = config('fcea.fursuit_catch_attempts_per_minute', 20);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return RateLimiter::availableIn($key);
        }

        RateLimiter::increment($key);
        return 0;
    }

    private function clearGameCaches(int $eventId, int $userId): void
    {
        $keys = [
            "game_stats_global",
            "game_stats_{$eventId}",
            "game_stats_global_{$userId}",
            "game_stats_{$eventId}_{$userId}",
            "leaderboard_global_10",
            "leaderboard_{$eventId}_10",
            "collection_global",
            "collection_{$eventId}",
            "collection_global_{$userId}",
            "collection_{$eventId}_{$userId}",
            "total_fursuiters_{$eventId}", // TODO: Forget when new fursuit gets approved and not here
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
