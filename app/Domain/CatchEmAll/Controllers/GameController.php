<?php

namespace App\Domain\CatchEmAll\Controllers;

use App\Domain\CatchEmAll\Achievements\Utils\AchievementFactory;
use App\Domain\CatchEmAll\Achievements\Utils\AchievementRegister;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Domain\CatchEmAll\Models\UserSpecialCatch;
use App\Domain\CatchEmAll\Services\AchievementService;
use App\Domain\CatchEmAll\Services\FursuitRankingService;
use App\Domain\CatchEmAll\Services\GameStatsService;
use App\Domain\CatchEmAll\Services\SpeciesPopulationService;
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

class GameController extends Controller
{
    public function __construct(
        private AchievementService $achievementService,
        private GameStatsService $gameStatsService,
        private SpeciesPopulationService $speciesPopulation,
        private FursuitRankingService $fursuitRanking,
    ) {}

    public function index(Request $request)
    {
        $selectedEvent = $this->getCurrentEvent(); // TODO: Add fetch method for Selected Event based on filter

        $isGameRunning = $selectedEvent?->isCatchEmAllActive();

        // Check for recent catch
        $recentCatch = null;
        if (session()->has('caught_fursuit')) {
            $recentCatch = $this->getRecentCatchData(session()->get('caught_fursuit'));
        }

        $eventUser = $selectedEvent ? $this->getEventUser(Auth::user(), $selectedEvent) : null;

        return Inertia::render('CatchEmAll/Catch', [
            'recentCatch' => $recentCatch,
            'isGameRunning' => $isGameRunning,
            'code' => $request->has('code') ? $request->input('code') : '',
            'autoCatch' => $request->has('auto') && $request->has('code'),
            'recent' => $eventUser ? $this->recentCatches($eventUser) : [],
            'caughtTotal' => $eventUser ? $eventUser->fursuitsCatched()->count() : 0,
            'eventTotal' => $selectedEvent
                ? Fursuit::where('event_id', $selectedEvent->id)->where('catch_em_all', true)->count()
                : 0,
        ]);
    }

    public function catch(UserCatchRequest $request)
    {
        $event = $this->getCurrentEvent();
        if (! $event) {
            return to_route('catch-em-all.catch')->with('error', 'No event is running right now');
        }

        // Rate limiting
        if ($seconds = $this->isRateLimited(Auth::id())) {
            return to_route('catch-em-all.catch')->with('error', "Too many tries. Wait {$seconds} seconds.");
        }

        if (! $event->isCatchEmAllActive()) {
            return to_route('catch-em-all.catch')->with('error', 'The game is closed right now');
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
            // A fursuit a reviewer barred from the public surfaces cannot be caught, even
            // if its owner has turned the switch back on since.
            ->publicationAllowed()
            ->first();

        // If neither exists, it's an invalid code
        if (! $specialCode && ! $fursuit) {
            $logEntry->save();

            return to_route('catch-em-all.catch')->with('error', 'No badge with that code');
        }

        $errors = [];
        $wasSuccessful = true;
        $userCatch = null;
        /**
         * @var SpecialCodeType|null $specialCodeResult
         */
        $specialCodeType = null;

        if ($specialCode) {
            // Check if it was already claimed by this user
            $alreadyClaimed = UserCatchLog::where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->where('catch_code', $catchCode)
                ->where('is_successful', true)
                ->exists();
            if ($alreadyClaimed) {
                $errors[] = 'You already claimed that special code';
                $wasSuccessful = false;
            } else {
                try {
                    $actionInstance = $specialCode->createActionInstance();
                    $specialCodeType = $actionInstance->use($eventUser);

                    UserSpecialCatch::create([
                        'event_user_id' => $eventUser->id,
                        'special_code_id' => $specialCode->id,
                        'type' => $specialCodeType,
                    ]);
                } catch (\Exception $e) {
                    $errors[] = 'Error processing special code';
                }
            }
        }

        // Check if user is trying to catch themselves
        if ($fursuit) {
            if ($user->id === $fursuit->user_id) {
                if (! $specialCode) {
                    $errors[] = 'That badge is your own';
                    $wasSuccessful = false;
                }
            } else {
                // Check if already caught
                $alreadyCaught = UserCatch::where('event_user_id', $eventUser->id)
                    ->where('fursuit_id', $fursuit->id)
                    ->exists();

                $logEntry->already_caught = $alreadyCaught;

                if ($alreadyCaught) {
                    if (! $specialCode) {
                        $errors[] = 'You already caught them';
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
                ->with('success', 'Special code redeemed, and you caught them');
        } elseif ($specialCode) {
            // Only special code was successful
            return to_route('catch-em-all.catch')->with('success', 'Special code redeemed');
        } elseif ($fursuit) {
            // Only fursuit catch was successful
            return to_route('catch-em-all.catch')->with('caught_fursuit', $fursuit->id);
        }

        // This shouldn't happen, but just in case
        return to_route('catch-em-all.catch')->with('error', 'Something went wrong');
    }

    public function leaderboard()
    {
        // The board is always the current event: an attendee comparing themselves
        // against a convention they did not attend was a filter nobody asked for.
        $event = $this->getCurrentEvent();
        $user = Auth::user();

        return Inertia::render('CatchEmAll/Leaderboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'leaderboard' => $event ? $this->gameStatsService->getLeaderboard($event, 50, 10) : [],
        ]);
    }

    public function collection(Request $request)
    {

        $user = Auth::user();
        $defaultEvent = $this->getCurrentEvent();
        $selectedEventId = $request->get('event', $defaultEvent?->id ?? 'global');

        $result = [];
        $eventsWithEntries = $this->getEventsWithEntries();

        if ($selectedEventId == 'global') {
            $result = $this->gameStatsService->getUserCollectionForEvents($user, $eventsWithEntries->all(), true);

        } else {
            $eventUser = $user->eventUsers()->where('event_id', $selectedEventId)->first();

            $result = $eventUser
                ? $this->gameStatsService->getUserCollection($eventUser)
                : [
                    'suits' => [],
                    'species' => [],
                    'totalCatches' => 0,
                ];
        }

        $selectedEvent = $selectedEventId === 'global' ? null : Event::find($selectedEventId);

        return Inertia::render('CatchEmAll/Collection', [
            'collection' => $result,
            'eventsWithEntries' => $eventsWithEntries,
            'selectedEvent' => $selectedEvent?->id,
            'isGlobal' => $selectedEventId === 'global',
            'eventTotal' => $selectedEvent
                ? Fursuit::where('event_id', $selectedEvent->id)->where('catch_em_all', true)->count()
                : 0,
        ]);
    }

    public function achievements()
    {
        $user = Auth::user();
        $eventUser = $this->getEventUser($user, $this->getCurrentEvent());
        $achievements = AchievementFactory::getUserAchievementData($eventUser);
        $stats = AchievementFactory::getUserAchievementStats($eventUser);

        return Inertia::render('CatchEmAll/Achievements', [
            'achievements' => $achievements,
            'stats' => $stats,
            'caughtTotal' => $eventUser ? $eventUser->fursuitsCatched()->count() : 0,
        ]);
    }

    public function introduction()
    {
        return Inertia::render('CatchEmAll/Introduction');
    }

    public function profile()
    {
        $profile = Auth::user()->userProfile()->firstOrCreate([]);

        return to_route('catch-em-all.profiles.show', $profile);
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

        return redirect()->route('catch-em-all.catch')->with('success', 'Have a good hunt');
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

    /**
     * The last dozen catches, for the strip and the day list on the catch screen.
     */
    private function recentCatches(EventUser $eventUser, int $limit = 12): array
    {
        return UserCatch::where('event_user_id', $eventUser->id)
            ->with(['fursuit.species', 'fursuit.event', 'fursuit.user.userProfile'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function (UserCatch $catch) {
                $fursuit = $catch->fursuit;
                $ranking = $this->fursuitRanking->forFursuit($fursuit->event, $fursuit);
                $profile = $fursuit->user?->userProfile;

                return [
                    'id' => $catch->id,
                    'fursuitId' => $fursuit->id,
                    'name' => $fursuit->name,
                    'species' => $fursuit->species?->name,
                    'owner' => $fursuit->user?->name,
                    // a 3-across grid gets the thumbnail, not the gallery variant
                    'image' => $fursuit->image_thumb_url,
                    'caughtAt' => $catch->created_at?->format('H:i'),
                    'profileUuid' => $profile?->approved_at !== null ? $profile->uuid : null,
                    'ranking' => [
                        'level' => $ranking->value,
                        'label' => $ranking->getLabel(),
                        'color' => $ranking->getColor(),
                    ],
                ];
            })
            ->all();
    }

    /**
     * The catch sheet's picture falls back to the master photo: most fursuits at the event
     * never got a webp rendered, and the game screen must show the suit that was caught
     * rather than an empty box. The gallery keeps refusing the master on purpose.
     */
    private function getRecentCatchData($fursuitId)
    {
        $fursuit = Fursuit::with(['species', 'user', 'event'])->find($fursuitId);
        if (! $fursuit) {
            return null;
        }

        $ranking = $this->fursuitRanking->forFursuit($fursuit->event, $fursuit);

        return [
            'id' => $fursuit->id,
            'name' => $fursuit->name,
            'species' => $fursuit->species->name ?? 'Unknown',
            'user' => $fursuit->user->name ?? 'Anonymous',
            'image' => $fursuit->image_webp_url,
            'ranking' => [
                'level' => $ranking->value,
                'label' => $ranking->getLabel(),
                'color' => $ranking->getColor(),
                'icon' => $ranking->getIcon(),
            ],
            'caught' => $this->fursuitRanking->catches($fursuit->event, $fursuit->id),
            'speciesCount' => $this->speciesPopulation->population($fursuit->event, $fursuit->species_id),
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
            sprintf('collection_user_%d', $eventUser->user_id),
            "total_fursuiters_{$eventUser->event_id}", // TODO: Forget when new fursuit gets approved and not here
        ];

        $achievementKeys = AchievementRegister::getAllUserCachedKeys($eventUser);
        $keys = array_unique([...$keys, ...$achievementKeys]);

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
