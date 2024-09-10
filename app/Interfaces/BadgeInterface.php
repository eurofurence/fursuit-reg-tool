<?php

namespace App\Interfaces;

use Imagine\Gd\Font;
use App\Models\Badge\Badge;
use Illuminate\Http\Response;
use Imagine\Image\ImageInterface;
use Imagine\Image\PointInterface;

interface BadgeInterface
{
    public function init();
    public function getPdf(Badge $badge): string;
    public function getFont(int $size, ?string $font_path = null): Font;
    public function getHeight(): int;
    public function getWidth(): int;
    public function getFileFormat(): string;
    public function addLetterSpacing(string $text, int $spacing = 1, string $spacer = ' ');
}
