<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public gallery variants
    |--------------------------------------------------------------------------
    |
    | The rendered webp variants under `gallery/fursuits/` are public content by
    | definition - they only exist for fursuits their owner published. Serving them
    | as plain, unsigned URLs is what makes them cacheable: a presigned URL is a
    | different string every time it is minted, so the browser treats the same
    | picture as a new resource on every page load and `Cache-Control` never gets a
    | chance to apply. A stable URL plus the immutable header the render job writes
    | means one download, ever, and it can sit behind a CDN.
    |
    | Turning this on requires the bucket to answer unauthenticated GETs for that
    | prefix - a policy granting `s3:GetObject` on `gallery/fursuits/*` and nothing
    | else. Do NOT grant `s3:ListBucket`: the filenames are the only thing keeping
    | an unpublished fursuit's variant from being enumerated.
    |
    | Master photos under `fursuits/` stay private and signed either way.
    |
    */

    'public_variants' => env('GALLERY_PUBLIC_VARIANTS', false),

    /*
    | The prefix the setting above applies to. Anything outside it keeps being
    | signed, so a fursuit still waiting on its render (whose URL points at the
    | master) does not leak.
    */

    'variant_prefix' => 'gallery/',

];
