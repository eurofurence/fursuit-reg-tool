<?php

namespace App\Http\Controllers\FCEA;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserCatchRequest;
use App\Models\FCEA\UserCatchLog;
use App\Models\FCEA\UserCatch;
use App\Models\FCEA\UserCatchRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use function Sodium\add;

class DashboardController extends Controller
{
    public function index()
    {
        $rankingSize = 10;

        // --------------------------------- Getting User Ranking Data ---------------------------------
        $myUserInfo = $this->getMyUserInfo();
        $userRanking = $this->getUserRanking($myUserInfo, $rankingSize);
        // --------------------------------- Getting Fursuit Ranking Data ---------------------------------
        $myFursuitInfos = $this->getMyFursuitInfos($myUserInfo);
        $fursuitRanking = $this->getFursuitRanking($myFursuitInfos, $rankingSize);

        $myFursuitInfoCatchedTotal = $myFursuitInfos->sum(function ($entry) { return $entry->score; });

        $caughtFursuit = null;

        if (session()->has('caught_fursuit'))
        {
            $caughtFursuitId = session()->get('caught_fursuit');
            $caughtFursuit = Fursuit::find($caughtFursuitId);
        }

        return Inertia::render('FCEA/Dashboard', [
            'myUserInfo' => [
                'id' => $myUserInfo->id,
                'rank' => $myUserInfo->rank,
                'score' => $myUserInfo->score,
                'score_till_next' => $myUserInfo->score_till_next,
                'others_behind' => $myUserInfo->others_behind,
            ],
            'userRanking' => $userRanking
                ->filter(fn($e) => $e->score > 0)
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'rank' => $entry->rank,
                    'score' => $entry->score,
                    'score_till_next' => $entry->score_till_next,
                    'others_behind' => $entry->others_behind,
                    'user' => [
                        'name' => $entry->user->name,
                    ],
                ];
            }),
            'myFursuitInfos' => $myFursuitInfos,
            'fursuitRanking' => $fursuitRanking->map(fn($entry) => [
                'name' => $entry->fursuit?->name,
            ]),
            'myFursuitInfoCatchedTotal' => $myFursuitInfoCatchedTotal, // How many times the user got catched on all fursuits summed up
            'caughtFursuit' => $caughtFursuit
        ]);
    }

    public function catch(UserCatchRequest $request)
    {
        $event = \App\Models\Event::getActiveEvent();
        if (!$event)
            return to_route('fcea.dashboard')->with('error', 'No Active Event');

        if ($second = $this->IsLimited(Auth::id()))
            return to_route('fcea.dashboard')->with('error', 'You may try again in '.$second.' seconds.');

        $catch_code = strtoupper($request->validated("catch_code"));
        $logEntry = new UserCatchLog();
        $logEntry->event_id = $event->id;
        $logEntry->user_id = Auth::id();
        $logEntry->catch_code = $catch_code;
        $logEntry->is_successful = false;
        $logEntry->already_caught = false;

        if (!$logEntry->fursuitExist())
        {
            $logEntry->save();
            return to_route('fcea.dashboard')->with('error', 'Invalid Code');
        }

        if (Auth::id() == $logEntry->tryGetFursuit()->user_id)
        {
            $logEntry->save();
            return to_route('fcea.dashboard')->with('error', "You can't catch yourself");
        }

        $logEntry->already_caught =
            UserCatch::where('user_id', Auth::id())
                            ->where('fursuit_id', $logEntry->tryGetFursuit()->id)
                             ->exists(); // Entry exists

        if ($logEntry->already_caught)
        {
            $logEntry->save();
            return to_route('fcea.dashboard')->with('error', "Fursuit already caught"); // TODO: separate UI for this case
        }

        $logEntry->is_successful = true;
        $logEntry->save();

        $userCatch = new UserCatch();
        $userCatch->event_id = $event->id;
        $userCatch->user_id = Auth::id();
        $userCatch->fursuit_id = $logEntry->tryGetFursuit()->id;
        $userCatch->save();
        self::refreshRanking();

        return to_route('fcea.dashboard')->with('caught_fursuit', $logEntry->tryGetFursuit()->id);
    }

    public static function refreshRanking() {
        self::refreshUserRanking();
        self::refreshFursuitRanking();
    }

    // Function to build User Ranking. Truncated Table and iterates all users. Similar to the Fursuit Ranking
    public static function refreshUserRanking() {
        $usersOrdered = User::query()
            ->withCount("fursuitsCatched")
            ->withMax("fursuitsCatched","created_at")
            ->orderByDesc("fursuits_catched_count")
            ->orderBy("fursuits_catched_max_created_at")
            ->get();

        // How many users do we have in total (Users with 0 score are counted too)
        $maxCount = $usersOrdered->count();

        // Save required information for iteration
        $current = array(
            'count' => 1,
            'rank' => 1,
            'score' => $usersOrdered->first()->fursuits_catched_count
        );

        // Need to have stats of previous Rank
        $previous = $current;

        DB::beginTransaction();

        // Clean Ranking
        UserCatchRanking::deleteUserRanking();

        // Iterate all users to build Ranking
        foreach ($usersOrdered as $user) {
            // Increase Rank when Score updates (players get same rank with same score)
            if ($current['score'] > $user->fursuits_catched_count) {
                $previous = $current;
                $current['rank']++;
                $current['score'] = $user->fursuits_catched_count;
            }

            $userRanking = new UserCatchRanking();
            $userRanking->id = $current['count'];
            $userRanking->user_id = $user->id;
            $userRanking->rank = $current['rank'];
            $userRanking->score = $user->fursuits_catched_count;
            $userRanking->score_till_next = $previous['score'] - $current['score'];
            $userRanking->others_behind = $maxCount - $previous['count'];
            $userRanking->score_reached_at = $user->fursuits_catched_max_created_at;
            $userRanking->save();
            $current['count']++;
        }

        DB::commit();
    }

    // Function to build Fursuit Ranking. Truncated Table and iterates all fursuits. Similar to the User Ranking
    public static function refreshFursuitRanking() {
        $fursuitsOrdered = Fursuit::query()
            ->withCount("catchedByUsers")
            ->withMax("catchedByUsers","created_at")
            ->orderByDesc("catched_by_users_count")
            ->orderBy("catched_by_users_max_created_at")
            ->get();

        // How many users do we have in total (Users with 0 score are counted too)
        $maxCount = $fursuitsOrdered->count();

        // Save required information for iteration
        $current = array(
            'count' => 1,
            'rank' => 1,
            'score' => $fursuitsOrdered->first()->catched_by_users_count
        );

        // Need to have stats of previous Rank
        $previous = $current;

        DB::beginTransaction();

        // Clean Ranking
        UserCatchRanking::deleteFursuitRanking();

        // Iterate all fursuits to build Ranking
        foreach ($fursuitsOrdered as $fursuit) {
            // Increase Rank when Score updates (players get same rank with same score)
            if ($current['score'] > $fursuit->catched_by_users_count) {
                $previous = $current;
                $current['rank']++;
                $current['score'] = $fursuit->catched_by_users_count;
            }

            $fursuitRanking = new UserCatchRanking();
            $fursuitRanking->id = $current['count'];
            $fursuitRanking->fursuit_id = $fursuit->id;
            $fursuitRanking->rank = $current['rank'];
            $fursuitRanking->score = $fursuit->catched_by_users_count;
            $fursuitRanking->score_till_next = $previous['score'] - $current['score'];
            $fursuitRanking->others_behind = $maxCount - $previous['count'];
            $fursuitRanking->score_reached_at = $fursuit->catched_by_users_max_created_at;
            $fursuitRanking->save();
            $current['count']++;
        }

        DB::commit();
    }

    // Small function to limit users interaction by id. By default, 20 Catches per minute.
    // User ID and Action can be modified on call, Limit per minute is set on config
    // If successful, return is 0 otherwise return is the remaining seconds until next attempt is allowed.
    protected function IsLimited(int $identifier, string $action = 'fursuit_catch') : int
    {
        $rateLimiterKey = $action.':'.$identifier;
        if (RateLimiter::tooManyAttempts($rateLimiterKey, config("fcea.fursuit_catch_attempts_per_minute")))
            return RateLimiter::availableIn($rateLimiterKey);

        RateLimiter::increment($rateLimiterKey);
        return 0;
    }

    private function getMyUserInfo() : UserCatchRanking
    {
        $myUserInfo = UserCatchRanking::getInfoOfUser(Auth::id()); // Getting own Rank, may be null if user is new

        if (!$myUserInfo) { // User not in ranking
            $this->refreshRanking();  // refresh it.
            $myUserInfo = UserCatchRanking::getInfoOfUser(Auth::id()); // should not be new anymore
        }

        return $myUserInfo;
    }

    private function getUserRanking(UserCatchRanking $myUserInfo, int $rankingSize)
    {
        $topRanking = UserCatchRanking::queryUserRanking()
            ->whereBetween('id', [1, $rankingSize]) // Top X Ranking
            ->orderBy('id') // already ordered by rank/score_reached_at
            ->limit($rankingSize);

        $ownIdRange = [$myUserInfo->id - ($rankingSize / 2), $myUserInfo->id + ($rankingSize / 2)];

        $ranking = UserCatchRanking::queryUserRanking()
            ->whereBetween('id',  $ownIdRange) // Ranking around own position - Be aware that you need to add separator to the ranking frontend if there is a jump in the ranking
            ->orderBy('id')  // already ordered by rank/score_reached_at
            ->limit($rankingSize)
            ->union($topRanking)
            ->distinct() // remove duplicates
            ->orderBy('id'); // Last time order to merge union select

        // Add Separators when its jumping
        return $this->AddPlaceholderOnJump($ranking->get());
    }

    private function getMyFursuitInfos(UserCatchRanking $myUserInfo) : Collection
    {
        $myFursuitIDs = $myUserInfo->user->fursuits->pluck('id')->toArray();  // Get all own Fursuit IDs
        return UserCatchRanking::getInfoOfFursuits($myFursuitIDs); // Get Ranking info of my fursuits
    }

    private function getFursuitRanking(Collection $myFursuitInfos, int $rankingSize) : Collection
    {
        $topRanking = UserCatchRanking::queryFursuitRanking()
            ->whereBetween('id', [1, $rankingSize]) // Top X Ranking
            ->orderBy('id') // already ordered by rank/score_reached_at
            ->limit($rankingSize);

        $ranking = $topRanking;

        foreach ($myFursuitInfos as $myFursuitInfo) {
            $ownIdRange = [$myFursuitInfo->id - ($rankingSize / 2), $myFursuitInfo->id + ($rankingSize / 2)];

            $ranking = UserCatchRanking::queryFursuitRanking()
                ->whereBetween('id', $ownIdRange) // Ranking around own position - Be aware that you need to add separator to the ranking frontend if there is a jump in the ranking
                ->orderBy('id')  // already ordered by rank/score_reached_at
                ->limit($rankingSize)
                ->union($ranking)
                ->distinct() // remove duplicates
                ->orderBy('id'); // Last time order to merge union select
        }

        // Add Separators when its jumping
        return $this->AddPlaceholderOnJump($ranking->get());
    }

    private function AddPlaceholderOnJump(Collection $userRanking) : Collection
    {
        // No need to add separators if there is no data
        if ($userRanking->isEmpty()) {
            return $userRanking;
        }

        $lastID = $userRanking->first()->id;
        // Iterate manually to find jumps in id (every id is used at least once) always starting with 1
        foreach ($userRanking as $ranking) {
            // Check if we incremented by more than one (jump)
            if ($lastID + 1 < $ranking->id) {
                $newItem = new UserCatchRanking();
                $newItem->id = $lastID + 1; // Use this rank for correct sorting
                $newItem->user = new User(); // Consider keeping user/fursuit null for performance
                $newItem->user->name = "...";
                $newItem->fursuit = new Fursuit();
                $newItem->fursuit->name = "...";
                $newItem->fursuit->image="filler"; // Crashes if this is null
                $userRanking->add($newItem); // Adding a fake item to indicate separators
            }

            $lastID = $ranking->id;
        }

        // Should not change the order as it was ordered by id to begin with
        return $userRanking->sortBy('id')->values();
    }
}
