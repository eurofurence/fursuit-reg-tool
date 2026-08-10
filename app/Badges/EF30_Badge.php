<?php

namespace App\Badges;

use App\Badges\Bases\BadgeBase_V2;
use App\Badges\Components\TextAlignment;
use App\Badges\Components\TextField_v2 as TextField;
use App\Interfaces\BadgeInterface_V2;
use App\Models\Badge\Badge;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

// Documentation: https://imagine.readthedocs.io/en/stable/

class EF30_Badge extends BadgeBase_V2 implements BadgeInterface_V2
{
    public function __construct()
    {
        $this->init();

        // Overwrite default values
        // 1024x648 is the ZXP Series 9 edge-to-edge dot grid (86.6 x 54.8 mm at
        // its native 300 dpi), so the composite is one pixel per printer dot.
        $this->height_px = 648;
        $this->width_px = 1024;
        $this->font_color = '#FFFFFF';
        $this->font_path = resource_path('badges/ef30/fonts/classic_market.ttf');
        // PNG, not JPEG. Imagine passes no jpeg_quality, so GD falls back to
        // quality 75 and bakes visible blocking into the pastel gradients of
        // the EF30 artwork. Nothing downstream resamples it away, because the
        // image already sits at the printer's own resolution. EF28 and EF29
        // both use PNG; this was the odd one out.
        $this->file_format = 'png';

        $this->text_filter_active = false;
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
        // Add Page 1
        $mpdf->AddPageByArray($options);
        $mpdf->Image('var:badgeImageFront', 0, 0, $options['format'][0], $options['format'][1], 'png', '', true, false);
        // The back is rendered only if it is going to be printed. It used to be rendered
        // unconditionally and then dropped, which doubled the cost of every single-sided
        // badge - a whole second card's worth of image work thrown away per order.
        if ($badge->dual_side_print) {
            $mpdf->imageVars['badgeImageBack'] = $this->getPng($badge, 1);
            $mpdf->AddPageByArray($options);
            $mpdf->Image('var:badgeImageBack', 0, 0, $options['format'][0], $options['format'][1], 'png', '', true, false);
        }

        return $mpdf->Output($badge->id.'.pdf', Destination::STRING_RETURN);
    }

    private function filterText(string $text): string
    {
        if ($this->text_filter_active) {
            $search = ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü'];
            $replace = ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue'];

            return str_replace($search, $replace, $text);
        }

        return $text;
    }

    private function addBaseLayerWithCode(Box $size): ImageInterface
    {
        // Add background
        return BadgeAssets::image(
            resource_path('badges/ef30/images/with_code.png'),
            $size->getWidth(),
            $size->getHeight(),
        );
    }

    private function addBaseLayerWithoutCode(Box $size): ImageInterface
    {
        // Add background
        return BadgeAssets::image(
            resource_path('badges/ef30/images/without_code.png'),
            $size->getWidth(),
            $size->getHeight(),
        );
    }

    private function greenscreen(): Greenscreen
    {
        // Tolerance of 10 around the EF30 green, over the left-hand window the photo sits in.
        return new Greenscreen(
            overlayPath: resource_path('badges/ef30/images/greenscreen.png'),
            key: [147, 192, 152],
            tolerance: 10,
            left: 35,
            top: 10,
            rightInset: 600,
            bottomInset: 90,
        );
    }

    private function addGreenscreenLayer(ImageInterface $badge_object, Box $size): void
    {
        $greenscreen = $this->greenscreen();
        $mask = $greenscreen->mask($size->getWidth(), $size->getHeight());

        // Load the image to be used as a replacement for green.
        //
        // ImagePreparer downloads once and caps the decode at badge size. This
        // used to pull the full-size attendee upload over HTTP twice, once here
        // and again for the getimagesize() type check further down, and decode a
        // multi-megapixel photo in full. The photo is then brought down to the
        // green window itself, which is where it actually lands.
        $prepared = (new ImagePreparer($this->imagine))
            ->prepare($this->badge->fursuit->image, $size->getWidth(), $size->getHeight());

        if ($mask['points'] === []) {
            // No green in the artwork: the overlay goes on untouched.
            $greenscreen->apply($badge_object, $prepared->gd(), 0, 0);

            return;
        }

        $prepared->image->resize(new Box(
            $mask['maxX'] - $mask['minX'] + 1,
            $mask['maxY'] - $mask['minY'] + 1,
        ));

        // The photo fills the green window exactly, so it is offset by the window's corner.
        // A transparent pixel leaves the green showing, which is what EF30 has always done.
        $greenscreen->apply(
            base: $badge_object,
            photo: $prepared->gd(),
            offsetX: $mask['minX'],
            offsetY: $mask['minY'],
            onTransparent: GreenscreenTransparency::KeepOverlay,
            photoHasAlpha: $prepared->isPng,
        );
    }

    private function addTextLayerWithCode(ImageInterface $badge_object): void
    {
        // Texts
        $text_attendee_id = $this->filterText($this->badge->custom_id);
        $text_name = $this->filterText($this->badge->fursuit->name);
        $text_species = $this->filterText($this->badge->fursuit->species->name);
        $text_code = $this->filterText($this->badge->fursuit->catch_code);

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
            350, // Width of the text field
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
            320, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_species,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
        );

        new TextField(
            $text_name,
            310, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_name,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
        );

        new TextField(
            $text_code,
            300, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_catch_code,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
        );

        // The text is drawn automatically when the TextField object is created.
    }

    private function addTextLayerWithoutCode(ImageInterface $badge_object): void
    {
        // Texts
        $text_attendee_id = $this->filterText($this->badge->custom_id);
        $text_name = $this->filterText($this->badge->fursuit->name);
        $text_species = $this->filterText($this->badge->fursuit->species->name);

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
            $this->height_px - 67 - 133 // Y-Position
        );

        $position_name = new Point(
            $this->width_px - 321 - 316, // X-Position (adapted for the width of the text box)
            $this->height_px - 67 - 255 // Y-Position
        );

        // Create TextField objects and draw text on the image
        new TextField(
            $text_attendee_id,
            350, // Width of the text field
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
            320, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_species,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
        );

        new TextField(
            $text_name,
            310, // Width of the text field
            42, // Height of the text field
            18, // Minimum font size
            40, // Start font size
            $font_path,
            $font_color,
            $badge_object,
            $position_name,
            TextAlignment::LEFT, // Centered alignment
            2, // Maximum number of lines
        );

        // The text is drawn automatically when the TextField object is created.
    }
}
