<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Support\Manage\EventScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Placeholder dashboard.
     *
     * The four stats, the doughnut and the bar chart are phase 9. They land as
     * top-level props so the page can poll `stats` on its own interval without
     * re-running everything else on the screen.
     */
    public function index(Request $request): Response
    {
        return inertia('Manage/Dashboard', [
            'stats' => fn () => [],
            'charts' => fn () => ['fulfillment' => null, 'events' => null],
            'placeholder' => true,
        ]);
    }

    /**
     * Write the global event selection.
     *
     * A missing or null event_id means all events, which is a real state here rather
     * than the unreachable branch it was under FilamentEventSelector. An unknown id is
     * a validation error, so the session can never hold an id nothing matches.
     *
     * Lives on the dashboard controller only because phase 0 owns no other non-module
     * controller; move it to its own controller when a later phase adds one.
     */
    public function selectEvent(Request $request, EventScope $scope): RedirectResponse
    {
        $validated = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        $scope->select(isset($validated['event_id']) ? (int) $validated['event_id'] : null);

        return back();
    }
}
