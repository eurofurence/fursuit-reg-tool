<?php

namespace App\Badges;

use Imagine\Image\Box;
use Imagine\Image\Point;
use App\Models\Badge\Badge;
use Illuminate\Http\Response;
use Imagine\Image\ImageInterface;
use App\Badges\Bases\BadgeBase_V1;
use App\Interfaces\BadgeInterface;
use Illuminate\Support\Facades\Storage;
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
        $this->font_path = 'badges/ef28/fonts/dream_mma.ttf';
        $this->file_format = 'png';
    }

    public function getImage(Badge $badge): Response
    {
        // Pflicht Verweis
        $this->badge = $badge;

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

    private function addSecondLayer(ImageInterface $badge, Box $size)
    {
        // Lade das Overlay-Bild, in dem Grün ersetzt werden soll
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/second_layer_green_screen.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Lade das Bild, das als Ersatz für Grün verwendet werden soll
        $replacementImage = $this->imagine->open(Storage::temporaryUrl($this->badge->fursuit->image, now()->addMinutes(1)));
        $replacementImage->resize(new Box(405, 595));

        $replacementSize = $replacementImage->getSize();

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
                    // Ersetze das grüne Pixel durch das entsprechende Pixel aus dem Ersatzbild
                    if ($x < $replacementSize->getWidth() && $y < $replacementSize->getHeight()) {
                        $replacementColor = $replacementImage->getColorAt(new Point($x, $y));
                        $overlayImage->draw()->dot(new Point($x, $y), $replacementColor);
                    }
                }
            }
        }

        // Füge das bearbeitete Overlay-Bild als zweiten Layer zum Basisbild hinzu
        $badge->paste($overlayImage, new Point(0, 0));
    }

    private function addThirdLayer(ImageInterface $badge, Box $size)
    {
        // Lade das Overlay-Bild
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/third_layer_overlay.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Zum Badge hinzufügen
        $badge->paste($overlayImage, new Point(0, 0));
    }

    private function addFourthLayer(ImageInterface $badge)
    {
        // Texte
        $text_attendee_id = $this->badge->fursuit->user->attendee_id;

        // Schriftarten
        $font_attendee_id = $this->getFont(16);
        $font_name = $this->getFont(24);

        // Position der Texte im Bild
        $textBox = $font_attendee_id->box($text_attendee_id);
        $position_attendee_id = new Point(
            $this->width_px - $textBox->getWidth() - 20, // X-Position (rechtsbündig mit 20px Abstand vom Rand)
            10 // X-Position (10px Abstand zur Oberkante)
        );


        // Zum Badge hinzufügen
        #Attendee ID
        $badge->draw()->text($text_attendee_id, $font_attendee_id, $position_attendee_id);
    }

    private function addFifthLayer(ImageInterface $badge, Box $size) {
        // Catch em all Feld hinzufügen
        // Lade das Overlay-Bild, in dem Grün ersetzt werden soll
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/fifth_layer_catch_em_all.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Textposition
        $position = new Point($this->height_px - 440, $this->width_px - 123);

        // Zum Badge hinzufügen
        $badge->paste($overlayImage, new Point(0, 0));
        $badge->draw()->text($this->addLetterSpacing(strtolower($this->badge->fursuit->catch_em_all_code), 2), $this->getFont(35, $this->font_path), $position);
    }
}
