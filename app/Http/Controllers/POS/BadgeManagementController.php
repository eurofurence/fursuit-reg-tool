<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BadgeManagementController extends Controller
{
    public function index(Request $request)
    {
        $currentEvent = Event::latest('starts_at')->first();

        if (! $currentEvent) {
            return redirect()->route('pos.dashboard')->with('error', 'No current event found');
        }

        // Get filter parameters from URL
        $tab = $request->get('tab', 'unprinted');
        $page = $request->get('page', 1);

        // Base query
        $query = Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        });

        // Apply filtering based on tab
        switch ($tab) {
            case 'unprinted':
                $query->where('status_fulfillment', 'pending');
                break;
            case 'processing':
                $query->where('status_fulfillment', 'processing');
                break;
            case 'printed':
                $query->whereIn('status_fulfillment', ['ready_for_pickup', 'picked_up']);
                break;
            case 'all':
            default:
                // No additional filtering needed
                break;
        }

        $badges = $query->join('fursuits', 'badges.fursuit_id', '=', 'fursuits.id')
            ->join('users', 'fursuits.user_id', '=', 'users.id')
            ->join('species', 'fursuits.species_id', '=', 'species.id')
            ->select(
                'badges.id',
                'badges.custom_id',
                'badges.status_payment',
                'badges.status_fulfillment',
                'badges.total',
                'badges.printed_at',
                'fursuits.name as fursuit_name',
                'users.name as owner_name',
                'species.name as species_name'
            )
            ->orderBy('badges.custom_id')
            ->paginate(50)
            ->appends($request->query());

        // Get available badge printers
        $printers = Printer::where('type', PrintJobTypeEnum::Badge)
            ->where('is_active', true)
            ->select('id', 'name')
            ->get();

        // Get counts for each tab
        $baseQuery = Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        });

        $tabCounts = [
            'unprinted' => (clone $baseQuery)->where('status_fulfillment', 'pending')->count(),
            'processing' => (clone $baseQuery)->where('status_fulfillment', 'processing')->count(),
            'printed' => (clone $baseQuery)->whereIn('status_fulfillment', ['ready_for_pickup', 'picked_up'])->count(),
            'all' => (clone $baseQuery)->count(),
        ];

        return Inertia::render('POS/Badges/Index', [
            'badges' => $badges,
            'currentEvent' => $currentEvent,
            'printers' => $printers,
            'tabCounts' => $tabCounts,
            'filters' => [
                'tab' => $tab,
            ],
        ]);
    }
}
