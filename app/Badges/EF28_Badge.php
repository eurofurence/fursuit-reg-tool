<?php

namespace App\Badges;

use App\Badges\Bases\BadgeBase_V1;
use App\Badges\Components\TextAlignment;
use App\Badges\Components\TextField;
use App\Interfaces\BadgeInterface;
use App\Models\Badge\Badge;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

// Documentation: https://imagine.readthedocs.io/en/stable/

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

        if ($this->badge->fursuit->catch_em_all == true && ! empty($this->badge->fursuit->catch_code)) {
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
        // Add Page 1
        $mpdf->AddPageByArray($options);
        $mpdf->Image('var:badgeImageFront', 0, 0, $options['format'][0], $options['format'][1], 'png', '', true, false);
        // Only render the back if it is going to be printed; it used to be rendered for
        // every badge and then discarded on the single-sided ones.
        if ($badge->dual_side_print) {
            $mpdf->imageVars['badgeImageBack'] = $this->getPng($badge, 1);
            $mpdf->AddPageByArray($options);
            $mpdf->Image('var:badgeImageBack', 0, 0, $options['format'][0], $options['format'][1], 'png', '', true, false);
        }

        return $mpdf->Output($badge->id.'.pdf', Destination::STRING_RETURN);
    }

    private function addFirstLayer(Box $size)
    {
        // Add background
        return BadgeAssets::image(
            resource_path('badges/ef28/images/first_layer_bg_purple.png'),
            $size->getWidth(),
            $size->getHeight(),
        );
    }

    private function addSecondLayer(ImageInterface $badge_object, Box $size)
    {
        // Load the image to be used as a replacement for green.
        //
        // ImagePreparer downloads once and scales on the way in, rather than
        // pulling a multi-megabyte upload over HTTP and decoding it at full
        // resolution to draw into a 380x507 box.
        $prepared = (new ImagePreparer($this->imagine))
            ->prepare($this->badge->fursuit->image, 380, 507);

        // EF28 never looked at the photo's alpha, so a transparent PNG pixel is drawn as it
        // is. Kept that way deliberately: these cards were printed in 2022.
        $greenscreen = new Greenscreen(
            overlayPath: resource_path('badges/ef28/images/second_layer_green_screen.png'),
            key: [134, 194, 148],
            tolerance: 0,
            left: 35,
            top: 100,
            rightInset: 610,
            bottomInset: 45,
        );

        $greenscreen->apply(
            base: $badge_object,
            photo: $prepared->gd(),
            offsetX: 35,
            offsetY: 100,
        );
    }

    private function addThirdLayer(ImageInterface $badge_object, Box $size)
    {
        // Load the overlay image
        $overlayImage = BadgeAssets::image(
            resource_path('badges/ef28/images/third_layer_overlay.png'),
            $size->getWidth(),
            $size->getHeight(),
        );

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
        $palette = new RGB;
        $font_color = $palette->color($this->font_color);
        // Create color palette - Frame
        $border_color = $palette->color('#9579aa');

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
        $overlayImage = BadgeAssets::image(
            resource_path('badges/ef28/images/fifth_layer_catch_em_all.png'),
            $size->getWidth(),
            $size->getHeight(),
        );

        // Textposition
        $position = new Point($this->width_px - 558, $this->height_px - 182);

        // Create color palette - Text color
        $palette = new RGB;
        $font_color = $palette->color($this->font_color);
        // Create color palette - Frame
        $border_color = $palette->color('#9579aa');

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
