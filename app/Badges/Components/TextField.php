<?php

namespace App\Badges\Components;

use Imagine\Gd\Font;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Imagine\Image\PointInterface;

/**
 * The TextField class is responsible for rendering text within a specified area on an image, adjusting the font size
 * dynamically to fit the text within the given dimensions, and supporting different text alignments.
 */
class TextField
{
    private string $text;                 // The text to be drawn in the image

    private int $width;                   // The width of the area in which the text is drawn

    private int $height;                  // The height of the area in which the text is drawn

    private int $minFontSize;             // The minimum font size that may be used

    private int $startFontSize;           // The initial font size that will be used

    private string $font_path;            // The path to the font file

    private ColorInterface $font_color;   // The color of the text

    private string $alignment;            // The alignment of the text (left, centered, right)

    private int $maxRows;                 // The maximum number of lines the text may have

    private ?ColorInterface $backgroundColor;  // Background color of the text box

    private ?ColorInterface $borderColor;      // Color of the frame

    private int $borderThickness;             // Thickness of the frame

    private int $borderRadius;                // Radius of the rounded corners

    private ?ColorInterface $textStrokeColor; // Color of the text frame

    private int $textStrokeThickness;         // Thickness of the text frame

    /**
     * Constructor for the TextField class.
     *
     * @param  string  $text  The text to be drawn on the image.
     * @param  int  $width  The width of the text box area in pixels.
     * @param  int  $height  The height of the text box area in pixels.
     * @param  int  $minFontSize  The minimum font size allowed for the text.
     * @param  int  $startFontSize  The initial font size to start with.
     * @param  string  $font_path  The file path to the font to be used.
     * @param  ColorInterface  $font_color  The color of the text.
     * @param  ImageInterface  $image  The image on which the text will be drawn.
     * @param  PointInterface  $position  The position (top-left corner) where the text box should be placed.
     * @param  string  $alignment  The alignment of the text within the text box. Defaults to left-aligned.
     * @param  int  $maxRows  The maximum number of lines allowed for the text.
     * @param  ?ColorInterface  $backgroundColor  The background color of the text box. Null for transparent.
     * @param  ?ColorInterface  $borderColor  The color of the border around the text box. Null for no border.
     * @param  int  $borderThickness  The thickness of the border. Default is 0 (no border).
     * @param  int  $borderRadius  The radius of the corners of the text box. Default is 0 (no rounding).
     * @param  ?ColorInterface  $textStrokeColor  The color of the stroke around the text. Null for no stroke.
     * @param  float  $textStrokeThickness  The thickness of the stroke around the text. Default is 0 (no stroke).
     */
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
        string $alignment, // Standardmäßig linksbündig
        int $maxRows,
        ?ColorInterface $backgroundColor = null, // No background by default
        ?ColorInterface $borderColor = null, // No border by default
        int $borderThickness = 0, // No border by default
        int $borderRadius = 0, // No rounded corners by default
        ?ColorInterface $textStrokeColor = null, // No text border by default
        float $textStrokeThickness = 0 // No text border by default
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
        $this->textStrokeThickness = $textStrokeThickness;

        // Draw the text when creating the object
        return $this->drawTextInBox($image, $position);
    }

    /**
     * Draws the text within the specified area on the given image, adjusting the font size and splitting the text
     * into multiple lines if necessary. Adds background color, border, and text stroke if specified.
     *
     * @param  ImageInterface  $image  The image on which the text will be drawn.
     * @param  PointInterface  $position  The position (top-left corner) where the text box should be placed.
     * @return ImageInterface The image with the drawn text.
     */
    protected function drawTextInBox(ImageInterface $image, PointInterface $position): ImageInterface
    {
        $fontSize = $this->startFontSize;  // Starts with the initial font size
        $palette = new RGB;              // Create an RGB palette

        do {
            // Calculate the box size with the current font size
            $font = new Font($this->font_path, $fontSize, $this->font_color);
            $textBox = $font->box($this->text);

            // Checks whether the text fits into the box
            if ($textBox->getWidth() > $this->width || $textBox->getHeight() > $this->height) {
                $fontSize--;  // Reduces the font size if the text is too large
            } else {
                break;  // Fits, no further changes required
            }
        } while ($fontSize >= $this->minFontSize);

        // Calculate the vertical position to center the text
        $y = $position->getY() + ($this->height - $textBox->getHeight()) / 2;
        // Calculate the horizontal position based on the orientation (centered, left or right)
        $x = $this->calculateXPosition($textBox->getWidth(), $position);

        // Draws the text outline, if specified
        if ($this->textStrokeColor && $this->textStrokeThickness > 0) {
            for ($offsetX = -$this->textStrokeThickness; $offsetX <= $this->textStrokeThickness; $offsetX++) {
                for ($offsetY = -$this->textStrokeThickness; $offsetY <= $this->textStrokeThickness; $offsetY++) {
                    if ($offsetX !== 0 || $offsetY !== 0) {
                        $image->draw()->text(
                            $this->text,
                            new Font($this->font_path, $fontSize, $this->textStrokeColor),
                            new Point($x + $offsetX, $y + $offsetY)
                        );
                    }
                }
            }
        }

        // Draws the text on the image at the calculated position
        $image->draw()->text($this->text, $font, new Point($x, $y));

        return $image;
    }

    /**
     * Calculates the X-position of the text based on the alignment.
     */
    private function calculateXPosition(int $textWidth, PointInterface $position): int
    {
        switch ($this->alignment) {
            case TextAlignment::CENTER:
                return $position->getX() + ($this->width - $textWidth) / 2;
            case TextAlignment::RIGHT:
                return $position->getX() + ($this->width - $textWidth);
            case TextAlignment::LEFT:
            default:
                return $position->getX(); // Left-aligned
        }
    }
}
