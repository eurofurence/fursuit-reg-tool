<?php

namespace App\Badges;

use Imagine\Image\Box;
use Imagine\Image\Point;
use App\Models\Badge\Badge;
use Illuminate\Http\Response;
use Imagine\Image\Palette\RGB;
use Imagine\Image\ImageInterface;
use App\Badges\Bases\BadgeBase_V1;
use App\Interfaces\BadgeInterface;
use App\Badges\Components\TextField;
use Illuminate\Support\Facades\Storage;
use App\Badges\Components\TextAlignment;
use Imagine\Image\Palette\Color\ColorInterface;

#Documentation: https://imagine.readthedocs.io/en/stable/

class EF28_Badge extends BadgeBase_V1 implements BadgeInterface
{
    public function __construct()
    {
        $this->init();

        // Standard Werte überschreiben
        $this->height_px = 638;
        $this->width_px = 1013;
        $this->font_color = '#FFFFFF';
        $this->font_path = 'badges/ef28/fonts/upcib.ttf';
        $this->file_format = 'png';
    }

    public function getImage(Badge $badge): Response
    {
        // Pflicht Verweis
        $this->badge = $badge->with(['fursuit', 'fursuit.species'])->first();

        $size = new Box($this->width_px, $this->height_px);

        $badge_objekt = $this->addFirstLayer($size);
        $this->addSecondLayer($badge_objekt, $size);
        $this->addThirdLayer($badge_objekt, $size);
        $this->addFourthLayer($badge_objekt);

        if ($this->badge->fursuit->catch_em_all == true) {
            $this->addFifthLayer($badge_objekt, $size);
        }

        $image_data = $badge_objekt->get($this->file_format);
        return response($image_data, 200)->header('Content-Type', 'image/png');
    }

    private function addFirstLayer(Box $size)
    {
        // Hintergrund hinzufügen
        $image = $this->imagine->open(resource_path('badges/ef28/images/first_layer_bg.png'));
        $image->resize($size);
        return $image;
    }

    private function addSecondLayer(ImageInterface $badge_object, Box $size)
    {
        // Lade das Overlay-Bild, in dem Grün ersetzt werden soll
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/second_layer_green_screen.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Lade das Bild, das als Ersatz für Grün verwendet werden soll
        $replacementImage = $this->imagine->open(Storage::temporaryUrl($this->badge->fursuit->image, now()->addMinutes(1)));
        $replacementImage->resize(new Box(375, 493));

        $replacementSize = $replacementImage->getSize();

        // Definiere die Offsets für die Verschiebung
        $xOffset = 30; // Beispielsweise um 30 Pixel nach rechts verschieben
        $yOffset = 100; // Beispielsweise um 50 Pixel nach unten verschieben

        // Ersetze grüne Bereiche im Overlay-Bild durch das Ersatzbild
        for ($x = 35; $x < $size->getWidth() - 600; $x++) {
            for ($y = 100; $y < $size->getHeight() - 38; $y++) {
                // Hole die Farbe des Pixels im Overlay-Bild
                $color = $overlayImage->getColorAt(new Point($x, $y));

                // Hole die RGB-Werte des Pixels
                $red = $color->getValue(ColorInterface::COLOR_RED);
                $green = $color->getValue(ColorInterface::COLOR_GREEN);
                $blue = $color->getValue(ColorInterface::COLOR_BLUE);

                // Definiere den Bereich für "Grün"
                if ($red == 134 && $green == 194 && $blue == 148) {
                    // Berechne die Position im replacementImage unter Berücksichtigung der Offsets
                    $replacementX = $x - $xOffset;
                    $replacementY = $y - $yOffset;

                    // Prüfe, ob die berechneten Koordinaten innerhalb des replacementImage liegen
                    if (
                        $replacementX >= 0 && $replacementX < $replacementSize->getWidth() &&
                        $replacementY >= 0 && $replacementY < $replacementSize->getHeight()
                    ) {

                        // Ersetze das grüne Pixel durch das entsprechende Pixel aus dem Ersatzbild
                        $replacementColor = $replacementImage->getColorAt(new Point($replacementX, $replacementY));
                        $overlayImage->draw()->dot(new Point($x, $y), $replacementColor);
                    }
                }
            }
        }

        // Füge das bearbeitete Overlay-Bild als zweiten Layer zum Basisbild hinzu
        $badge_object->paste($overlayImage, new Point(0, 0));
    }


    private function addThirdLayer(ImageInterface $badge_object, Box $size)
    {
        // Lade das Overlay-Bild
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/third_layer_overlay.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Zum Badge hinzufügen
        $badge_object->paste($overlayImage, new Point(0, 0));
    }

    private function addFourthLayer(ImageInterface $badge_object)
    {
        // Texte
        $text_attendee_id = $this->badge->fursuit->user->attendee_id;
        $text_name = $this->badge->fursuit->name;
        $text_species = $this->badge->fursuit->species->name;

        // Schriftarten und Farbdefinitionen
        $font_path = resource_path($this->font_path); // Pfad zur Schriftartdatei

        // Farbpalette erstellen
        $palette = new RGB();
        $font_color = $palette->color($this->font_color);

        // Position der Texte im Bild
        $position_attendee_id = new Point(
            $this->width_px - 355, // X-Position (angepasst)
            0 // Y-Position
        );

        $position_species = new Point(
            $this->width_px - 321 - 160, // X-Position (angepasst für die Breite der Textbox)
            $this->height_px - 67 - 213 // Y-Position
        );

        $position_name = new Point(
            $this->width_px - 321 - 160, // X-Position (angepasst für die Breite der Textbox)
            $this->height_px - 67 - 340 // Y-Position
        );

        // TextField-Objekte erstellen und Text auf das Bild zeichnen
        new TextField(
            $text_attendee_id,
            321, // Breite des Textfeldes
            67, // Höhe des Textfeldes
            16, // Minimale Schriftgröße
            25, // Start-Schriftgröße
            $font_path,
            $font_color,
            $badge_object,
            $position_attendee_id,
            TextAlignment::RIGHT, // Rechtsbündige Ausrichtung
            1 // Maximale Anzahl von Zeilen
        );

        new TextField(
            $text_species,
            321, // Breite des Textfeldes
            77, // Höhe des Textfeldes
            10, // Minimale Schriftgröße
            50, // Start-Schriftgröße
            $font_path,
            $font_color,
            $badge_object,
            $position_species,
            TextAlignment::CENTER, // Zentrierte Ausrichtung
            2 // Maximale Anzahl von Zeilen
        );

        new TextField(
            $text_name,
            321, // Breite des Textfeldes
            77, // Höhe des Textfeldes
            10, // Minimale Schriftgröße
            50, // Start-Schriftgröße
            $font_path,
            $font_color,
            $badge_object,
            $position_name,
            TextAlignment::CENTER, // Zentrierte Ausrichtung
            2 // Maximale Anzahl von Zeilen
        );

        // Der Text wird automatisch gezeichnet, wenn das TextField-Objekt erstellt wird.
    }


    private function addFifthLayer(ImageInterface $badge_object, Box $size)
    {
        // Catch em all Feld hinzufügen
        // Lade das Overlay-Bild, in dem Grün ersetzt werden soll
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/fifth_layer_catch_em_all.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Textposition
        $position = new Point($this->width_px - 435, $this->height_px - 150);

        // Zum Badge hinzufügen
        $badge_object->paste($overlayImage, new Point(0, 0));
        $badge_object->draw()->text($this->addLetterSpacing(strtoupper($this->badge->fursuit->catch_em_all_code), 2), $this->getFont(65, 'badges/ef28/fonts/upcib.ttf'), $position);
    }
}
