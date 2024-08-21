<?php

namespace App\Badges;

use Imagine\Image\Box;
use Imagine\Gd\Imagine;
use Imagine\Image\Point;
use Illuminate\Http\Response;
use Imagine\Image\Palette\RGB;
use App\Models\Fursuit\Fursuit;
use Imagine\Image\ImageInterface;
use Illuminate\Support\Facades\Storage;
use Imagine\Image\Palette\Color\ColorInterface;

class EF28_Badge
{
    private const HEIGHT_PX = 638;
    private const WIDTH_PX = 1013;

    private $imagine;

    private Fursuit $fursuit;

    public function __construct()
    {
        $this->imagine = new Imagine;
    }

    public function getImage(Fursuit $fursuit)
    {
        $this->fursuit = $fursuit;

        $size = new Box(self::WIDTH_PX, self::HEIGHT_PX);

        $badge = $this->addFirstLayer($size);
        $this->addSecondLayer($badge, $size);
        $this->addThirdLayer($badge, $size);


        $image_data = $badge->get('png');
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

        //Auf Badge Größe anpassen
        $overlayImage->resize($size);

        // Lade das Bild, das als Ersatz für Grün verwendet werden soll
        $replacementImage = $this->imagine->open(Storage::temporaryUrl($this->fursuit->image, now()->addMinutes(1)));
        $replacementSize = $replacementImage->getSize();

        // Ersetze grüne Bereiche im Overlay-Bild durch das Ersatzbild
        for ($x = 0; $x < $size->getWidth(); $x++) {
            for ($y = 0; $y < $size->getHeight(); $y++) {
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
        // Lade das Overlay-Bild, in dem Grün ersetzt werden soll
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/third_layer_overlay.png'));

        // Auf Badge Größe anpassen
        $overlayImage->resize($size);

        $badge->paste($overlayImage, new Point(0, 0));
    }

    private function addFourthLayer()
    {

    }
}
