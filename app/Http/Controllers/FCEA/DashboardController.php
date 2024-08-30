<?php

namespace App\Http\Controllers\FCEA;

use App\Enum\EventStateEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserCatchRequest;
use App\Models\FCEA\UserCatchLog;
use App\Models\FCEA\UserCatch;
use App\Models\FCEA\UserCatchUserRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $fursuit = UserCatchUserRanking::query()->with("user")->get();
        return Inertia::render('FCEA/Dashboard', [
            'tempVar1' => 1,
            'tempVar2' => 4,
            'tempVar3' => "trololol",
            'tempVar4' => $fursuit,
        ]);
    }
    public function catch(UserCatchRequest $request)
    {
        $event = \App\Models\Event::getActiveEvent();
        if (!$event)
            return "error"; // TODO

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
        //$this->RefreshUserRanking();
        return Inertia::render('FCEA/Dashboard');
    }

    public function RefreshUserRanking() {
        $catchersOrdered = User::query()
            ->withCount("fursuitsCatched")
            ->withMax("fursuitsCatched","created_at")
            ->orderByDesc("fursuits_catched_count")
            ->orderBy("fursuits_catched_max_created_at")
            ->get();

        // How many users do we have in totel (Users with 0 score are counted too)
        $maxCount = $catchersOrdered->count();

        // Save required informations for iteration
        $current = array(
            'count' => 1,
            'rank' => 1,
            'score' => $catchersOrdered->first()->fursuits_catched_count
        );

        // Need to have stats of previous Rank
        $previous = $current;

        // Clean Ranking
        UserCatchUserRanking::truncate();

        // Iterate all users to build Ranking
        foreach ($catchersOrdered as $catcher) {
            // Increase Rank when Score updates (players get same rank with same score)
            if ($current['score'] > $catcher->fursuits_catched_count) {
                $previous = $current;
                $current['rank']++;
                $current['score'] = $catcher->fursuits_catched_count;
            }

            $userRanking = new UserCatchUserRanking();
            $userRanking->user_id = $catcher->id;
            $userRanking->rank = $current['rank'];
            $userRanking->catches = $catcher->fursuits_catched_count;
            $userRanking->catches_till_next = $previous['score'] - $current['score'];
            $userRanking->users_behind = $maxCount - $previous['count'];
            $userRanking->newest_catch_at = $catcher->fursuits_catched_max_created_at;
            $userRanking->save();
            $current['count']++;
        }
    }

    // Small function to limit users interaction by id. By default 20 Catches per minute.
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
