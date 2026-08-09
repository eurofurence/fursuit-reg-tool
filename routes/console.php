<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('refresh:tokens')->daily();

// FCEA Rankings - refresh every 15 minutes during convention hours
Schedule::command('fcea:refresh-rankings')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Check for stuck print jobs every 3 minutes
Schedule::command('printing:check-stuck-jobs')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Return print jobs abandoned by a dead agent to the queue. Runs often because
// a job sitting on an expired lease is a card nobody is printing.
Schedule::command('printing:reap-leases')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Send the review notifications whose undo window has passed. Every minute, because the
// window is five minutes and an attendee should hear about a verdict promptly once the
// reviewer can no longer take it back.
Schedule::command('fursuits:deliver-review-decisions')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
