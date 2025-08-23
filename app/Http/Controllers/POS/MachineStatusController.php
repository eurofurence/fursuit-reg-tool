<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MachineStatusController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $machine = $request->attributes->get('machine');

        if (!$machine) {
            return response()->json([
                'qz_status' => 'disconnected',
                'is_connected' => false,
                'pending_jobs' => 0,
            ]);
        }

        return response()->json([
            'qz_status' => $machine->qz_connection_status->value,
            'is_connected' => $machine->isQzConnected(),
            'pending_jobs' => $machine->getPendingPrintJobsCount(),
            'last_seen' => $machine->qz_last_seen_at?->toISOString(),
        ]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:connected,disconnected,error',
        ]);

        $machine = auth('machine')->user();

        if (!$machine) {
            return response()->json(['error' => 'Machine not found'], 404);
        }

        $machine->updateQzStatus(
            \App\Enum\QzConnectionStatusEnum::from($request->input('status'))
        );

        return response()->json([
            'success' => true,
            'qz_status' => $machine->qz_connection_status->value,
            'is_connected' => $machine->isQzConnected(),
        ]);
    }
}
