<?php

\Illuminate\Support\Facades\Schedule::command('refresh:tokens')->daily();

// FCEA Rankings - refresh every 15 minutes during convention hours
\Illuminate\Support\Facades\Schedule::command('fcea:refresh-rankings')
    ->everyFifteenMinutes()
    ->between('08:00', '23:00')
    ->withoutOverlapping()
    ->runInBackground();
