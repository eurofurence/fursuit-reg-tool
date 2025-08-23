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
            case 'printed':
                $query->whereIn('status_fulfillment', ['printed', 'ready_for_pickup', 'picked_up']);
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

        return Inertia::render('POS/Badges/Index', [
            'badges' => $badges,
            'currentEvent' => $currentEvent,
            'printers' => $printers,
            'filters' => [
                'tab' => $tab,
            ],
        ]);
    }
}
