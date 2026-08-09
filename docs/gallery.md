# Gallery

The public fursuit gallery at `/gallery` and the webp variants it serves. Read this before changing
gallery routes or caching, and before touching image derivation on `Fursuit`.

## Routes and folder cache

`/gallery` is a folder overview (one card per event, plus an "all fursuits" card); the grid lives at
`/gallery/all` and `/gallery/event/{event}` (`gallery.all`, `gallery.event`). Old `?event=` links
redirect into the matching folder. Folder counts and covers are cached under
`GalleryController::FOLDER_CACHE_KEY`, which `FursuitObserver` drops whenever a fursuit changes what
a card shows.

## The webp is generated on write, never on read

**The gallery webp is derived data, generated on write, never on read.** `FursuitObserver` clears
`image_webp` the moment `image` changes and queues `GenerateFursuitWebpJob`, which encodes to the
deterministic path `gallery/fursuits/{original-filename}.webp` and deletes the orphan.
`Fursuit::imageWebpUrl()` only reads, falling back to the original when no variant exists yet.

Do not restore on-read generation: it put an S3 download plus a GD encode plus a model write inside
gallery requests, and because it keyed off "column empty" rather than "photo changed", a fursuit
whose photo was replaced after approval kept serving the previous picture forever.

Backfill / repair: `php artisan fursuits:generate-webp --stale` (add `--sync` to encode in process,
`--all` to re-render everything).

## Signed URLs

Signed storage URLs are cached for 30 minutes by `Fursuit::signedStorageUrl()`; a gallery page
otherwise signs 20 objects per load.
