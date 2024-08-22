<?php

namespace App\Interfaces;

use Imagine\Gd\Font;
use App\Models\Badge\Badge;
use Illuminate\Http\Response;

interface BadgeInterface
{
    public function init();
    public function getImage(Badge $badge): Response;
    public function getFont(int $size): Font;
    public function getHeight(): int;
    public function getWidth(): int;
    public function getFileFormat(): string;
    public function addLetterSpacing(string $text, int $spacing = 1, string $spacer = ' ');
}
