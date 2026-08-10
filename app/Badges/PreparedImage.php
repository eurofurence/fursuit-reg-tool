<?php

namespace App\Badges;

use GdImage;
use Imagine\Gd\Image as GdBackedImage;
use Imagine\Image\BoxInterface;
use Imagine\Image\ImageInterface;
use RuntimeException;

/**
 * An attendee image already downloaded, checked and scaled to the box a badge
 * renderer asked for. Produced by ImagePreparer.
 */
class PreparedImage
{
    public function __construct(
        public readonly ImageInterface $image,
        public readonly bool $isPng,
    ) {}

    public function size(): BoxInterface
    {
        return $this->image->getSize();
    }

    /**
     * The underlying GD resource, for the pixel work that has to bypass Imagine.
     *
     * The resource belongs to $image and dies with it, so callers must keep this
     * PreparedImage alive for as long as they use what comes back.
     */
    public function gd(): GdImage
    {
        if (! $this->image instanceof GdBackedImage) {
            throw new RuntimeException('Badge rendering needs the GD driver, got '.$this->image::class);
        }

        return $this->image->getGdResource();
    }
}
