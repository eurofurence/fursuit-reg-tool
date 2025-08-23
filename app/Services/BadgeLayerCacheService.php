<?php

namespace App\Services;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Point;

class BadgeLayerCacheService
{
    private Imagine $imagine;

    public function __construct()
    {
        $this->imagine = new Imagine;
    }

    /**
     * Get cached background layer for badge type
     */
    public function getCachedBackgroundLayer(string $badgeType, int $width, int $height): ImageInterface
    {
        $cacheKey = "badge_background_{$badgeType}_{$width}x{$height}";

        $imageData = Cache::remember($cacheKey, now()->addHours(24), function () use ($badgeType, $width, $height) {
            $image = $this->generateBackgroundLayer($badgeType, $width, $height);

            return base64_encode($image->get('png'));
        });

        return $this->imagine->load(base64_decode($imageData));
    }

    /**
     * Generate fresh fursuit layer with green screen replacement (not cached)
     * Note: Not cached to maintain image quality - pixel-perfect processing required
     */
    public function generateFreshFursuitLayer(Fursuit $fursuit, string $badgeType, int $width, int $height): ImageInterface
    {
        // Always generate fresh for best quality
        return $this->generateFursuitLayer($fursuit, $badgeType, $width, $height);
    }

    /**
     * Get cached catch-em-all overlay
     */
    public function getCachedCatchEmAllOverlay(string $badgeType, int $width, int $height): ImageInterface
    {
        $cacheKey = "catch_overlay_{$badgeType}_{$width}x{$height}";

        $imageData = Cache::remember($cacheKey, now()->addHours(24), function () use ($badgeType, $width, $height) {
            $image = $this->generateCatchEmAllOverlay($badgeType, $width, $height);

            return base64_encode($image->get('png'));
        });

        return $this->imagine->load(base64_decode($imageData));
    }

    /**
     * Generate background layer (first layer)
     */
    private function generateBackgroundLayer(string $badgeType, int $width, int $height): ImageInterface
    {
        $size = new Box($width, $height);

        $backgroundPath = match ($badgeType) {
            'EF29_Badge' => resource_path('badges/ef29/images/first_layer_space_layout_main.png'),
            'EF28_Badge' => resource_path('badges/ef28/images/first_layer_bg_purple.png'),
            default => resource_path('badges/ef28/images/first_layer_bg_purple.png'),
        };

        $image = $this->imagine->open($backgroundPath);
        $image->resize($size);

        return $image;
    }

    /**
     * Generate fursuit layer with green screen replacement (second layer)
     */
    private function generateFursuitLayer(Fursuit $fursuit, string $badgeType, int $width, int $height): ImageInterface
    {
        $size = new Box($width, $height);

        // Load the green screen overlay
        $overlayPath = match ($badgeType) {
            'EF29_Badge' => resource_path('badges/ef29/images/second_layer_green_screen.png'),
            'EF28_Badge' => resource_path('badges/ef28/images/second_layer_green_screen.png'),
            default => resource_path('badges/ef28/images/second_layer_green_screen.png'),
        };

        $overlayImage = $this->imagine->open($overlayPath);
        $overlayImage->resize($size);

        // Load and resize fursuit image
        $fursuitImagePath = $this->getFursuitImagePath($fursuit);
        if (! $fursuitImagePath) {
            return $overlayImage; // Return overlay without replacement if no fursuit image
        }

        $fursuitImage = $this->imagine->open($fursuitImagePath);
        $fursuitImage->resize(new Box(350, 455));

        // Optimized green screen replacement using color replacement
        return $this->replaceGreenScreen($overlayImage, $fursuitImage, $size);
    }

    /**
     * Generate catch-em-all overlay (fourth layer)
     */
    private function generateCatchEmAllOverlay(string $badgeType, int $width, int $height): ImageInterface
    {
        $size = new Box($width, $height);

        $overlayPath = match ($badgeType) {
            'EF29_Badge' => resource_path('badges/ef29/images/fourth_layer_catch_em_all.png'),
            'EF28_Badge' => resource_path('badges/ef28/images/fifth_layer_catch_em_all.png'),
            default => resource_path('badges/ef28/images/fifth_layer_catch_em_all.png'),
        };

        $overlayImage = $this->imagine->open($overlayPath);
        $overlayImage->resize($size);

        return $overlayImage;
    }

    /**
     * Get fursuit image path (direct S3 temporary URL)
     */
    private function getFursuitImagePath(Fursuit $fursuit): ?string
    {
        if (! $fursuit->image) {
            return null;
        }

        try {
            // Use temporary URL directly from S3
            return Storage::temporaryUrl($fursuit->image, now()->addMinutes(5));
        } catch (\Exception $e) {
            \Log::warning("Failed to get fursuit image temporary URL for fursuit {$fursuit->id}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Original pixel-by-pixel green screen replacement for best quality
     */
    private function replaceGreenScreen(ImageInterface $overlayImage, ImageInterface $fursuitImage, Box $size): ImageInterface
    {
        $fursuitSize = $fursuitImage->getSize();
        $xOffset = 35;
        $yOffset = 35;

        // Pixel-by-pixel replacement for best quality (restored from original EF29_Badge)
        for ($x = 35; $x < $size->getWidth() - 600; $x++) {
            for ($y = 10; $y < $size->getHeight() - 150; $y++) {
                // Get the color of the pixel in the overlay image
                $color = $overlayImage->getColorAt(new Point($x, $y));

                // Get the RGB values of the pixel
                $red = $color->getValue(ColorInterface::COLOR_RED);
                $green = $color->getValue(ColorInterface::COLOR_GREEN);
                $blue = $color->getValue(ColorInterface::COLOR_BLUE);

                // Check for green screen color (RGB: 134, 194, 148)
                if ($red == 134 && $green == 194 && $blue == 148) {
                    // Calculate the position in the replacementImage taking into account the offsets
                    $replacementX = $x - $xOffset;
                    $replacementY = $y - $yOffset;

                    // Check whether the calculated coordinates are within the replacementImage
                    if ($replacementX >= 0 && $replacementX < $fursuitSize->getWidth() &&
                        $replacementY >= 0 && $replacementY < $fursuitSize->getHeight()) {

                        // Replace the green pixel with the corresponding pixel from the replacement image
                        $replacementColor = $fursuitImage->getColorAt(new Point($replacementX, $replacementY));
                        $overlayImage->draw()->dot(new Point($x, $y), $replacementColor);
                    }
                }
            }
        }

        return $overlayImage;
    }

    /**
     * Clear specific cache entries (fursuit layers no longer cached)
     */
    public function clearFursuitCache(int $fursuitId): void
    {
        // Note: Fursuit layers are no longer cached for quality reasons
        // Only background and overlay layers are cached
        // This method is kept for API compatibility but does nothing
    }

    /**
     * Clear all badge layer caches
     */
    public function clearAllBadgeCaches(): void
    {
        $patterns = [
            'badge_background_*',
            'catch_overlay_*',
        ];

        foreach ($patterns as $pattern) {
            $this->clearCacheByPattern($pattern);
        }
    }

    /**
     * Clear cache entries by pattern (simplified implementation)
     */
    private function clearCacheByPattern(string $pattern): void
    {
        // This is a simplified implementation - in production you might want to use Redis SCAN
        // or implement a more sophisticated cache key tracking system
        Cache::flush(); // For now, we'll just flush all cache - not ideal but safe
    }

    /**
     * Warm up caches for common badge types
     */
    public function warmupCaches(): void
    {
        $badgeTypes = ['EF29_Badge', 'EF28_Badge'];
        $dimensions = [[1024, 648]]; // Common badge dimensions

        foreach ($badgeTypes as $badgeType) {
            foreach ($dimensions as [$width, $height]) {
                // Warm up background layer
                $this->getCachedBackgroundLayer($badgeType, $width, $height);

                // Warm up catch-em-all overlay
                $this->getCachedCatchEmAllOverlay($badgeType, $width, $height);
            }
        }
    }
}
