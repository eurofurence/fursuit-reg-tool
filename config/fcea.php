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
    | Fursuit Ranking Thresholds
    |--------------------------------------------------------------------------
    |
    | A fursuit's ranking comes from how many people have caught it: caught more
    | means a higher ranking, Novice through Legend. It is a measure of how
    | sought after a suiter is, not of how rare their species is, which is why
    | the tiers use the event's own progression names.
    |
    | Everyone starts at Novice on the first morning and climbs from there.
    |
    */

    'fursuit_ranking_threshold_regular' => env('FURSUIT_RANKING_THRESHOLD_REGULAR', 5),
    'fursuit_ranking_threshold_fluffy' => env('FURSUIT_RANKING_THRESHOLD_FLUFFY', 10),
    'fursuit_ranking_threshold_extraordinaire' => env('FURSUIT_RANKING_THRESHOLD_EXTRAORDINAIRE', 20),
    'fursuit_ranking_threshold_legend' => env('FURSUIT_RANKING_THRESHOLD_LEGEND', 50),

    /*
    |--------------------------------------------------------------------------
    | Species Population
    |--------------------------------------------------------------------------
    |
    | Not a tier any more, but still worth showing: how many fursuits of a
    | species are registered for the event. SpeciesRarityService reads this to
    | tell an attendee that the Kugsha Dog they just caught is the only one at
    | the convention.
    |
    */

];
