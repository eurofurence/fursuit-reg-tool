<?php

namespace App\Http\Controllers\FCEA;

use App\Enum\EventStateEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserCatchRequest;
use App\Models\FCEA\UserCatchLog;
use App\Models\FCEA\UserCatch;
use App\Models\FCEA\UserCatchRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $myUserInfo = UserCatchRanking::getInfoOfUser(Auth::id());
        $userRanking = UserCatchRanking::queryUserRanking()->get();
        return Inertia::render('FCEA/Dashboard', [
            'myUserInfo' => $myUserInfo,
            'userRanking' => $userRanking,
        ]);
    }
    public function catch(UserCatchRequest $request)
    {
        $this->RefreshUserRanking();
        $this->RefreshFursuitRanking();
        $event = \App\Models\Event::getActiveEvent();
        if (!$event)
            return "No Active Event"; // TODO

        if ($second = $this->IsLimited(Auth::id()))
            return 'You may try again in '.$second.' seconds.';

        $catch_code = strtoupper($request->validated("catch_code"));
        $logEntry = new UserCatchLog();
        $logEntry->event_id = $event->id;
        $logEntry->user_id = Auth::id();
        $logEntry->catch_code = $catch_code;
        $logEntry->is_successful = false;
        $logEntry->already_caught = false;

        if (!$logEntry->FursuitExist())
        {
            $logEntry->save();
            return Inertia::render('FCEA/Dashboard');
        }

        $logEntry->already_caught =
            UserCatch::where('user_id', Auth::id())
                            ->where('fursuit_id', $logEntry->tryGetFursuit()->id)
                             ->exists(); // Entry exists

        if ($logEntry->already_caught)
        {
            $logEntry->save();
            return Inertia::render('FCEA/Dashboard');
        }

        $logEntry->is_successful = true;
        $logEntry->save();

        $userCatch = new UserCatch();
        $userCatch->event_id = $event->id;
        $userCatch->user_id = Auth::id();
        $userCatch->fursuit_id = $logEntry->tryGetFursuit()->id;
        $userCatch->save();
        return Inertia::render('FCEA/Dashboard');
    }

    // Function to build User Ranking. Truncated Table and iterates all users. Similar to the Fursuit Ranking
    public function RefreshUserRanking() {
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
    public function RefreshFursuitRanking() {
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
}
