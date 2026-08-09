<?php

namespace App\Badges;

use GdImage;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;

/**
 * Drop an attendee's photo into the green window of a badge overlay.
 *
 * Every badge design works the same way: an overlay PNG carries a flat green rectangle where
 * the photo belongs, and the renderer walks that region swapping green pixels for photo
 * pixels. The green is a shaped hole, not a plain rectangle - it has rounded corners and the
 * artwork overlaps it - so it cannot simply be `imagecopy`d over.
 *
 * ## Why this is not written with Imagine
 *
 * It was, and it was the single worst thing in the print pipeline on both counts.
 *
 * **Speed.** `getColorAt()` builds a `Point`, reads the pixel, calls `imagecolorsforindex()`
 * and constructs an RGB `Color`; `draw()->dot()` builds another `Point`, allocates a colour
 * and toggles blending. Doing that ~90,000 times per side cost ~320ms of the ~700ms it took
 * to render one card. The same pixels through `imagecolorat()` / `imagesetpixel()` cost
 * ~20ms.
 *
 * **Memory.** `Imagine\Image\Palette\RGB` memoises every colour it is ever asked for in a
 * `protected static $colors` array. Static, so it belongs to the process and not to the
 * image: freeing the image, unsetting the renderer and forcing a GC cycle all leave it
 * untouched, because a static array is reachable by definition. Reading ~90,000 mostly
 * unique photo pixels per card added 28,000-57,000 permanently cached objects each time,
 * measured at 20-30MB per badge with no plateau.
 *
 * That is what killed `PrepareBadgePrintBatchJob`, which renders every badge of a run inside
 * one process: it ran out of its 1GB at around the fortieth card, and because the job's
 * `retry_after` then made the run visible again, a second worker picked it up and failed it
 * with "has been attempted too many times". Rendering the same badge repeatedly leaked
 * nothing - its colours were already cached - which is what made the leak look like it was
 * not there.
 *
 * Reading pixels as plain ints touches no palette. The cache stays empty for the whole run.
 *
 * ## Keeping the output identical
 *
 * The masks, the tolerances and the per-design transparency rules below are transcriptions
 * of what each renderer did, not improvements on it. The output is verified byte-identical
 * against golden renders of real badges, both sides, for all three designs - a card that
 * printed last year has to still render the same way this year.
 */
final class Greenscreen
{
    /**
     * Where the green is, per overlay and size. The overlay is a fixed asset, so its green
     * region never moves: scanning it costs ~35ms and is worth doing exactly once.
     *
     * @var array<string, array{points: list<array{int, int}>, minX: int, maxX: int, minY: int, maxY: int}>
     */
    private static array $masks = [];

    /**
     * @param  string  $overlayPath  the artwork carrying the green window
     * @param  array{int, int, int}  $key  the green being looked for
     * @param  int  $tolerance  per channel; 0 means an exact match
     * @param  int  $left  scan window, transcribed from each renderer's loop bounds
     */
    public function __construct(
        private readonly string $overlayPath,
        private readonly array $key,
        private readonly int $tolerance,
        private readonly int $left,
        private readonly int $top,
        private readonly int $rightInset,
        private readonly int $bottomInset,
    ) {}

    /**
     * The green region of this overlay at badge size.
     *
     * @return array{points: list<array{int, int}>, minX: int, maxX: int, minY: int, maxY: int}
     */
    public function mask(int $width, int $height): array
    {
        $key = implode('|', [$this->overlayPath, $width.'x'.$height, implode(',', $this->key), $this->tolerance,
            $this->left, $this->top, $this->rightInset, $this->bottomInset]);

        if (isset(self::$masks[$key])) {
            return self::$masks[$key];
        }

        $overlay = BadgeAssets::copy($this->overlayPath, $width, $height);

        $points = [];
        $minX = $width;
        $maxX = 0;
        $minY = $height;
        $maxY = 0;

        for ($x = $this->left; $x < $width - $this->rightInset; $x++) {
            for ($y = $this->top; $y < $height - $this->bottomInset; $y++) {
                if (! $this->isKeyColour(imagecolorat($overlay, $x, $y))) {
                    continue;
                }

                $points[] = [$x, $y];
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        imagedestroy($overlay);

        return self::$masks[$key] = compact('points', 'minX', 'maxX', 'minY', 'maxY');
    }

    /**
     * Paste the overlay onto $base with the photo showing through its green window.
     *
     * The photo pixel for mask point (x, y) is taken from ($x - $offsetX, $y - $offsetY);
     * points that fall outside the photo are left green, as they were before.
     *
     * An overlay with no green at all is pasted unchanged. EF30 has always had that fallback
     * and the other two designs did the same thing by simply never entering their loop.
     */
    public function apply(
        ImageInterface $base,
        GdImage $photo,
        int $offsetX,
        int $offsetY,
        GreenscreenTransparency $onTransparent = GreenscreenTransparency::Ignore,
        bool $photoHasAlpha = false,
    ): void {
        $size = $base->getSize();
        $width = $size->getWidth();
        $height = $size->getHeight();

        $overlay = BadgeAssets::copy($this->overlayPath, $width, $height);
        $mask = $this->mask($width, $height);

        $photoWidth = imagesx($photo);
        $photoHeight = imagesy($photo);
        $readAlpha = $photoHasAlpha && $onTransparent !== GreenscreenTransparency::Ignore;
        $baseResource = $onTransparent === GreenscreenTransparency::TakeFromBase ? $base->getGdResource() : null;

        // Imagine's drawer blends by default, so a partly transparent photo pixel was
        // composited onto the green underneath it rather than replacing it - the soft edge
        // of a cut-out PNG comes out opaque with a green tint. Writing the pixel straight
        // would keep its alpha instead and change those edges, so blending stays on.
        imagealphablending($overlay, true);

        foreach ($mask['points'] as [$x, $y]) {
            $photoX = $x - $offsetX;
            $photoY = $y - $offsetY;

            if ($photoX < 0 || $photoX >= $photoWidth || $photoY < 0 || $photoY >= $photoHeight) {
                continue;
            }

            $pixel = imagecolorat($photo, $photoX, $photoY);

            if ($readAlpha && self::alphaPercent($pixel) <= 80) {
                if ($baseResource === null) {
                    continue;
                }

                $pixel = imagecolorat($baseResource, $x, $y);
            }

            imagesetpixel($overlay, $x, $y, self::roundTripAlpha($pixel));
        }

        imagealphablending($overlay, false);

        $base->paste(BadgeAssets::wrap($overlay), new Point(0, 0));
    }

    /**
     * Imagine reports alpha as an opacity percentage, and both the 80 thresholds below were
     * written against that scale. Same arithmetic as Imagine\Gd\Image::getColorAt().
     */
    private static function alphaPercent(int $pixel): int
    {
        $alpha = ($pixel >> 24) & 0x7F;

        return max(min(100 - (int) round($alpha / 127 * 100), 100), 0);
    }

    /**
     * Put a pixel's alpha through the same lossy conversion Imagine performed.
     *
     * A pixel read with `getColorAt()` and written with `dot()` went 0-127 -> percent ->
     * 0-127, and those two scales do not divide evenly: alpha 7 comes back as 8. Copying the
     * true alpha instead is more accurate and produces a different file, and "more accurate"
     * is not what this is for - a badge printed last year has to render the same today. So
     * the loss is reproduced exactly, from a table of all 128 possible values.
     *
     * @var array<int, int>|null
     */
    private static ?array $alphaRoundTrip = null;

    private static function roundTripAlpha(int $pixel): int
    {
        if (self::$alphaRoundTrip === null) {
            self::$alphaRoundTrip = [];

            for ($alpha = 0; $alpha <= 127; $alpha++) {
                $percent = max(min(100 - (int) round($alpha / 127 * 100), 100), 0);
                self::$alphaRoundTrip[$alpha] = (int) round((100 - $percent) * 127 / 100);
            }
        }

        $alpha = ($pixel >> 24) & 0x7F;

        return (self::$alphaRoundTrip[$alpha] << 24) | ($pixel & 0xFFFFFF);
    }

    private function isKeyColour(int $pixel): bool
    {
        [$red, $green, $blue] = $this->key;

        return abs((($pixel >> 16) & 0xFF) - $red) <= $this->tolerance
            && abs((($pixel >> 8) & 0xFF) - $green) <= $this->tolerance
            && abs(($pixel & 0xFF) - $blue) <= $this->tolerance;
    }
}
