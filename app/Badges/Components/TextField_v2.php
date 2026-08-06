<?php

namespace App\Badges\Components;

use Imagine\Gd\Font;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Image\PointInterface;

/**
 * The TextField_v2 class is responsible for rendering text in a field,
 *  supports line breaks and font size adjustment.
 */
class TextField_v2
{
    private string $text;

    private int $width;

    private int $height;

    private int $minFontSize;

    private int $startFontSize;

    private string $font_path;

    private ColorInterface $font_color;

    private string $alignment;

    private int $maxRows;

    private ?ColorInterface $backgroundColor;

    private ?ColorInterface $borderColor;

    private int $borderThickness;

    private int $borderRadius;

    private ?ColorInterface $textStrokeColor;

    private int $textStrokeThickness;

    public function __construct(
        string $text,
        int $width,
        int $height,
        int $minFontSize,
        int $startFontSize,
        string $font_path,
        ColorInterface $font_color,
        ImageInterface $image,
        PointInterface $position,
        string $alignment,
        int $maxRows,
        ?ColorInterface $backgroundColor = null,
        ?ColorInterface $borderColor = null,
        int $borderThickness = 0,
        int $borderRadius = 0,
        ?ColorInterface $textStrokeColor = null,
        float $textStrokeThickness = 0
    ) {
        $this->text = $text;
        $this->width = $width;
        $this->height = $height;
        $this->minFontSize = $minFontSize;
        $this->startFontSize = $startFontSize;
        $this->font_path = $font_path;
        $this->font_color = $font_color;
        $this->alignment = $alignment;
        $this->maxRows = $maxRows;
        $this->backgroundColor = $backgroundColor;
        $this->borderColor = $borderColor;
        $this->borderThickness = $borderThickness;
        $this->borderRadius = $borderRadius;
        $this->textStrokeColor = $textStrokeColor;
        $this->textStrokeThickness = (int) $textStrokeThickness;

        $this->drawTextInBox($image, $position);
    }

    protected function drawTextInBox(ImageInterface $image, PointInterface $position): ImageInterface
    {
        if (trim($this->text) === '') {
            return $image;
        }

        $fontSize = $this->startFontSize;
        $palette = new RGB;

        $lines = [];
        $textBox = null;

        do {
            $font = new Font($this->font_path, $fontSize, $this->font_color);

            // Wrap text
            // This is a simplification. A correct conversion requires loops,
            // to measure the text at the current font size.
            $wrappedText = $this->wrapText($this->text, $fontSize, $this->width);
            $lines = explode("\n", $wrappedText);

            // Limit to maxRows
            if (count($lines) > $this->maxRows) {
                $lines = array_slice($lines, 0, $this->maxRows);
            }

            // Filter out empty rows
            $filteredLines = [];
            foreach ($lines as $line) {
                if (! empty(trim($line))) {
                    $filteredLines[] = trim($line);
                }
            }
            $lines = $filteredLines;

            if (empty($lines)) {
                $fontSize--;

                continue;
            }

            // Calculate the box size for all rows
            $maxLineWidth = 0;
            $totalHeight = 0;
            foreach ($lines as $line) {
                $lineBox = $font->box($line);
                $maxLineWidth = max($maxLineWidth, $lineBox->getWidth());
                $totalHeight += $lineBox->getHeight();
            }
            $textBox = new Box($maxLineWidth, $totalHeight);

            if ($textBox->getWidth() > $this->width || $textBox->getHeight() > $this->height) {
                $fontSize--;
            } else {
                break;
            }
        } while ($fontSize >= $this->minFontSize);

        if ($textBox === null || empty($lines)) {
            return $image;
        }

        // Draw each line
        $lineHeight = $textBox->getHeight() / count($lines);
        $startY = $position->getY() + ($this->height - $textBox->getHeight()) / 2;

        foreach ($lines as $index => $line) {
            $lineBox = $font->box($line);
            $y = $startY + ($index * $lineHeight);
            $x = $this->calculateXPosition($lineBox->getWidth(), $position);

            if ($this->textStrokeColor && $this->textStrokeThickness > 0) {
                for ($offsetX = -$this->textStrokeThickness; $offsetX <= $this->textStrokeThickness; $offsetX++) {
                    for ($offsetY = -$this->textStrokeThickness; $offsetY <= $this->textStrokeThickness; $offsetY++) {
                        if ($offsetX !== 0 || $offsetY !== 0) {
                            $image->draw()->text(
                                $line,
                                new Font($this->font_path, $fontSize, $this->textStrokeColor),
                                new Point($x + $offsetX, (int) $y + $offsetY)
                            );
                        }
                    }
                }
            }

            $image->draw()->text($line, $font, new Point($x, (int) $y));
        }

        return $image;
    }

    private function wrapText(string $text, int $fontSize, int $maxWidth): string
    {
        $words = explode(' ', $text);
        $lines = [];
        $currentLine = '';

        $font = new Font($this->font_path, $fontSize, $this->font_color);

        foreach ($words as $word) {
            // Check if even a single word exceeds maxWidth
            if ($font->box($word)->getWidth() > $maxWidth) {
                // If the word itself is too long, break it by characters
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $currentLine = '';
                }

                $chars = mb_str_split($word);
                foreach ($chars as $char) {
                    $testLine = $currentLine.$char;
                    if ($font->box($testLine)->getWidth() <= $maxWidth) {
                        $currentLine = $testLine;
                    } else {
                        $lines[] = $currentLine;
                        $currentLine = $char;
                    }
                }
            } else {
                // Normal word processing
                $testLine = $currentLine.($currentLine === '' ? '' : ' ').$word;
                if ($font->box($testLine)->getWidth() <= $maxWidth) {
                    $currentLine = $testLine;
                } else {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                }
            }
        }
        $lines[] = $currentLine;

        return implode("\n", $lines);
    }

    private function calculateXPosition(int $textWidth, PointInterface $position): int
    {
        switch ($this->alignment) {
            case TextAlignment::CENTER:
                return $position->getX() + ($this->width - $textWidth) / 2;
            case TextAlignment::RIGHT:
                return $position->getX() + ($this->width - $textWidth);
            case TextAlignment::LEFT:
            default:
                return $position->getX();
        }
    }
}
