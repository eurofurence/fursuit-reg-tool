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
 * The TextField class is responsible for rendering text within a specified area on an image, adjusting the font size
 * dynamically to fit the text within the given dimensions, and supporting different text alignments.
 */
class TextField
{
    private string $text;                 // Der Text, der in das Bild gezeichnet werden soll

    private int $width;                   // Die Breite des Bereichs, in den der Text gezeichnet wird

    private int $height;                  // Die Höhe des Bereichs, in den der Text gezeichnet wird

    private int $minFontSize;             // Die minimale Schriftgröße, die verwendet werden darf

    private int $startFontSize;           // Die anfängliche Schriftgröße, die verwendet wird

    private string $font_path;            // Der Pfad zur Schriftartdatei

    private ColorInterface $font_color;   // Die Farbe des Textes

    private string $alignment;            // Die Ausrichtung des Textes (links, zentriert, rechts)

    private int $maxRows;                 // Die maximale Anzahl der Zeilen, die der Text haben darf

    private ?ColorInterface $backgroundColor;  // Hintergrundfarbe der Textbox

    private ?ColorInterface $borderColor;      // Farbe des Rahmens

    private int $borderThickness;             // Dicke des Rahmens

    private int $borderRadius;                // Radius der abgerundeten Ecken

    private ?ColorInterface $textStrokeColor; // Farbe der Textumrahmung

    private int $textStrokeThickness;         // Dicke der Textumrahmung

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
        ?ColorInterface $backgroundColor = null, // Standardmäßig kein Hintergrund
        ?ColorInterface $borderColor = null,     // Standardmäßig keine Umrandung
        int $borderThickness = 0,                // Standardmäßig keine Umrandung
        int $borderRadius = 0,                   // Standardmäßig keine abgerundeten Ecken
        ?ColorInterface $textStrokeColor = null, // Standardmäßig keine Textumrahmung
        float $textStrokeThickness = 0             // Standardmäßig keine Textumrahmung
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

        // Zeichne den Text beim Erstellen des Objekts
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
        $fontSize = $this->startFontSize;  // Startet mit der anfänglichen Schriftgröße
        $palette = new RGB;              // Erzeuge eine RGB-Palette

        do {
            // Berechne die Box-Größe mit der aktuellen Schriftgröße
            $font = new Font($this->font_path, $fontSize, $this->font_color);
            $textBox = $font->box($this->text);

            // Überprüft, ob der Text in die Box passt
            if ($textBox->getWidth() > $this->width || $textBox->getHeight() > $this->height) {
                $fontSize--;  // Reduziert die Schriftgröße, wenn der Text zu groß ist
            } else {
                break;  // Passt, keine weiteren Änderungen erforderlich
            }
        } while ($fontSize >= $this->minFontSize);

        // Berechne die vertikale Position, um den Text zentriert zu platzieren
        $y = $position->getY() + ($this->height - $textBox->getHeight()) / 2;
        // Berechne die horizontale Position basierend auf der Ausrichtung (zentriert, links oder rechts)
        $x = $this->calculateXPosition($textBox->getWidth(), $position);

        // Zeichnet den Textumriss, falls angegeben
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

        // Zeichnet den Text auf das Bild an der berechneten Position
        $image->draw()->text($this->text, $font, new Point($x, $y));

        return $image;
    }

    /**
     * Berechnet die X-Position des Textes basierend auf der Ausrichtung.
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
                return $position->getX(); // Linksbündig
        }
    }
}
