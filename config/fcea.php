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
    | Species Rarity Thresholds
    |--------------------------------------------------------------------------
    |
    | Rarity is how many fursuits of a species are registered for the event:
    | fewer means rarer. A species at or below a threshold gets that rarity, and
    | anything above the uncommon threshold is common.
    |
    | These are counts, not percentages, because the distribution is a long tail.
    | Measured against EF30 (2,748 catchable fursuits across 738 species, 538 of
    | them one-of-a-kind) these defaults land at roughly:
    |
    |   legendary  538 species,  19% of the fursuits at the event
    |   epic       129 species,  13%
    |   rare        46 species,  17%
    |   uncommon    18 species,  19%
    |   common       7 species,  33%
    |
    | Raise the legendary threshold and Legendary swallows the tail; lower the
    | common one and Fox, Wolf and Dragon stop being common, which they plainly
    | are. Recheck these against the event before an event opens.
    |
    */

    'rarity_population_legendary' => env('RARITY_POPULATION_LEGENDARY', 1),
    'rarity_population_epic' => env('RARITY_POPULATION_EPIC', 5),
    'rarity_population_rare' => env('RARITY_POPULATION_RARE', 19),
    'rarity_population_uncommon' => env('RARITY_POPULATION_UNCOMMON', 49),
];
