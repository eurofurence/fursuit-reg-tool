<?php

namespace App\Http\Controllers\FCEA;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserCatchRequest;
use App\Models\FCEA\UserCatchLog;
use App\Models\FCEA\UserFursuitCatch;
use App\Models\FCEA\UserFursuitCatchesUserRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        return Inertia::render('FCEA/Dashboard');
    }
    public function catch(UserCatchRequest $request)
    {
        $catch_code = $request->validated("catch_code");
        $logEntry = new UserCatchLog();
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
            UserFursuitCatch::where('user_id', Auth::id())
                            ->where('fursuit_id', $logEntry->tryGetFursuit()->id)
                             ->exists(); // Entry exists

        if ($logEntry->already_caught)
        {
            $logEntry->save();
            return Inertia::render('FCEA/Dashboard');
        }

        $logEntry->is_successful = true;
        $logEntry->save();

        $UserFursuitCatchEntry = new UserFursuitCatch();
        $UserFursuitCatchEntry->user_id = Auth::id();
        $UserFursuitCatchEntry->fursuit_id = $logEntry->tryGetFursuit()->id;
        $UserFursuitCatchEntry->save();
        $this->RefreshUserRanking();
        return Inertia::render('FCEA/Dashboard');
    }

    public function RefreshUserRanking() {
        $catchersOrdered = User::query()
            ->withCount("fursuitsCatched")
            ->orderByDesc("fursuits_catched_count")
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
        UserFursuitCatchesUserRanking::truncate();

        // Iterate all users to build Ranking (players with same score have same ranking)
        foreach ($catchersOrdered as $catcher) {
            // Increase Rank when Score updates (players get same rank with same score)
            if ($current['score'] > $catcher->fursuits_catched_count) {
                $previous = $current;
                $current['rank']++;
                $current['score'] = $catcher->fursuits_catched_count;
            }

            $rankingEntry = new UserFursuitCatchesUserRanking();
            $rankingEntry->user_id = $catcher->id;
            $rankingEntry->rank = $current['rank'];
            $rankingEntry->catches = $catcher->fursuits_catched_count;
            $rankingEntry->catches_till_next = $previous['score'] - $current['score'];
            $rankingEntry->users_behind = $maxCount - $previous['count'];
            $rankingEntry->save();
            $current['count']++;
        }
    }
}
