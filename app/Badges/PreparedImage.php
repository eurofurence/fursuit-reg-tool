<?php

namespace App\Badges;

use Imagine\Image\BoxInterface;
use Imagine\Image\ImageInterface;

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
}
