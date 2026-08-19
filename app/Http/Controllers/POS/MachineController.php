<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\SumUpReader;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MachineController extends Controller
{
    /**
     * The card readers this desk can pick from.
     *
     * Fetched when the dialog opens rather than shared on every POS response:
     * the list changes about once a convention, and the header only needs the
     * name of the one that is already selected.
     *
     * Each row carries the other desks currently pointing at it. Readers are a
     * physical box on a counter, and two tills sending checkouts to the same one
     * is a queue of prompts on a terminal nobody is standing at - worth seeing
     * before picking, but not worth forbidding: swapping a broken terminal for
     * the neighbour's is exactly the move this dialog exists for.
     */
    public function sumUpReaders(Request $request)
    {
        $machine = $request->user('machine');

        $inUse = Machine::query()
            ->whereNotNull('sumup_reader_id')
            ->whereKeyNot($machine->id)
            ->whereNull('archived_at')
            ->get(['id', 'name', 'sumup_reader_id'])
            ->groupBy('sumup_reader_id');

        return response()->json([
            'current_id' => $machine->sumup_reader_id,
            'readers' => SumUpReader::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SumUpReader $reader) => [
                    'id' => $reader->id,
                    'name' => $reader->name,
                    'in_use_by' => $inUse->get($reader->id)?->pluck('name')->values() ?? [],
                ]),
        ]);
    }

    /**
     * Point this till at a different card reader.
     *
     * Scoped to the machine holding the session rather than an id in the URL:
     * a clerk chooses the terminal on their own counter, and nothing about this
     * screen is a reason to be able to re-point somebody else's desk.
     */
    public function updateSumUpReader(Request $request)
    {
        $machine = $request->user('machine');

        $validated = $request->validate([
            'sumup_reader_id' => ['nullable', 'integer', Rule::exists('sumup_readers', 'id')],
        ], [
            'sumup_reader_id.exists' => 'That card reader no longer exists.',
        ]);

        $machine->update([
            'sumup_reader_id' => $validated['sumup_reader_id'] ?? null,
        ]);

        return back()->with('success', $machine->sumup_reader_id
            ? 'Card reader updated.'
            : 'Card payments switched off for this till.');
    }

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
