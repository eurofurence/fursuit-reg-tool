<?php

namespace App\Http\Controllers\PrintAgent;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for the print agent API.
 *
 * Every lookup here is scoped to the calling machine. The endpoints this
 * replaces bound {job} straight off the URL with no ownership check at all, so
 * any authenticated machine could mutate any other machine's print jobs.
 */
abstract class AgentController extends Controller
{
    protected function machine(): Machine
    {
        /** @var Machine $machine */
        $machine = auth('sanctum')->user();

        return $machine;
    }

    /**
     * Record that we heard from the agent. This is the only liveness signal we
     * get, since the agent lives on a private network and calls out to us.
     */
    protected function touchAgent(Request $request): void
    {
        $this->machine()->forceFill([
            'agent_last_seen_at' => now(),
            'agent_version' => $request->header('X-Agent-Version') ?? $this->machine()->agent_version,
        ])->save();
    }

    /**
     * A print job on one of this machine's printers, or a 404.
     */
    protected function jobForMachine(int $jobId): PrintJob
    {
        $job = PrintJob::query()
            ->whereKey($jobId)
            ->whereHas('printer', fn ($query) => $query->where('machine_id', $this->machine()->id))
            ->with(['printer', 'batch', 'printable'])
            ->first();

        if (! $job) {
            throw new NotFoundHttpException('Print job not found for this machine.');
        }

        return $job;
    }

    /**
     * A batch this machine may work on: either already assigned to one of its
     * printers, or not yet assigned to anyone.
     */
    protected function batchForMachine(int $batchId): PrintBatch
    {
        $batch = PrintBatch::query()
            ->whereKey($batchId)
            ->where(function ($query) {
                $query->whereNull('printer_id')
                    ->orWhereHas('printer', fn ($q) => $q->where('machine_id', $this->machine()->id));
            })
            ->first();

        if (! $batch) {
            throw new NotFoundHttpException('Print batch not available to this machine.');
        }

        return $batch;
    }

    protected function printerForMachine(string $name): Printer
    {
        $printer = $this->machine()->printers()->where('name', $name)->first();

        if (! $printer) {
            throw new NotFoundHttpException("Printer '{$name}' is not registered to this machine.");
        }

        return $printer;
    }
}
