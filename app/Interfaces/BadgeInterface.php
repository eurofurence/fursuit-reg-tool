<?php

namespace App\Interfaces;

use App\Models\Badge\Badge;
use Imagine\Gd\Font;

interface BadgeInterface
{
    public function init();

    /**
     * @param Badge $badge
     * @return string
     */
    public function getPdf(Badge $badge): string;

    /**
     * @param int $size
     * @param string|null $font_path
     * @return Font
     */
    public function getFont(int $size, ?string $font_path = null): Font;

    /**
     * @return int
     */
    public function getHeight(): int;

    /**
     * @return int
     */
    public function getWidth(): int;

    /**
     * @return string
     */
    public function getFileFormat(): string;

    /**
     * @param string $text
     * @param int $spacing
     * @param string $spacer
     * @return mixed
     */
    public function addLetterSpacing(string $text, int $spacing = 1, string $spacer = ' ');
}
