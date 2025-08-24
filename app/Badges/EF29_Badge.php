<?php

namespace App\Badges;

use App\Badges\Bases\BadgeBase_V1;
use App\Badges\Components\TextAlignment;
use App\Badges\Components\TextField;
use App\Interfaces\BadgeInterface;
use App\Models\Badge\Badge;
use Illuminate\Support\Facades\Storage;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Mpdf\Mpdf;

// Documentation: https://imagine.readthedocs.io/en/stable/

class EF29_Badge extends BadgeBase_V1 implements BadgeInterface
{
    public function __construct()
    {
        $this->init();

        // Overwrite default values
        $this->height_px = 648;
        $this->width_px = 1024;
        $this->font_color = '#FFFFFF';
        $this->font_path = resource_path('badges/ef29/fonts/ORBITRON_MEDIUM.ttf');
        $this->file_format = 'png';
    }

    public function getPng(Badge $badge, bool $flip = false): string
    {
        // Mandatory reference
        $this->badge = $badge;

        $size = new Box($this->width_px, $this->height_px);

        $badge_objekt = $this->addFirstLayer($size);
        $this->addSecondLayer($badge_objekt, $size);
        $this->addThirdLayer($badge_objekt);

        if ($this->badge->fursuit->catch_em_all == true && ! empty($this->badge->fursuit->catch_code)) {
            $this->addFourthLayer($badge_objekt, $size);
        }

        // Rotate image 180 degrees
        if ($flip) {
            $badge_objekt->rotate(180);
        }

        return $badge_objekt->get($this->file_format);
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

    private function addFirstLayer(Box $size)
    {
        // Add background
        $image = $this->imagine->open(resource_path('badges/ef29/images/first_layer_space_layout_main.png'));
        $image->resize($size);

        return $image;
    }

    private function addSecondLayer(ImageInterface $badge_object, Box $size)
    {
        // Load the overlay image in which green is to be replaced
        $overlayImage = $this->imagine->open(resource_path('badges/ef29/images/second_layer_green_screen.png'));

        // Adjust to badge size
        $overlayImage->resize($size);

        // Load the image to be used as a replacement for green
        $replacementImageUrl = Storage::temporaryUrl($this->badge->fursuit->image, now()->addMinutes(1));
        $replacementImage = $this->imagine->open($replacementImageUrl);
        $replacementImage->resize(new Box(350, 455));

        $replacementSize = $replacementImage->getSize();

        // Define the offsets for the shift
        $xOffset = 30; // For example, move it 30 pixels to the right
        $yOffset = 35; // For example, move it down by 35 pixels

        // Check whether the file is a PNG
        $isPng = false;
        if (! empty($replacementImage)) {
            $imageInfo = getimagesize($replacementImageUrl);
            $isPng = ($imageInfo[2] === IMAGETYPE_PNG);
        }

        // Replace green areas in the overlay image with the replacement image
        for ($x = 35; $x < $size->getWidth() - 600; $x++) {
            for ($y = 10; $y < $size->getHeight() - 150; $y++) {
                // Get the color of the pixel in the overlay image
                $color = $overlayImage->getColorAt(new Point($x, $y));

                // Get the RGB values of the pixel
                $red = $color->getValue(ColorInterface::COLOR_RED);
                $green = $color->getValue(ColorInterface::COLOR_GREEN);
                $blue = $color->getValue(ColorInterface::COLOR_BLUE);

                // Define the area for "green"
                if ($red == 134 && $green == 194 && $blue == 148) {
                    // Calculate the position in the replacementImage taking into account the offsets
                    $replacementX = $x - $xOffset;
                    $replacementY = $y - $yOffset;

                    // Check whether the calculated coordinates are within the replacementImage
                    if (
                        $replacementX >= 0 && $replacementX < $replacementSize->getWidth() &&
                        $replacementY >= 0 && $replacementY < $replacementSize->getHeight()
                    ) {

                        $replacementColor = $replacementImage->getColorAt(new Point($replacementX, $replacementY));

                        if ($isPng) {
                            if ($replacementColor->getAlpha() <= 80) {
                                $replacementColor = $badge_object->getColorAt(new Point($replacementX + 30, $replacementY + 35));
                            }
                        }

                        $overlayImage->draw()->dot(new Point($x, $y), $replacementColor);
                    }
                }
            }
        }

        // Add the edited overlay image as a second layer to the base image
        $badge_object->paste($overlayImage, new Point(0, 0));
    }

    private function addThirdLayer(ImageInterface $badge_object)
    {
        // Texts
        $text_attendee_id = $this->badge->custom_id;
        $text_name = $this->badge->fursuit->name;
        $text_species = $this->badge->fursuit->species->name;

        // Fonts and color definitions
        $font_path = $this->font_path; // Path to the font file

        // Create color palette - Text color
        $palette = new RGB;
        $font_color = $palette->color($this->font_color);

        // Position of the texts in the image
        $position_attendee_id = new Point(
            $this->width_px - 608, // X-Position (adapted)
            191 // Y-Position
        );

        $position_species = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 142 // Y-Position
        );

        $position_name = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 246 // Y-Position
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

    private function addFourthLayer(ImageInterface $badge_object, Box $size)
    {
        // Add catch em all field
        // Load the overlay image in which green is to be replaced
        $overlayImage = $this->imagine->open(resource_path('badges/ef29/images/fourth_layer_catch_em_all.png'));

        // Customize to badge size
        $overlayImage->resize($size);

        // Textposition
        $position = new Point($this->width_px - 595, $this->height_px - 143);

        // Create color palette - Text color
        $palette = new RGB;
        $font_color = $palette->color($this->font_color);

        // Add to badge
        $badge_object->paste($overlayImage, new Point(0, 0));

        new TextField(
            $this->addLetterSpacing(strtoupper($this->badge->fursuit->catch_code), 1),
            500, // Width of the text field
            90, // Height of the text field
            15, // Minimum font size
            40, // Start font size
            $this->font_path,
            $font_color,
            $badge_object,
            $position,
            TextAlignment::CENTER, // Centered alignment
            2, // Maximum number of lines
        );
    }
}
