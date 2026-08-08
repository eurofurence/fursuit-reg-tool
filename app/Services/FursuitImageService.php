<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Turn an attendee upload into the 3:4 badge image we actually store.
 *
 * The crop used to happen in the browser: vue-advanced-cropper drew the
 * selection into a <canvas>, pica resampled it and the resulting blob was
 * uploaded instead of the file the attendee picked. That broke in all the ways
 * client-side canvas work breaks - iOS silently caps canvas area and hands back
 * a blank or downscaled image, older browsers mangle EXIF-rotated photos, and a
 * large phone photo can exhaust the tab's memory mid-crop.
 *
 * Now the browser only sends the original file plus the selected rectangle, and
 * the crop happens here where the input is predictable.
 */
class FursuitImageService
{
    /** Badge images are always 3:4 (the stencil the attendee drags is locked to it). */
    public const ASPECT_WIDTH = 3;

    public const ASPECT_HEIGHT = 4;

    /**
     * Width of the stored master.
     *
     * Print needs far less than this - the badge renderers draw into ~350x455px
     * and ImagePreparer scales down on the way in - but the master is also what
     * the gallery webp is rendered from, so it is sized for the gallery.
     */
    public const MAX_STORED_WIDTH = 1500;

    /** Transparent uploads are flattened onto this before encoding. */
    public const TRANSPARENCY_BACKGROUND = 'ffffff';

    /** High enough that the gallery does not show artefacts on a re-encoded photo. */
    public const JPEG_QUALITY = 92;

    /** Smallest crop we accept, matching the upload dimension rules. */
    public const MIN_CROP_WIDTH = 240;

    public const MIN_CROP_HEIGHT = 320;

    /**
     * Refuse to decode beyond this many pixels - same guard as the print-side
     * ImagePreparer, applied before we ever hand the file to GD.
     */
    public const MAX_SOURCE_PIXELS = 50_000_000;

    /**
     * Crop, normalise and store an upload. Returns the path on the default disk.
     *
     * @param  array{x?: int|string, y?: int|string, width?: int|string, height?: int|string}|null  $crop
     *                                                                                                     Selection in the pixel space of the *oriented* image, as the browser
     *                                                                                                     displays it. Null (or an unusable rectangle) falls back to a centred crop.
     */
    public function store(UploadedFile $file, ?array $crop = null): string
    {
        $this->guardSourceSize($file);

        $manager = new ImageManager(new Driver);
        $image = $manager->read($file->getRealPath());

        // Browsers render <img> with EXIF orientation already applied, so the
        // coordinates we got are in oriented space. Match that before cropping.
        $image->orient();

        $this->applyCrop($image, $crop);
        $this->scaleDown($image);

        // Transparency is flattened either way: the badge renderers composite the
        // photo onto the card and would otherwise decide for themselves what shows
        // through. The upload dialog says so before the attendee confirms.
        $image->blendTransparency(self::TRANSPARENCY_BACKGROUND);

        // Format is preserved. A PNG upload is usually flat-coloured artwork or a
        // reference sheet, where a lossy re-encode is visible in the gallery; a
        // JPEG upload is a photo, where re-encoding it as PNG would multiply the
        // file size for no gain.
        $isPng = $file->getMimeType() === 'image/png';
        $encoded = $isPng
            ? $image->encodeByExtension('png')
            : $image->encodeByExtension('jpg', quality: self::JPEG_QUALITY);

        $path = 'fursuits/'.Str::random(40).($isPng ? '.png' : '.jpg');
        Storage::put($path, (string) $encoded);

        return $path;
    }

    /**
     * Reject decompression bombs before GD allocates anything.
     */
    private function guardSourceSize(UploadedFile $file): void
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            throw ValidationException::withMessages([
                'image' => 'That file could not be read as an image.',
            ]);
        }

        if ($info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            throw ValidationException::withMessages([
                'image' => sprintf(
                    'That image is %dx%d pixels, which is beyond the %d megapixel limit.',
                    $info[0], $info[1], self::MAX_SOURCE_PIXELS / 1_000_000
                ),
            ]);
        }
    }

    /**
     * Crop to the selected rectangle, clamped to the image, then snap to 3:4.
     */
    private function applyCrop(ImageInterface $image, ?array $crop): void
    {
        $rectangle = $this->sanitiseCrop($image, $crop);

        if ($rectangle !== null) {
            [$x, $y, $width, $height] = $rectangle;
            $image->crop($width, $height, $x, $y);
        }

        // Either the crop was unusable, or the attendee's rectangle is a pixel
        // or two off 3:4 from the stencil rounding. Centre-crop to the exact
        // ratio so the badge renderer never has to stretch the image.
        [$targetWidth, $targetHeight] = $this->fitAspect($image->width(), $image->height());

        if ($targetWidth !== $image->width() || $targetHeight !== $image->height()) {
            $image->crop($targetWidth, $targetHeight, position: 'center');
        }
    }

    /**
     * Clamp the requested rectangle to the image. Returns null when there is
     * nothing usable to crop to.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private function sanitiseCrop(ImageInterface $image, ?array $crop): ?array
    {
        if ($crop === null) {
            return null;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($crop[$key]) || ! is_numeric($crop[$key])) {
                return null;
            }
        }

        $x = max(0, (int) $crop['x']);
        $y = max(0, (int) $crop['y']);
        $width = min((int) $crop['width'], $image->width() - $x);
        $height = min((int) $crop['height'], $image->height() - $y);

        if ($width < 1 || $height < 1) {
            return null;
        }

        return [$x, $y, $width, $height];
    }

    /**
     * Largest 3:4 box that fits inside the given dimensions.
     *
     * @return array{0: int, 1: int}
     */
    private function fitAspect(int $width, int $height): array
    {
        $byWidth = (int) round($width * self::ASPECT_HEIGHT / self::ASPECT_WIDTH);

        if ($byWidth <= $height) {
            return [$width, $byWidth];
        }

        return [(int) round($height * self::ASPECT_WIDTH / self::ASPECT_HEIGHT), $height];
    }

    /**
     * Shrink oversized crops. Never upscales - a small crop stays small.
     */
    private function scaleDown(ImageInterface $image): void
    {
        if ($image->width() <= self::MAX_STORED_WIDTH) {
            return;
        }

        $image->resize(
            self::MAX_STORED_WIDTH,
            (int) round(self::MAX_STORED_WIDTH * self::ASPECT_HEIGHT / self::ASPECT_WIDTH)
        );
    }
}
