<?php

\Illuminate\Support\Facades\Schedule::command('refresh:tokens')->daily();

// FCEA Rankings - refresh every 15 minutes during convention hours
\Illuminate\Support\Facades\Schedule::command('fcea:refresh-rankings')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Check for stuck print jobs every 3 minutes
\Illuminate\Support\Facades\Schedule::command('printing:check-stuck-jobs')
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->runInBackground();
