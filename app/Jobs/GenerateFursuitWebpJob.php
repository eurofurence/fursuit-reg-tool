<?php

namespace App\Jobs;

use App\Models\Fursuit\Fursuit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;

/**
 * Render the gallery webp for a fursuit photo.
 *
 * This used to happen inside the `image_webp_url` accessor: the first page view that
 * touched a fursuit without a webp downloaded the original from s3, re-encoded it and
 * wrote the row back, all inside the request. A gallery page could therefore trigger
 * twenty encodes, and because the accessor only generated when `image_webp` was *empty*,
 * a replaced photo kept serving the webp of the previous one forever.
 *
 * Generation now belongs to the write side: FursuitObserver clears the stale reference
 * when the photo changes and queues this job, and the accessor only reads.
 */
class GenerateFursuitWebpJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 1;

    /**
     * Full HD bounding box for the full-size gallery variant (the lightbox).
     *
     * The stored master is sized for archival (FursuitImageService::MAX_STORED_WIDTH,
     * 1500x2000), which is more than any gallery viewport asks for. Badge images are
     * 3:4, so this lands at 1080x1440; the box form also handles legacy uploads that
     * predate the fixed ratio.
     */
    public const MAX_WIDTH = 1080;

    public const MAX_HEIGHT = 1920;

    /**
     * Bounding box for the grid thumbnail. A gallery page shows dozens of cards at
     * a few hundred pixels each; serving the Full HD variant into them was most of
     * the page weight.
     */
    public const THUMB_MAX_WIDTH = 500;

    public const THUMB_MAX_HEIGHT = 500;

    public const QUALITY = 82;

    /**
     * Object metadata, so the bucket answers with a cache policy.
     *
     * Without this the variants come back with no `Cache-Control` at all and every page
     * load refetches them. `immutable` is honest here: a variant's key is derived from
     * the master's random filename, so a replaced photo produces a new key rather than
     * new bytes behind the old one.
     */
    public const CACHE_HEADERS = ['CacheControl' => 'public, max-age=31536000, immutable'];

    public function __construct(public int $fursuitId) {}

    /**
     * One pending render per fursuit; a burst of edits collapses into a single encode.
     */
    public function uniqueId(): string
    {
        return 'fursuit-webp-'.$this->fursuitId;
    }

    public function handle(): void
    {
        $fursuit = Fursuit::withTrashed()->find($this->fursuitId);

        if (! $fursuit || ! $fursuit->image) {
            return;
        }

        $target = self::pathFor($fursuit->image);
        $thumbTarget = self::thumbPathFor($fursuit->image);

        // Already rendered for this exact photo (the job can be retried or queued twice
        // for the same upload) - nothing to do.
        if ($fursuit->image_webp === $target && $fursuit->image_thumb === $thumbTarget
            && Storage::exists($target) && Storage::exists($thumbTarget)) {
            return;
        }

        if (! Storage::exists($fursuit->image)) {
            // Placeholder rows (imports, fixtures) point at files that were never
            // uploaded. Retrying will not conjure them up.
            Log::warning('No source image on disk for fursuit '.$fursuit->id.': '.$fursuit->image);

            return;
        }

        try {
            // One decode, two encodes: the thumbnail is derived from the already
            // scaled-down full variant rather than from the master a second time.
            $image = (new ImageManager(new Driver))
                ->read(Storage::get($fursuit->image))
                // scaleDown, not resize: an image already inside the box is left alone
                // rather than upscaled.
                ->scaleDown(self::MAX_WIDTH, self::MAX_HEIGHT);

            $encoded = $image->toWebp(quality: self::QUALITY);

            $encodedThumb = $image
                ->scaleDown(self::THUMB_MAX_WIDTH, self::THUMB_MAX_HEIGHT)
                ->toWebp(quality: self::QUALITY);
        } catch (DecoderException $e) {
            // Whatever is stored is not an image GD will take. That is permanent, so
            // fail quietly: the accessor falls back to the original file, which means
            // this costs the gallery bandwidth rather than content.
            Log::warning('Failed to decode source image for fursuit '.$fursuit->id.': '.$e->getMessage());

            return;
        } catch (\Throwable $e) {
            // Anything else (a storage hiccup, an out-of-memory encode) is worth a retry.
            Log::warning('Failed to generate WebP for fursuit '.$fursuit->id.': '.$e->getMessage());

            throw $e;
        }

        Storage::put($target, (string) $encoded, self::CACHE_HEADERS);
        Storage::put($thumbTarget, (string) $encodedThumb, self::CACHE_HEADERS);

        $stale = [$fursuit->image_webp, $fursuit->image_thumb];

        // Quietly: this is a derived column, and a normal save would re-run the observer
        // and invalidate every print file for the badge again.
        $fursuit->forceFill([
            'image_webp' => $target,
            'image_thumb' => $thumbTarget,
        ])->saveQuietly();

        foreach ($stale as $path) {
            if ($path && $path !== $target && $path !== $thumbTarget) {
                Storage::delete($path);
            }
        }
    }

    /**
     * Where the webp for a given original lives. Deterministic, so a re-run overwrites
     * instead of littering the bucket.
     */
    public static function pathFor(string $image): string
    {
        return 'gallery/fursuits/'.pathinfo($image, PATHINFO_FILENAME).'.webp';
    }

    /**
     * Where the grid thumbnail for a given original lives.
     */
    public static function thumbPathFor(string $image): string
    {
        return 'gallery/fursuits/thumbs/'.pathinfo($image, PATHINFO_FILENAME).'.webp';
    }
}
