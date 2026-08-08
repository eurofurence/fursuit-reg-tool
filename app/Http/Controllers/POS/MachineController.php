<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MachineController extends Controller
{
    public function updateTimeout(Request $request, Machine $machine)
    {
        $request->validate([
            'auto_logout_timeout' => [
                'nullable',
                'integer',
                'min:30',
                'max:1800',
                Rule::in([30, 60, 120, 180, 300, 900, 1800, null]),
            ],
        ]);

        $machine->update([
            'auto_logout_timeout' => $request->auto_logout_timeout,
        ]);

        return back()->with('success', 'Auto logout timeout updated successfully.');
    }

    /**
     * Which attendee-ID crate this desk holds.
     *
     * Empty fields are stored as null rather than 0, because "no lower bound"
     * and "from attendee 0" have to stay distinguishable: null on both ends is
     * what makes the desk count every badge again.
     */
    public function updateBadgeRange(Request $request, Machine $machine)
    {
        $validated = $request->validate([
            'badge_range_min' => ['nullable', 'integer', 'min:0'],
            'badge_range_max' => ['nullable', 'integer', 'min:0', 'gte:badge_range_min'],
        ], [
            'badge_range_max.gte' => 'The last badge ID must not be lower than the first one.',
        ]);

        $machine->update([
            'badge_range_min' => $validated['badge_range_min'] ?? null,
            'badge_range_max' => $validated['badge_range_max'] ?? null,
        ]);

        return back()->with('success', 'Badge range updated.');
    }
}
