<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Catch-Em-All Domain
    |--------------------------------------------------------------------------
    |
    | The domain where the Catch-Em-All game is hosted. This allows the game
    | to be served from a separate subdomain with its own authentication flow.
    |
    */

    'domain' => env('CATCH_DOMAIN', 'catch.localhost'),

    /*
    |--------------------------------------------------------------------------
    | Fursuit Catch Code Length
    |--------------------------------------------------------------------------
    |
    | Length of characters and digits the code of fursuits for the catch em all feature
    | Printed on the ordered fursuit badges
    |
    */

    'fursuit_catch_code_length' => env('FURSUIT_CATCH_CODE_LENGTH', 5),

    /*
    |--------------------------------------------------------------------------
    | Fursuit Catch Attempts per Minute
    |--------------------------------------------------------------------------
    |
    | Amount of times per 60 seconds a user can submit a Fursuit Catch Code
    | in attempt to catch a fursuiter. Will respond "429 Too Many Requests" if triggered.
    | Used to prevent bruteforce attempts.
    |
    */

    'fursuit_catch_attempts_per_minute' => env('FURSUIT_CATCH_ATTEMPTS_PER_MINUTE', 20),

    /*
    |--------------------------------------------------------------------------
    | Fursuit Ranking Threshold
    |--------------------------------------------------------------------------
    |
    | Fursuit is given a ranking based on the amount it got caught globally.
    | The thresholds for the rankings can be configured here.
    |
    | BRONZE - SILVER - GOLD - PLATINUM - DIAMOND
    */

    'fursuit_ranking_threshold_silver' => env('FURSUIT_RANKING_THRESHOLD_SILVER', 5),
    'fursuit_ranking_threshold_gold' => env('FURSUIT_RANKING_THRESHOLD_GOLD', 10),
    'fursuit_ranking_threshold_platinum' => env('FURSUIT_RANKING_THRESHOLD_PLATINUM', 20),
    'fursuit_ranking_threshold_diamond' => env('FURSUIT_RANKING_THRESHOLD_DIAMOND', 50),
    // If none of this applies, the fursuit is considered bronze
];
