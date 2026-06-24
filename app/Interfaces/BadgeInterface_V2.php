<?php

namespace App\Interfaces;

use App\Models\Badge\Badge;
use Imagine\Gd\Font;

interface BadgeInterface_V2
{
    /**
     * Initialize the badge.
     */
    public function init();

    /**
     * Get the font instance for the badge.
     *
     * @param int $size The font size in pixels.
     * @param string|null $font_path Optional path to the font file.
     * @return Font The initialized font instance.
     */
    public function getFont(int $size, ?string $font_path = null): Font;

    /**
     * Get the height of the badge.
     *
     * @return int The height of the badge in pixels.
     */
    public function getHeight(): int;

    /**
     * Get the width of the badge.
     *
     * @return int The width of the badge in pixels.
     */
    public function getWidth(): int;

    /**
     * Get the supported file format of the badge.
     *
     * @return string The file format (e.g., 'png', 'jpg').
     */
    public function getFileFormat(): string;

    /**
     * Apply letter spacing to the given text.
     *
     * @param string $text The text to modify.
     * @param int $spacing The amount of spacing to apply.
     * @param string $spacer The character used for spacing.
     * @return string The modified text.
     */
    public function addLetterSpacing(string $text, int $spacing = 1, string $spacer = ' '): string;
}
