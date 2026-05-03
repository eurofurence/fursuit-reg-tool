<?php

namespace App\Domain\CatchEmAll\Controllers;

use App\Domain\CatchEmAll\Achievements\Utils\AchievementFactory;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Domain\CatchEmAll\Services\AchievementService;
use App\Domain\CatchEmAll\Services\GameStatsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserCatchRequest;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\FCEA\UserCatchLog;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use PhpParser\Error;

class GameController extends Controller
{
    public function __construct(
        private AchievementService $achievementService,
        private GameStatsService $gameStatsService
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $selectedEventId = $request->get('event');

        // Get event
        $selectedEvent = $this->getCurrentEvent(); // TODO: Add fetch method for Selected Event based on filter
        $eventUser = $this->getEventUser($user, $selectedEvent);

        // Get user's game stats
        $gameStats = $this->gameStatsService->getUserStats($eventUser);

        // Get leaderboard data
        $leaderboard = $this->gameStatsService->getLeaderboard($selectedEvent);

        // Get user's collection progress
        $collection = $this->gameStatsService->getUserCollection($eventUser);

        // Get user's achievements
        $achievements = AchievementFactory::getUserAchievementData($eventUser);

        // Get events for filter dropdown
        $eventsWithEntries = $this->getEventsWithEntries();

        $isGameRunning = $selectedEvent?->isCatchEmAllActive();

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
            'selectedEvent' => $selectedEvent?->id,
            'recentCatch' => $recentCatch,
            'isGameRunning' => $isGameRunning,
        ]);
    }

    public function catch(UserCatchRequest $request)
    {
        $event = $this->getCurrentEvent();
        if (! $event) {
            return to_route('catch-em-all.catch')->with('error', 'No Event Available for Catch Em All');
        }

        // Rate limiting
        if ($seconds = $this->isRateLimited(Auth::id())) {
            return to_route('catch-em-all.catch')->with('error', "You may try again in {$seconds} seconds.");
        }

        if (! $event->isCatchEmAllActive()) {
            return to_route('catch-em-all.catch')->with('error', 'The Catch Em All game is not currently active.');
        }

        $catchCode = strtoupper($request->validated('catch_code'));
        $user = Auth::user();
        $eventUser = $this->getEventUser($user, $event);

        // Log the attempt
        $logEntry = $this->createCatchLog($event, $user, $catchCode);

        // Check for both special code and fursuit code simultaneously
        /**
         * @var SpecialCode|null
         */
        $specialCode = SpecialCode::where('event_id', $event->id)
            ->where('code', $catchCode)
            ->first();

        $fursuit = Fursuit::where('event_id', $event->id)
            ->where('catch_code', $catchCode)
            ->where('catch_em_all', true)
            ->first();

        // If neither exists, it's an invalid code
        if (! $specialCode && ! $fursuit) {
            $logEntry->save();

            return to_route('catch-em-all.catch')->with('error', 'Invalid Code - Try Again!');
        }

        $errors = [];
        $wasSuccessful = true;
        $userCatch = null;
        /**
         * @var SpecialCodeType|null $specialCodeResult
         */
        $specialCodeType = null;

        if ($specialCode) {
            try {
                $actionInstance = $specialCode->createActionInstance();
                $specialCodeType = $actionInstance->use($eventUser);
            } catch (\Exception $e) {
                $errors[] = 'Error processing special code';
            }
        }

        // Check if user is trying to catch themselves
        if ($fursuit) {
            if ($user->id === $fursuit->user_id) {
                if (! $specialCode) {
                    $errors[] = "You can't catch yourself!";
                    $wasSuccessful = false;
                }
            } else {
                // Check if already caught
                $alreadyCaught = UserCatch::where('event_user_id', $user->id)
                    ->where('fursuit_id', $fursuit->id)
                    ->exists();

                $logEntry->already_caught = $alreadyCaught;

                if ($alreadyCaught) {
                    if (! $specialCode) {
                        $errors[] = 'Already caught this fursuiter!';
                        $wasSuccessful = false;
                    }
                } else {
                    // Success! Create the catch record
                    $userCatch = new UserCatch([
                        'event_user_id' => $eventUser->id,
                        'fursuit_id' => $fursuit->id,
                    ]);
                    $userCatch->save();
                }
            }
        }

        if ($wasSuccessful) {
            $this->achievementService->processAchievements(
                $eventUser,
                $userCatch,
                $specialCodeType,
            );
        }

        // Determine success/failure and log
        $logEntry->is_successful = $wasSuccessful;
        $logEntry->save();

        // If there were errors and no successes, return the first error
        if (! $wasSuccessful && ! empty($errors)) {
            return to_route('catch-em-all.catch')->with('error', $errors[0]);
        }

        // Clear caches if any action was successful
        if ($wasSuccessful) {
            $this->clearGameCaches($eventUser);
        }

        // Determine response message and redirect
        if ($specialCode && $fursuit) {
            // Both were successful
            return to_route('catch-em-all.catch')
                ->with('caught_fursuit', $fursuit->id)
                ->with('success', 'Special code redeemed and fursuiter caught!');
        } elseif ($specialCode) {
            // Only special code was successful
            return to_route('catch-em-all.catch')->with('success', 'Special code redeemed successfully!');
        } elseif ($fursuit) {
            // Only fursuit catch was successful
            return to_route('catch-em-all.catch')->with('caught_fursuit', $fursuit->id);
        }

        // This shouldn't happen, but just in case
        return to_route('catch-em-all.catch')->with('error', 'Unexpected error occurred.');
    }

    public function leaderboard(Request $request)
    {
        $selectedEventId = $request->get('event');
        $rankCutoff = 3;

        $selectedEvent = $this->getCurrentEvent(); // TODO: Add fetch method for Selected Event based on filter

        // Get leaderboard data
        $leaderboard = $this->gameStatsService->getLeaderboard($selectedEvent, 50, $rankCutoff); // Show more players

        // Get events for filter dropdown
        $eventsWithEntries = $this->getEventsWithEntries();

        $user = Auth::user();
        $eventUser = $this->getEventUser($user, $selectedEvent);
        $userStat = $this->gameStatsService->getUserStats($eventUser);

        $userLeaderboard = [];
        if ($userStat['rank'] > $rankCutoff && $userStat['totalCatches'] > 0) {
            $userLeaderboard = $this->gameStatsService->getUserLeaderboard(
                $eventUser,
                $userStat['rank'],
                $userStat['totalCatches'],
                $user->name,
                $rankCutoff
            );
        }

        return Inertia::render('CatchEmAll/Leaderboard', [
            'user' => $user,
            'leaderboard' => $leaderboard,
            'userLeaderboard' => $userLeaderboard,
            'eventsWithEntries' => $eventsWithEntries,
            'selectedEvent' => $selectedEvent?->id,
        ]);
    }

    public function collection(Request $request)
    {
        $user = Auth::user();
        $selectedEventId = $request->get('event');

        $selectedEvent = $this->getCurrentEvent();
        $eventUser = $this->getEventUser($user, $selectedEvent);

        $collection = $this->gameStatsService->getUserCollection($eventUser);
        $eventsWithEntries = $this->getEventsWithEntries();

        return Inertia::render('CatchEmAll/Collection', [
            'collection' => $collection,
            'eventsWithEntries' => $eventsWithEntries,
            'selectedEvent' => $selectedEvent->id,
        ]);
    }

    public function achievements()
    {
        $user = Auth::user();
        $eventUser = $this->getEventUser($user, $this->getCurrentEvent());
        $achievements = AchievementFactory::getUserAchievementData($eventUser);

        return Inertia::render('CatchEmAll/Achievements', [
            'achievements' => $achievements,
        ]);
    }

    public function introduction()
    {
        return Inertia::render('CatchEmAll/Introduction');
    }

    public function profile()
    {
        return Inertia::render('CatchEmAll/Profile');
    }

    public function completeIntroduction(Request $request)
    {
        $user = Auth::user();
        $eventUser = $this->getEventUser($user, $this->getCurrentEvent());

        // Mark as introduced
        $eventUser->update(['catch_em_all_introduced' => true]);

        // Log for debugging
        \Log::info('User introduction completed', [
            'event_user_id' => $eventUser->id,
            'introduced' => $eventUser->fresh()->catch_em_all_introduced,
        ]);

        return redirect()->route('catch-em-all.catch')->with('success', 'Welcome to Fursuit Catch em All! Happy hunting!');
    }

    private function getCurrentEvent(): ?Event
    {
        return Event::latest('starts_at')->first();
    }

    private function getEventUser(User $user, Event $event): ?EventUser
    {
        return EventUser::where('user_id', $user->id)
            ->where('event_id', $event->id)
            ->first();
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
        if (! $fursuit) {
            return null;
        }

        $userCatch = new UserCatch(['fursuit_id' => $fursuitId]);
        $rarity = $userCatch->getFursuitRarity();

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

    /**
     * @todo UPDATE DB AND THIS TO TAKE EVENTUSERS AS INPUT
     *
     * @param  mixed  $event
     * @param  mixed  $user
     * @param  mixed  $catchCode
     */
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

    private function clearGameCaches(EventUser $eventUser): void
    {
        $keys = [
            "game_stats_{$eventUser->id}",
            "leaderboard_{$eventUser->event_id}",
            "user_leaderboard_{$eventUser->id}",
            "collection_{$eventUser->id}",
            "total_fursuiters_{$eventUser->event_id}", // TODO: Forget when new fursuit gets approved and not here
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
