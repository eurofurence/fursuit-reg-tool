<?php

namespace App\Badges;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagine\Image\Box;
use Imagine\Image\ImagineInterface;
use RuntimeException;

/**
 * Fetch an attendee's uploaded image and get it down to printable size.
 *
 * Attendee uploads are whatever came off a phone: routinely several megabytes
 * and thousands of pixels across. The badge renderers only ever draw that image
 * into a box a few hundred pixels wide, so every pixel beyond that is decoded,
 * held in memory and thrown away.
 *
 * The renderers used to do this badly in two specific ways, both fixed here:
 *
 *  1. They opened the image straight from a signed S3 URL and then called
 *     getimagesize() on the same URL to find out whether it was a PNG. That is
 *     two full HTTP downloads of a multi-megabyte file to render one card.
 *  2. They decoded at full resolution and only then resized, so a 6000x4000
 *     upload was fully expanded in memory before being shrunk to 350x455.
 *
 * This downloads once to a local temp file, inspects it locally, refuses
 * anything absurd, and hands back an image already scaled to the box the
 * renderer asked for.
 */
class ImagePreparer
{
    /**
     * Refuse to decode beyond this many pixels.
     *
     * A JPEG that decompresses to hundreds of megapixels is either a mistake or
     * an attack, and either way it must not take the print queue down mid-event.
     */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    public function __construct(private readonly ImagineInterface $imagine) {}

    /**
     * Load $storagePath and scale it to fit within $width x $height.
     *
     * @param  string  $storagePath  Path on the default filesystem disk.
     */
    public function prepare(string $storagePath, int $width, int $height): PreparedImage
    {
        $localPath = $this->download($storagePath);

        try {
            [$sourceWidth, $sourceHeight, $type] = $this->inspect($localPath, $storagePath);

            $image = $this->imagine->open($localPath);
            $image->resize(new Box($width, $height));

            Log::debug('Prepared badge image', [
                'path' => $storagePath,
                'source' => $sourceWidth.'x'.$sourceHeight,
                'target' => $width.'x'.$height,
            ]);

            return new PreparedImage($image, $type === IMAGETYPE_PNG);
        } finally {
            // The decoded image is in memory now; the temp file has done its job
            // whether or not decoding succeeded.
            @unlink($localPath);
        }
    }

    /**
     * Copy the remote object to local disk with a single read.
     */
    private function download(string $storagePath): string
    {
        $disk = Storage::disk();

        if (! $disk->exists($storagePath)) {
            throw new RuntimeException("Badge image missing from storage: {$storagePath}");
        }

        $localPath = tempnam(sys_get_temp_dir(), 'badge-src-');
        $source = $disk->readStream($storagePath);

        if ($source === false || $source === null) {
            @unlink($localPath);
            throw new RuntimeException("Could not read badge image: {$storagePath}");
        }

        $destination = fopen($localPath, 'wb');

        try {
            stream_copy_to_stream($source, $destination);
        } finally {
            fclose($destination);
            if (is_resource($source)) {
                fclose($source);
            }
        }

        return $localPath;
    }

    /**
     * Read dimensions and type from the local copy, and reject the absurd.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function inspect(string $localPath, string $storagePath): array
    {
        $info = @getimagesize($localPath);

        if ($info === false) {
            throw new RuntimeException("Badge image is not a readable image: {$storagePath}");
        }

        [$width, $height, $type] = $info;

        if ($width * $height > self::MAX_SOURCE_PIXELS) {
            throw new RuntimeException(sprintf(
                'Badge image %s is %dx%d, beyond the %d megapixel limit.',
                $storagePath, $width, $height, self::MAX_SOURCE_PIXELS / 1_000_000
            ));
        }

        return [$width, $height, $type];
    }
}
