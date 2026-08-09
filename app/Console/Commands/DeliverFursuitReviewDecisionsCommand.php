<?php

namespace App\Console\Commands;

use App\Jobs\DeliverFursuitReviewDecisionJob;
use App\Models\Fursuit\FursuitReviewDecision;
use Illuminate\Console\Command;

/**
 * Tell attendees about review verdicts whose undo window has run out.
 *
 * The undo window is a column (`notify_at`), not a queue delay, so it cannot be undone by
 * a driver: `sync` ignores delays, which would send the mail inside the reviewer's own
 * request and remove the only thing that makes a mis-click recoverable. Scheduled every
 * minute in routes/console.php, so the attendee hears within a minute of the window
 * closing.
 *
 * The job does the deciding - undone, superseded, or the record has moved on since - so
 * this only picks the rows that are due.
 */
class DeliverFursuitReviewDecisionsCommand extends Command
{
    protected $signature = 'fursuits:deliver-review-decisions';

    protected $description = 'Send the review notifications whose undo window has passed';

    public function handle(): int
    {
        $due = FursuitReviewDecision::query()
            ->pendingNotification()
            ->where('notify_at', '<=', now())
            ->pluck('id');

        foreach ($due as $id) {
            DeliverFursuitReviewDecisionJob::dispatch($id);
        }

        $this->info($due->count().' review notification(s) dispatched.');

        return self::SUCCESS;
    }
}
