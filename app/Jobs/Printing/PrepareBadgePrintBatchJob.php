<?php

namespace App\Jobs\Printing;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Services\BadgePrintQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Turn an opened print batch into a run that can print, away from the operator.
 *
 * This is the whole of what pressing "Print" costs the person who pressed it: the request
 * opens a Draft batch and dispatches this. Everything expensive - allocating `custom_id`,
 * pulling each attendee's upload off S3, decoding it, driving mpdf, writing the PDF back -
 * happens here.
 *
 * It used to happen in the request. A bulk print of badges nobody had rendered yet spent
 * seconds per card and died on PHP's 30 second limit partway through (Sentry c5c679fb),
 * leaving every badge in the selection sitting in Processing with no batch and no jobs -
 * a state that looks like "sent to the printer" and prints nothing.
 *
 * `tries = 1`. A run that fails is undone rather than retried: `BadgePrintQueue::prepare()`
 * puts the badges back and cancels the batch, and the operator presses the button again
 * having seen why. Retrying instead would mean a second attempt against a batch whose
 * badges were mid-move, which is the failure this job exists to remove.
 *
 * The timeout is generous because the work is bounded by the size of the selection, not by
 * anything a person is waiting on: a full page of unrendered badges is minutes of image
 * work. `failOnTimeout` matters more than the number - it is what gets `failed()` called,
 * and `failed()` is what puts the badges back when the worker is killed mid-render.
 *
 * The timeout alone is not enough, which is why this runs on `queue.long_running` rather
 * than the default connection. `retry_after` belongs to the connection and the shared one
 * is 90 seconds, so a run of a hundred cards became visible again long before it finished:
 * a second worker reserved it, saw `attempts` past `tries = 1`, and failed it with
 * "App\Jobs\Printing\PrepareBadgePrintBatchJob has been attempted too many times" - which
 * ran `failed()`, cancelled the batch and returned the badges while the first worker was
 * still rendering them. The long-running connection's `retry_after` sits above `$timeout`,
 * so nothing re-serves a run that is still going.
 */
class PrepareBadgePrintBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public $failOnTimeout = true;

    /**
     * @param  array<int, int>  $badgeIds
     * @param  bool  $autoName  rename the batch once the badges carry attendee ids
     */
    public function __construct(
        public readonly PrintBatch $batch,
        public readonly array $badgeIds,
        public readonly bool $autoName = true,
    ) {
        $this->onConnection(config('queue.long_running'));
        $this->onQueue('badge-render');
    }

    public function handle(): void
    {
        BadgePrintQueue::prepare($this->batch, $this->badgeIds, $this->autoName);
    }

    /**
     * Also reached when the worker is killed outright - timeout, memory limit, the pod
     * moving - which is the case `prepare()`'s own catch cannot cover.
     *
     * `abandon()` is safe to run twice and refuses to touch a batch that already holds
     * jobs, so a failure after a successful commit cannot cancel a real run.
     */
    public function failed(?Throwable $exception): void
    {
        // Null, not $this->badgeIds: what has to go back is what the run actually moved,
        // which prepare() records as it goes. A badge in the selection that was already
        // in Processing belongs to whatever put it there.
        BadgePrintQueue::abandon(
            $this->batch,
            null,
            $exception?->getMessage() ?? 'The print run could not be prepared.',
        );
    }
}
