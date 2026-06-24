<?php

namespace App\Badges;

use App\Badges\Bases\BadgeBase_V2;
use App\Badges\Components\TextAlignment;
use App\Badges\Components\TextField;
use App\Interfaces\BadgeInterface_V2;
use App\Models\Badge\Badge;
use Illuminate\Support\Facades\Storage;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Mpdf\Mpdf;

// Documentation: https://imagine.readthedocs.io/en/stable/

class EF30_Badge extends BadgeBase_V2 implements BadgeInterface_V2
{
    public function __construct()
    {
        $this->init();

        // Overwrite default values
        $this->height_px = 648;
        $this->width_px = 1024;
        $this->font_color = '#FFFFFF';
        $this->font_path = resource_path('badges/ef30/fonts/Zhurzh.ttf');
        $this->file_format = 'png';
    }

    public function getPng(Badge $badge, bool $flip = false): string
    {
        // Mandatory reference
        $this->badge = $badge;

        $size = new Box($this->width_px, $this->height_px);

        if ($this->badge->fursuit->catch_em_all && ! empty($this->badge->fursuit->catch_code)) {
            $badge_object = $this->addBaseLayerWithCode($size);
            $this->addGreenscreenLayer($badge_object, $size);
            $this->addTextLayerWithCode($badge_object);
        } else {
            $badge_object = $this->addBaseLayerWithoutCode($size);
            $this->addGreenscreenLayer($badge_object, $size);
            $this->addTextLayerWithoutCode($badge_object);
        }

        // Rotate image 180 degrees
        if ($flip) {
            $badge_object->rotate(180);
        }

        return $badge_object->get($this->file_format);
    }

    public function getPdf(Badge $badge): string
    {
        // Convert Image blob to PDF using mPDF
        $options = [
            'mode' => 'utf-8',
            'format' => [86.7, 54.86],
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
        ];
        $mpdf = new Mpdf($options);
        $mpdf->img_dpi = 300;
        $mpdf->dpi = 300;
        $mpdf->imageVars['badgeImageFront'] = $this->getPng($badge, 0);
        $mpdf->imageVars['badgeImageBack'] = $this->getPng($badge, 1);
        // Add Page 1
        $mpdf->AddPageByArray($options);
        $mpdf->Image('var:badgeImageFront', 0, 0, $options['format'][0], $options['format'][1], 'png', '', true, false);
        if ($badge->dual_side_print) {
            $mpdf->AddPageByArray($options);
            $mpdf->Image('var:badgeImageBack', 0, 0, $options['format'][0], $options['format'][1], 'png', '', true, false);
        }

        return $mpdf->Output($badge->id.'.pdf', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function addBaseLayerWithCode(Box $size): ImageInterface
    {
        // Add background
        $image = $this->imagine->open(resource_path('badges/ef30/images/with_code.png'));
        $image->resize($size);

        return $image;
    }

    private function addBaseLayerWithoutCode(Box $size): ImageInterface
    {
        // Add background
        $image = $this->imagine->open(resource_path('badges/ef30/images/without_code.png'));
        $image->resize($size);

        return $image;
    }

    private static ?array $greenBoundingBox = null;

    private function addGreenscreenLayer(ImageInterface $badge_object, Box $size): void
    {
        // Load the overlay image in which green is to be replaced
        $overlayImage = $this->imagine->open(resource_path('badges/ef30/images/greenscreeen.png'));

        // Adjust to badge size
        $overlayImage->resize($size);

        // Load the image to be used as a replacement for green
        $replacementImageUrl = Storage::temporaryUrl($this->badge->fursuit->image, now()->addMinutes(1));
        $replacementImage = $this->imagine->open($replacementImageUrl);

        if (self::$greenBoundingBox === null) {
            $minX = $size->getWidth();
            $maxX = 0;
            $minY = $size->getHeight();
            $maxY = 0;
            $found = false;

            for ($x = 35; $x < $size->getWidth() - 600; $x++) {
                for ($y = 10; $y < $size->getHeight() - 90; $y++) {
                    $color = $overlayImage->getColorAt(new Point($x, $y));
                    $red = $color->getValue(ColorInterface::COLOR_RED);
                    $green = $color->getValue(ColorInterface::COLOR_GREEN);
                    $blue = $color->getValue(ColorInterface::COLOR_BLUE);

                    if (abs($red - 147) <= 10 && abs($green - 192) <= 10 && abs($blue - 152) <= 10) {
                        if ($x < $minX) $minX = $x;
                        if ($x > $maxX) $maxX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                        $found = true;
                    }
                }
            }
            self::$greenBoundingBox = $found ? ['minX' => $minX, 'maxX' => $maxX, 'minY' => $minY, 'maxY' => $maxY] : false;
        }

        if (self::$greenBoundingBox === false) {
            $badge_object->paste($overlayImage, new Point(0, 0));
            return;
        }

        $box = self::$greenBoundingBox;
        $targetWidth = $box['maxX'] - $box['minX'] + 1;
        $targetHeight = $box['maxY'] - $box['minY'] + 1;

        // Resize replacement image to fit the bounding box
        $replacementImage->resize(new Box($targetWidth, $targetHeight));

        $replacementSize = $replacementImage->getSize();

        // Check whether the file is a PNG
        $isPng = false;
        if (! empty($replacementImage)) {
            $imageInfo = getimagesize($replacementImageUrl);
            $isPng = ($imageInfo[2] === IMAGETYPE_PNG);
        }

        // Replace green areas in the overlay image with the replacement image
        for ($x = $box['minX']; $x <= $box['maxX']; $x++) {
            for ($y = $box['minY']; $y <= $box['maxY']; $y++) {
                // Get the color of the pixel in the overlay image
                $color = $overlayImage->getColorAt(new Point($x, $y));
                $red = $color->getValue(ColorInterface::COLOR_RED);
                $green = $color->getValue(ColorInterface::COLOR_GREEN);
                $blue = $color->getValue(ColorInterface::COLOR_BLUE);

                // Define the area for "green" (with tolerance)
                if (abs($red - 147) <= 10 && abs($green - 192) <= 10 && abs($blue - 152) <= 10) {

                    // Map the current pixel to the replacement image
                    $replacementX = $x - $box['minX'];
                    $replacementY = $y - $box['minY'];

                    if (
                        $replacementX >= 0 && $replacementX < $replacementSize->getWidth() &&
                        $replacementY >= 0 && $replacementY < $replacementSize->getHeight()
                    ) {
                        $replacementColor = $replacementImage->getColorAt(new Point($replacementX, $replacementY));

                        if ($isPng && $replacementColor->getAlpha() <= 80) {
                            // If transparent, keep the overlay pixel
                            continue;
                        }

                        $overlayImage->draw()->dot(new Point($x, $y), $replacementColor);
                    }
                }
            }
        }

        // Add the edited overlay image as a second layer to the base image
        $badge_object->paste($overlayImage, new Point(0, 0));
    }

    private function addTextLayerWithCode(ImageInterface $badge_object): void
    {
        // Texts
        $text_attendee_id = $this->badge->custom_id;
        $text_name = $this->badge->fursuit->name;
        $text_species = $this->badge->fursuit->species->name;
        $text_code = $this->badge->fursuit->catch_code;

        // Fonts and color definitions
        $font_path = $this->font_path; // Path to the font file

        // Create color palette - Text color
        $palette = new RGB;
        $font_color = $palette->color($this->font_color);

        // Position of the texts in the image
        $position_attendee_id = new Point(
            30, // X-Position (adapted)
            10 // Y-Position
        );

        $position_species = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 100 // Y-Position
        );

        $position_name = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 220 // Y-Position
        );

        $position_catch_code = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 335, // Y-Position
        );

        // Create TextField objects and draw text on the image
        new TextField(
            $text_attendee_id,
            321, // Width of the text field
            67, // Height of the text field
            16, // Minimum font size
            25, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_attendee_id,
            TextAlignment::LEFT, // Right-aligned alignment
            1, // Maximum number of lines
        );

        new TextField(
            $text_species,
            321, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_species,
            TextAlignment::LEFT, // Centered alignment
            1, // Maximum number of lines
        );

        new TextField(
            $text_name,
            321, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_name,
            TextAlignment::LEFT, // Centered alignment
            1, // Maximum number of lines
        );

        new TextField(
            $text_code,
            321, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_catch_code,
            TextAlignment::LEFT, // Centered alignment
            1, // Maximum number of lines
        );

        // The text is drawn automatically when the TextField object is created.
    }

    private function addTextLayerWithoutCode(ImageInterface $badge_object): void
    {
        // Texts
        $text_attendee_id = $this->badge->custom_id;
        $text_name = $this->badge->fursuit->name;
        $text_species = $this->badge->fursuit->species->name;
        $text_code = $this->badge->fursuit->catch_code;

        // Fonts and color definitions
        $font_path = $this->font_path; // Path to the font file

        // Create color palette - Text color
        $palette = new RGB;
        $font_color = $palette->color($this->font_color);

        // Position of the texts in the image
        $position_attendee_id = new Point(
            30, // X-Position (adapted)
            10 // Y-Position
        );

        $position_species = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 100 // Y-Position
        );

        $position_name = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 220 // Y-Position
        );

        $position_catch_code = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 50 // Y-Position
        );

        // Create TextField objects and draw text on the image
        new TextField(
            $text_attendee_id,
            321, // Width of the text field
            67, // Height of the text field
            16, // Minimum font size
            25, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_attendee_id,
            TextAlignment::LEFT, // Right-aligned alignment
            1, // Maximum number of lines
        );

        new TextField(
            $text_species,
            321, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_species,
            TextAlignment::LEFT, // Centered alignment
            1, // Maximum number of lines
        );

        new TextField(
            $text_name,
            321, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_name,
            TextAlignment::LEFT, // Centered alignment
            1, // Maximum number of lines
        );

        // The text is drawn automatically when the TextField object is created.
    }
}
