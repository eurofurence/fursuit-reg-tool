<?php

namespace App\Http\Controllers\PrintAgent;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrintJobTypeEnum;
use App\Events\PrinterStatusUpdated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent handshake, printer registration and hardware condition reporting.
 */
class AgentSessionController extends AgentController
{
    /**
     * What the agent needs on startup to know who it is and what it drives.
     */
    public function config(Request $request): JsonResponse
    {
        $this->touchAgent($request);

        $machine = $this->machine();

        return response()->json([
            'machine' => [
                'id' => $machine->id,
                'name' => $machine->name,
            ],
            'printers' => $machine->printers()->get()->map(fn (Printer $printer) => [
                'id' => $printer->id,
                'name' => $printer->name,
                'type' => $printer->type?->value,
                'is_active' => (bool) $printer->is_active,
                'condition' => $printer->condition,
            ]),
            'lease_seconds' => 180,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Register the Windows printers the operator mapped in the agent UI.
     *
     * Only what the operator explicitly assigned a role to. The QZ integration
     * this replaces registered every printer the OS reported, so machines ended
     * up with "Microsoft Print to PDF" and fax drivers sitting in the printer
     * list as receipt printers.
     */
    public function registerPrinters(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printers' => 'required|array|max:20',
            'printers.*.name' => 'required|string|max:255',
            'printers.*.role' => ['required', 'string', 'in:badge,receipt'],
            'printers.*.paper_sizes' => 'nullable|array',
            'printers.*.default_paper_size' => 'nullable|string|max:100',
        ]);

        $this->touchAgent($request);

        $printers = collect($data['printers'])->map(function (array $printer) {
            $record = $this->machine()->printers()->firstOrNew(['name' => $printer['name']]);

            $record->fill([
                'type' => PrintJobTypeEnum::from($printer['role']),
                'paper_sizes' => $printer['paper_sizes'] ?? $record->paper_sizes ?? [],
                'default_paper_size' => $printer['default_paper_size'] ?? $record->default_paper_size,
                'is_active' => true,
            ])->save();

            return ['id' => $record->id, 'name' => $record->name, 'type' => $record->type?->value];
        });

        return response()->json(['printers' => $printers]);
    }

    /**
     * Physical printer condition, read over SNMP by the agent.
     *
     * Laravel cannot see the printer: it is on a private LAN behind the agent.
     * This is how the POS learns that a printer is jammed, out of ribbon or out
     * of cards so it can tell staff to go and fix it.
     */
    public function reportCondition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printer_name' => 'required|string',
            'condition' => 'required|string',
            'message' => 'nullable|string|max:1000',
            'cards_remaining' => 'nullable|integer|min:0',
            'cards_capacity' => 'nullable|integer|min:0',
            'raw' => 'nullable|array',
        ]);

        $this->touchAgent($request);

        $printer = $this->printerForMachine($data['printer_name']);

        // An unrecognised reading is recorded as Unknown, which counts as a stop.
        // Guessing that silence means everything is fine is what let the old
        // system report jammed cards as printed.
        $condition = PrinterConditionEnum::tryFrom($data['condition']) ?? PrinterConditionEnum::Unknown;

        $printer->forceFill([
            'condition' => $condition->value,
            'condition_message' => $data['message'] ?? $condition->remedy(),
            'condition_reported_at' => now(),
            'cards_remaining' => $data['cards_remaining'] ?? $printer->cards_remaining,
            'cards_capacity' => $data['cards_capacity'] ?? $printer->cards_capacity,
            'condition_raw' => $data['raw'] ?? null,
        ])->save();

        // Push it to the POS. Writing the columns alone left every screen
        // showing whatever the printer was doing when the page was loaded, so
        // a jam mid-session looked exactly like a healthy printer.
        broadcast(PrinterStatusUpdated::fromCondition(
            $printer->name,
            $printer->type?->value === 'receipt' ? 'receipt' : 'badge',
            $condition,
            $data['message'] ?? null,
        ));

        return response()->json([
            'condition' => $condition->value,
            'is_stop' => $condition->isStop(),
            'label' => $condition->label(),
        ]);
    }
}
