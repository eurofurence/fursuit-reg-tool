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
    | Fursuit Species Rarity Threshold
    |--------------------------------------------------------------------------
    |
    | Fursuit Species is given a rarity based on the amount it appears among all fursuiter
    | If a species appear more often than the threshold, it is considered this rarity
    |
    */

    'species_rarity_threshold_uncommon' => env('SPECIES_RARITY_THRESHOLD_UNCOMMON', 5),
    'species_rarity_threshold_rare' => env('SPECIES_RARITY_THRESHOLD_RARE', 10),
    'species_rarity_threshold_epic' => env('SPECIES_RARITY_THRESHOLD_EPIC', 20),
    'species_rarity_threshold_legendary' => env('SPECIES_RARITY_THRESHOLD_LEGENDARY', 50),
    // If none of this applies, the species is considered common
];
