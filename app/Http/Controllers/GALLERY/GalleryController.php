<?php

namespace App\Http\Controllers\GALLERY;

use App\Http\Controllers\Controller;
use App\Models\FCEA\UserCatch;
use App\Models\Fursuit\Fursuit;
use Database\Factories\Fursuit\FursuitFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class GalleryController extends Controller
{

    const IMAGES_PER_SITE = 3 * 3; // 10 rows with 3 images each // TODO: Reverse to 10 * 3

    public function index(int $site, Request $request)
    {
        if ($site < 1) {
            return redirect()->route('gallery.site', ['site' => 1]);
        }

        // Get the number of images
        $imageCount = Fursuit::query()
            ->where('status', "approved")
            ->where('published', true)
            ->count();

        $MAX_SITE = ceil($imageCount / self::IMAGES_PER_SITE);

        if ($site > $MAX_SITE) {
            return redirect()->route('gallery.site', ['site' => $MAX_SITE]);
        }

        $fursuits = Fursuit::query()
            ->with('species')
            ->where('status', "approved")
            ->where('published', true)
            ->withCount('catchedByUsers')
            ->orderBy('catched_by_users_count', 'desc')
            ->orderBy('name', 'asc')
            ->offset(($site - 1) * self::IMAGES_PER_SITE)
            ->limit(self::IMAGES_PER_SITE)
            ->get();

        if ($fursuits->isEmpty()) {
            return redirect()->route('gallery.site', ['site' => 1]);
        }


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
        ]);
    }

}
