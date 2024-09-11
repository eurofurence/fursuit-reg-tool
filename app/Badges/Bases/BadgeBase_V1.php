<?php

namespace App\Badges\Bases;

use Imagine\Gd\Font;
use Imagine\Gd\Imagine;
use App\Models\Badge\Badge;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Palette\Color\ColorInterface;

class BadgeBase_V1
{
    protected ColorInterface $text_color;
    protected Badge $badge;
    protected Imagine $imagine;

    // Standart Werte
    protected int $height_px = 648;
    protected int $width_px = 1024;
    protected string $font_color = '#FFFFFF';
    protected string $font_path = '';
    protected string $file_format = 'png';

    public function init()
    {
        $this->imagine = new Imagine();

        $new_text_color = new RGB();
        $this->text_color = $new_text_color->color($this->font_color);
    }


    /**
     * This PHP function returns a Font object with a specified size and font path, using a default font path if none is
     * provided.
     *
     * @param int size The `size` parameter in the `getFont` function is an integer that represents the font size to be
     * used for the Font object. It specifies the size of the font in points.
     * @param string font_path The `font_path` parameter in the `getFont` function is a string that represents the path to the
     * font file. It is optional and can be provided when calling the function. If a `font_path` is provided, the function
     * will create a `Font` object using the specified font file
     *
     * @return Font A Font object is being returned. The Font object is created with the specified size, font path (if
     * provided), and text color.
     */
    public function getFont(int $size, ?string $font_path = null): Font
    {
        if (!empty($font_path)) {
            return new Font(resource_path($font_path), $size, $this->text_color);
        }

        return new Font(resource_path($this->font_path), $size, $this->text_color);
    }

    /**
     * The getHeight function in PHP returns the height in pixels.
     *
     * @return int The function `getHeight()` is returning the constant `HEIGHT_PX` which is of type integer.
     */
    public function getHeight(): int
    {
        return $this->height_px;
    }

    /**
     * The getWidth function in PHP returns the width in pixels.
     *
     * @return int The function `getWidth()` is returning the constant `WIDTH_PX` which is of type integer.
     */
    public function getWidth(): int
    {
        return $this->width_px;
    }

    /**
     * The getFileFormat function in PHP returns a string representing the file format.
     *
     * @return string The method `getFileFormat()` is returning the constant `FILE_FORMAT` as a string.
     */
    public function getFileFormat(): string
    {
        return $this->file_format;
    }

    /**
     * The function `addLetterSpacing` in PHP adds spacing between each letter in a given text string.
     *
     * @param string text The `text` parameter is a string that represents the text you want to add letter spacing to.
     * @param int spacing The `spacing` parameter in the `addLetterSpacing` function determines how many spaces should be
     * added between each character in the input text. By default, the spacing is set to 1, meaning there will be one space
     * between each character.
     * @param string spacer The `spacer` parameter in the `addLetterSpacing` function is used to specify the character or
     * characters that will be inserted between each letter in the input text. By default, a single space character is used
     * as the spacer. However, you can customize this spacer by providing a different character or string
     *
     * @return string The input text with additional spacing between each character.
     */
    public function addLetterSpacing(string $text, int $spacing = 1, string $spacer = ' ')
    {
        return implode($spacer, str_split($text)) . str_repeat($spacer, $spacing - 1);
    }
}
