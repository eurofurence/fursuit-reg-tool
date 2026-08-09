<?php

namespace App\Badges;

/**
 * What a badge does with a photo pixel that is (near enough) transparent.
 *
 * Only reached for PNG uploads; a JPEG has no alpha to consider. The three renderers each
 * made a different choice here and all three are kept, because changing one would change
 * cards that have already been printed.
 */
enum GreenscreenTransparency
{
    /** Leave the greenscreen pixel where it is. EF30: transparent areas print green. */
    case KeepOverlay;

    /** Take the pixel from the layer underneath instead. EF29: transparent areas show the background. */
    case TakeFromBase;

    /** Do not look at alpha at all. EF28: the photo pixel is drawn whatever its alpha. */
    case Ignore;
}
