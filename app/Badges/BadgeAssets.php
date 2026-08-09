<?php

namespace App\Badges;

use GdImage;
use Imagine\Gd\Image as GdBackedImage;
use Imagine\Image\ImageInterface;
use Imagine\Image\Metadata\MetadataBag;
use Imagine\Image\Palette\RGB;
use RuntimeException;

/**
 * The badge artwork, decoded once per process instead of once per card.
 *
 * Every badge is drawn on top of the same handful of files - a background, a greenscreen
 * overlay, a catch-code panel - and each of them is a 2300x1500 PNG that the renderers used
 * to `open()` and `resize()` again for every single badge. That is ~75ms of decode and
 * resample per asset per card, spent producing a canvas identical to the one the previous
 * card just threw away. A print run of a hundred badges decoded the same 2.2MB background a
 * hundred times.
 *
 * Here each (path, size) pair is decoded and resampled once and kept as a master canvas; a
 * badge gets a copy to draw on. Copying a 1024x648 canvas is ~3ms.
 *
 * The canvases are deliberately built the way Imagine builds its own, because the renderers'
 * output has to stay byte-identical: transparent-white fill, a registered transparent colour
 * index, alpha saved, blending off. Skip any of that and every transparent pixel of the
 * artwork blends onto opaque black - the card renders black and only the photo and the text
 * survive, which is exactly what the first attempt at this produced.
 */
final class BadgeAssets
{
    /**
     * Master canvases, keyed by path and size. Each is ~2.6MB at badge size, and there are
     * only a handful, so this stays far below what a single decode used to cost.
     *
     * @var array<string, GdImage>
     */
    private static array $masters = [];

    /**
     * An Imagine image the renderers can paste onto and hand to the text layer.
     */
    public static function image(string $path, int $width, int $height): ImageInterface
    {
        return self::wrap(self::copy($path, $width, $height));
    }

    /**
     * A private copy of the artwork at badge size, safe to draw on.
     */
    public static function copy(string $path, int $width, int $height): GdImage
    {
        $canvas = self::canvas($width, $height);

        // Blending is off on a fresh canvas, so this copies the source pixels and their
        // alpha verbatim rather than compositing them onto the fill.
        imagecopy($canvas, self::master($path, $width, $height), 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /**
     * Wrap a GD resource so Imagine-based code - paste(), the text fields, get('png') - can
     * keep working on it. The returned image owns the resource and frees it on destruction.
     */
    public static function wrap(GdImage $resource): ImageInterface
    {
        return new GdBackedImage($resource, new RGB, new MetadataBag);
    }

    /**
     * A blank canvas built exactly like Imagine\Gd\Image::createImage().
     */
    public static function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            throw new RuntimeException("Could not create a {$width}x{$height} badge canvas.");
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if (function_exists('imageantialias')) {
            imageantialias($canvas, true);
        }

        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecolortransparent($canvas, $transparent);

        return $canvas;
    }

    /**
     * A resample that matches Imagine\Gd\Image::resize(): blending on for the copy so
     * partially transparent source pixels are interpolated the same way, off again after.
     */
    public static function resample(GdImage $source, int $width, int $height): GdImage
    {
        $destination = self::canvas($width, $height);

        imagealphablending($source, true);
        imagealphablending($destination, true);

        if (! imagecopyresampled($destination, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
            throw new RuntimeException("Could not resample badge artwork to {$width}x{$height}.");
        }

        imagealphablending($source, false);
        imagealphablending($destination, false);

        return $destination;
    }

    /**
     * Drop the cache. Only tests need this; a worker wants the assets to stay warm.
     */
    public static function flush(): void
    {
        self::$masters = [];
    }

    private static function master(string $path, int $width, int $height): GdImage
    {
        $key = $path.'|'.$width.'x'.$height;

        if (! isset(self::$masters[$key])) {
            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("Badge artwork missing: {$path}");
            }

            $decoded = @imagecreatefromstring($contents);

            if ($decoded === false) {
                throw new RuntimeException("Badge artwork is not a readable image: {$path}");
            }

            self::$masters[$key] = self::resample($decoded, $width, $height);
            imagedestroy($decoded);
        }

        return self::$masters[$key];
    }
}
