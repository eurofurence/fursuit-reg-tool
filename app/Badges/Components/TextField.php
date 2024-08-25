<?php

namespace App\Badges\Components;

use Imagine\Gd\Font;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Imagine\Image\ImageInterface;
use Imagine\Image\PointInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Gd\Imagine;
use Imagine\Image\DrawerInterface;

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
     * @param string $text The text to be drawn on the image.
     * @param int $width The width of the text box area in pixels.
     * @param int $height The height of the text box area in pixels.
     * @param int $minFontSize The minimum font size allowed for the text.
     * @param int $startFontSize The initial font size to start with.
     * @param string $font_path The file path to the font to be used.
     * @param ColorInterface $font_color The color of the text.
     * @param ImageInterface $image The image on which the text will be drawn.
     * @param PointInterface $position The position (top-left corner) where the text box should be placed.
     * @param string $alignment The alignment of the text within the text box. Defaults to left-aligned.
     * @param int $maxRows The maximum number of lines allowed for the text.
     * @param ?ColorInterface $backgroundColor The background color of the text box. Null for transparent.
     * @param ?ColorInterface $borderColor The color of the border around the text box. Null for no border.
     * @param int $borderThickness The thickness of the border. Default is 0 (no border).
     * @param int $borderRadius The radius of the corners of the text box. Default is 0 (no rounding).
     * @param ?ColorInterface $textStrokeColor The color of the stroke around the text. Null for no stroke.
     * @param float $textStrokeThickness The thickness of the stroke around the text. Default is 0 (no stroke).
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
        string $alignment = TextAlignment::LEFT, // Standardmäßig linksbündig
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
     * @param ImageInterface $image The image on which the text will be drawn.
     * @param PointInterface $position The position (top-left corner) where the text box should be placed.
     *
     * @return ImageInterface The image with the drawn text.
     */
    protected function drawTextInBox(ImageInterface $image, PointInterface $position): ImageInterface
    {
        $fontSize = $this->startFontSize;  // Startet mit der anfänglichen Schriftgröße
        $lines = [];                       // Array zum Speichern der Zeilen des Textes
        $palette = new RGB();              // Erzeuge eine RGB-Palette

        do {
            // Wenn der Text ein zusammenhängender String ohne Leerzeichen ist, füge das gesamte Wort in die Zeile ein
            if (strpos($this->text, ' ') === false) {
                $lines = [$this->text];
                $font = new Font($this->font_path, $fontSize, $this->font_color);
                $textBox = $font->box($this->text);

                // Überprüft, ob der gesamte String in die Breite passt
                if ($textBox->getWidth() > $this->width) {
                    $fontSize--;  // Reduziert die Schriftgröße, wenn der Text nicht passt
                } else {
                    break;  // Passt, keine weiteren Änderungen erforderlich
                }
            } else {
                // Teilt den Text in Wörter auf
                $words = explode(' ', $this->text);
                $lines = [];
                $currentLine = '';

                // Erstelle die Font-Instanz für die aktuelle Schleife
                $font = new Font($this->font_path, $fontSize, $this->font_color);

                // Verarbeitet jedes Wort und fügt es zur aktuellen Zeile hinzu
                foreach ($words as $word) {
                    $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
                    $textBox = $font->box($testLine);

                    // Überprüft, ob die aktuelle Zeile in die Breite passt
                    if ($textBox->getWidth() > $this->width) {
                        if (!empty($currentLine)) {
                            $lines[] = $currentLine;
                        }
                        $currentLine = $word;
                    } else {
                        $currentLine = $testLine;
                    }
                }
                $lines[] = $currentLine;

                // Überprüft, ob die Anzahl der Zeilen die maximale Anzahl überschreitet
                if (count($lines) > $this->maxRows) {
                    $fontSize--;  // Reduziert die Schriftgröße, wenn zu viele Zeilen vorhanden sind
                } else {
                    break;
                }
            }
        } while ($fontSize >= $this->minFontSize);

        // Erstelle die Font-Instanz mit finaler Schriftgröße
        $font = new Font($this->font_path, $fontSize, $this->font_color);

        // Berechnet die vertikale Startposition, um den Text zentriert zu platzieren
        $y = $position->getY() + ($this->height - (count($lines) * $fontSize)) / 2;

        // Zeichne Hintergrundfarbe und Rahmen, wenn angegeben
        $drawer = $image->draw();
        if ($this->backgroundColor || $this->borderColor) {
            $box = new Box($this->width, $this->height);
            $boxEnd = new Point($position->getX() + $this->width, $position->getY() + $this->height);

            if ($this->backgroundColor) {
                // Erzeuge ein Rechteck mit der Hintergrundfarbe
                $drawer->rectangle($position, $boxEnd, $this->backgroundColor, true);
            }

            if ($this->borderThickness > 0 && $this->borderColor) {
                // Zeichne den Rahmen
                $drawer->rectangle($position, $boxEnd, $this->borderColor, false, $this->borderThickness);
            }
        }

        // Zeichnet jede Zeile auf das Bild
        foreach ($lines as $line) {
            // Berechnet die X-Position basierend auf der Ausrichtung
            $textBox = $font->box($line);

            switch ($this->alignment) {
                case TextAlignment::CENTER:
                    $x = $position->getX() + ($this->width - $textBox->getWidth()) / 2;
                    break;
                case TextAlignment::RIGHT:
                    $x = $position->getX() + ($this->width - $textBox->getWidth());
                    break;
                case TextAlignment::LEFT:
                default:
                    $x = $position->getX(); // Linksbündig
                    break;
            }

            // Zeichnet den Textumriss, falls angegeben
            if ($this->textStrokeColor && $this->textStrokeThickness > 0) {
                for ($offsetX = -$this->textStrokeThickness; $offsetX <= $this->textStrokeThickness; $offsetX++) {
                    for ($offsetY = -$this->textStrokeThickness; $offsetY <= $this->textStrokeThickness; $offsetY++) {
                        if ($offsetX !== 0 || $offsetY !== 0) {
                            $image->draw()->text($line, new Font($this->font_path, $fontSize, $this->textStrokeColor), new Point($x + $offsetX, $y + $offsetY));
                        }
                    }
                }
            }

            // Zeichnet den Text auf das Bild an der berechneten Position
            $image->draw()->text($line, $font, new Point($x, $y));
            $y += $fontSize;  // Verschiebt die Y-Position für die nächste Zeile nach unten
        }

        return $image;
    }
}
