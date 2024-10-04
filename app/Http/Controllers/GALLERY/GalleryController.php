<?php

namespace App\Http\Controllers\GALLERY;

use App\Http\Controllers\Controller;
use App\Models\FCEA\UserCatch;
use App\Models\FCEA\UserCatchRanking;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Database\Factories\Fursuit\FursuitFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class GalleryController extends Controller
{

    const IMAGES_PER_SITE = 3 * 3; // 10 rows with 3 images each // TODO: Reverse to 10 * 3

    public function index(int $site, Request $request)
    {

        $searchTerm = $request->input('s') ?? "";
        $search = collect(explode(' ', $searchTerm))->map(function ($term) {
            return '%' . trim($term) . '%';
        })->toArray();

        if ($site < 1) {
            return redirect()->route('gallery.site', ['site' => 1, 's' => $searchTerm]);
        }

        // Get the number of images
        $imageCount = Fursuit::query()
            ->where('status', "approved")
            ->where('published', true);
        foreach ($search as $term) {
            $imageCount = $imageCount->where('name', 'LIKE', $term);
        }
        $imageCount = $imageCount->count();

        $MAX_SITE = ceil($imageCount / self::IMAGES_PER_SITE);

        if ($site > $MAX_SITE && $imageCount > 0) {
            return redirect()->route('gallery.site', ['site' => $MAX_SITE, 's' => $searchTerm]);
        }

        $fursuits = Fursuit::query()
            ->with('species')
            ->where('status', "approved")
            ->where('published', true)
            ->where('name', 'LIKE', $search);
        foreach ($search as $term) {
            $fursuits = $fursuits->where('name', 'LIKE', $term);
        }
        $fursuits = $fursuits->withCount('catchedByUsers')
            ->orderBy('catched_by_users_count', 'desc')
            ->orderBy('name', 'asc')
            ->offset(($site - 1) * self::IMAGES_PER_SITE)
            ->limit(self::IMAGES_PER_SITE)
            ->get();

        $topRankings = UserCatchRanking::query()
            ->whereNotNull('user_id')
            ->orderBy('rank', 'desc')
            ->limit(3)
            ->with('user')
            ->get();

        return Inertia::render('Gallery/GalleryIndex', [
            'fursuit' => $fursuits
                ->map(function ($fursuit) {
                    return [
                        'id' => $fursuit->id,
                        'name' => $fursuit->name,
                        'species' => $fursuit->species->name,
                        'image' => $fursuit->image_url,
                        'scoring' => $fursuit->catched_by_users_count,
                    ];
                }),
            'site' => $site,
            'maxSite' => $MAX_SITE,
            'ranking' => $topRankings
                ->map(function ($ranking) {
                    return [
                        'user' => $ranking->user->name,
                        'rank' => $ranking->rank,
                        'catches' => $ranking->score,
                    ];
                }),
            'suiteAmount' => $imageCount,
            'search' => $searchTerm,
        ]);
    }

    public function getTotalFursuitCount(Request $request) {
        $count = Fursuit::query()
            ->where('status', "approved")
            ->where('published', true)
            ->count();
        return response()->json(['count' => $count]);
    }



}
