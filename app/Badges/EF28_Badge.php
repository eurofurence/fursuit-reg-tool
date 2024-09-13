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
use Mpdf\Mpdf;

#Documentation: https://imagine.readthedocs.io/en/stable/

class EF28_Badge extends BadgeBase_V1 implements BadgeInterface
{
    public function __construct()
    {
        $this->init();

        // Overwrite default values
        $this->height_px = 648;
        $this->width_px = 1024;
        $this->font_color = '#FFFFFF';
        $this->font_path = 'badges/ef28/fonts/HEMIHEAD.TTF';
        $this->file_format = 'png';
    }

    public function getPng(Badge $badge, bool $flip = false): string
    {
        // Mandatory reference
        $this->badge = $badge;

        $size = new Box($this->width_px, $this->height_px);

        $badge_objekt = $this->addFirstLayer($size);
        $this->addSecondLayer($badge_objekt, $size);
        $this->addThirdLayer($badge_objekt, $size);
        $this->addFourthLayer($badge_objekt);

        if ($this->badge->fursuit->catch_em_all == true && !empty($this->badge->fursuit->catch_code)) {
            $this->addFifthLayer($badge_objekt, $size);
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
        return $mpdf->Output($badge->id . '.pdf', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function addFirstLayer(Box $size)
    {
        // Add background
        $image = $this->imagine->open(resource_path('badges/ef28/images/first_layer_bg_purple.png'));
        $image->resize($size);
        return $image;
    }

    private function addSecondLayer(ImageInterface $badge_object, Box $size)
    {
        // Load the overlay image in which green is to be replaced
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/second_layer_green_screen.png'));

        // Adjust to badge size
        $overlayImage->resize($size);

        // cLoad the image to be used as a replacement for green
        $replacementImage = $this->imagine->open(Storage::temporaryUrl($this->badge->fursuit->image, now()->addMinutes(1)));
        $replacementImage->resize(new Box(380, 507));

        $replacementSize = $replacementImage->getSize();

        // Define the offsets for the shift
        $xOffset = 35; // For example, move it 30 pixels to the right
        $yOffset = 100; // For example, move it down by 100 pixels

        // Replace green areas in the overlay image with the replacement image
        for ($x = 35; $x < $size->getWidth() - 610; $x++) {
            for ($y = 100; $y < $size->getHeight() - 45; $y++) {
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

                        // Replace the green pixel with the corresponding pixel from the replacement image
                        $replacementColor = $replacementImage->getColorAt(new Point($replacementX, $replacementY));
                        $overlayImage->draw()->dot(new Point($x, $y), $replacementColor);
                    }
                }
            }
        }

        // Add the edited overlay image as a second layer to the base image
        $badge_object->paste($overlayImage, new Point(0, 0));
    }


    private function addThirdLayer(ImageInterface $badge_object, Box $size)
    {
        // Load the overlay image
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/third_layer_overlay.png'));

        // Adjust to badge size
        $overlayImage->resize($size);

        // Add to badge
        $badge_object->paste($overlayImage, new Point(0, 0));
    }

    private function addFourthLayer(ImageInterface $badge_object)
    {
        // Texts
        $text_attendee_id = $this->badge->custom_id;
        $text_name = $this->badge->fursuit->name;
        $text_species = $this->badge->fursuit->species->name;

        // Fonts and color definitions
        $font_path = resource_path($this->font_path); // Path to the font file

        // Create color palette - Text color
        $palette = new RGB();
        $font_color = $palette->color($this->font_color);
        // Create color palette - Frame
        $border_color = $palette->color("#9579aa");

        // Position of the texts in the image
        $position_attendee_id = new Point(
            $this->width_px - 129, // X-Position (adapted)
            38 // Y-Position
        );

        $position_species = new Point(
            $this->width_px - 321 - 160, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 213 // Y-Position
        );

        $position_name = new Point(
            $this->width_px - 321 - 160, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 339 // Y-Position
        );

        $position_name_label = new Point(
            $this->width_px - 321 - 260, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 361 // Y-Position
        );

        $position_species_label = new Point(
            $this->width_px - 321 - 275, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 232 // Y-Position
        );

        $position_fursuit_badge = new Point(
            $this->width_px - 321 - 230, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 482 // Y-Position
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
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );

        new TextField(
            $text_species,
            321, // Width of the text field
            60, // Height of the text field
            15, // Minimum font size
            50, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_species,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );

        new TextField(
            $text_name,
            321, // Width of the text field
            60, // Height of the text field
            15, // Minimum font size
            50, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_name,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );

        new TextField(
            'Name:',
            321, // Width of the text field
            90, // Height of the text field
            15, // Minimum font size
            25, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_name_label,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );

        new TextField(
            'Species:',
            321, // Width of the text field
            90, // Height of the text field
            15, // Minimum font size
            22, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_species_label,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );

        new TextField(
            'Fursuit Badge',
            500, // Width of the text field
            90, // Height of the text field
            15, // Minimum font size
            55, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_fursuit_badge,
            TextAlignment::CENTER, // Centered alignment
            2, // Maximum number of lines
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );

        // The text is drawn automatically when the TextField object is created.
    }


    private function addFifthLayer(ImageInterface $badge_object, Box $size)
    {
        // Add catch em all field
        // Load the overlay image in which green is to be replaced
        $overlayImage = $this->imagine->open(resource_path('badges/ef28/images/fifth_layer_catch_em_all.png'));

        // Customize to badge size
        $overlayImage->resize($size);

        // Textposition
        $position = new Point($this->width_px - 558, $this->height_px - 182);

        // Create color palette - Text color
        $palette = new RGB();
        $font_color = $palette->color($this->font_color);
        // Create color palette - Frame
        $border_color = $palette->color("#9579aa");

        // Add to badge
        $badge_object->paste($overlayImage, new Point(0, 0));

        new TextField(
            $this->addLetterSpacing(strtoupper($this->badge->fursuit->catch_code), 1),
            500, // Width of the text field
            90, // Height of the text field
            15, // Minimum font size
            60, // Start font size
            resource_path('badges/ef28/fonts/upcib.ttf'),
            $font_color,
            $badge_object,
            $position,
            TextAlignment::CENTER, // Centered alignment
            2, // Maximum number of lines
            textStrokeThickness: 1,
            textStrokeColor: $border_color
        );
    }
}
