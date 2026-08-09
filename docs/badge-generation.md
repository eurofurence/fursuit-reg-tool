# Badge generation

How a badge becomes artwork. Read this before adding a badge type for a new event or changing
positioning, fonts or image handling in `app/Badges/`.

- Badges are rendered as PDFs using custom badge classes in `app/Badges/`
- Each badge type (e.g. `EF28_Badge`, `EF29_Badge`) extends `BadgeBase_V1` (in `Bases/`) and defines
  positioning/fonts; reusable field/layout helpers live in `app/Badges/Components/`
- PDF generation uses `mpdf/mpdf`; images are processed with Intervention/Imagine and stored on S3
- QR codes are generated for the Catch-Em-All game integration (see `CATCH.md` in the repo root)

Rendering is server side and one-shot: `GenerateBadgePrintFileJob` writes the print file and its
content hash. Downscaling of attendee uploads before they reach the PDF is covered in
[`printing.md`](./printing.md#print-files).

## The output is a golden master

A badge that printed at a past convention has to still render identically today, so the renderers
are held to byte-for-byte output. Anything that touches `app/Badges/` must be diffed pixel by pixel
against renders of real badges - both sides, all three designs, JPEG and PNG uploads, with and
without a catch code - before it is merged. `tests/Feature/Printing/BadgeRenderingTest.php` covers
the properties that can be asserted without a fixture library; the pixel diff is a manual step.

Three things turned out to be load-bearing for that equality, each found by breaking it:

- **Canvas initialisation.** Imagine fills a new canvas with transparent white and registers a
  transparent colour index. A plain `imagecreatetruecolor()` is opaque black, so every transparent
  pixel of the artwork blends onto black and the card renders black with only the photo and text
  visible. `BadgeAssets::canvas()` is the correct construction.
- **Alpha blending on writes.** Imagine's drawer blends by default, so a partly transparent photo
  pixel is composited onto the green underneath rather than replacing it. The soft edge of a cut-out
  PNG comes out opaque and slightly green-tinted. `Greenscreen::apply()` keeps blending on.
- **The alpha round trip.** Imagine converts 0-127 alpha to an opacity percentage and back, and the
  scales do not divide evenly - alpha 7 returns as 8. `Greenscreen::roundTripAlpha()` reproduces the
  loss deliberately.

## Do not read pixels through Imagine

`Imagine\Image\Palette\RGB` memoises every colour it is ever given in a `protected static $colors`
array. It is static, so it belongs to the process: freeing the image, unsetting the renderer and
forcing a GC cycle all leave it in place. `getColorAt()` mints one `Color` per pixel read, so the
old greenscreen loop added 28,000-57,000 permanently cached objects per badge, measured at 20-30MB
each with no plateau.

`PrepareBadgePrintBatchJob` renders a whole run inside one process. It exhausted its 1GB at around
the fortieth card, and because the run then became visible to the queue again, a second worker
re-served it and failed it with "has been attempted too many times". The same badge rendered
repeatedly leaked nothing - its colours were already cached - which is what made this hard to see.

So pixel work is raw GD (`imagecolorat` / `imagesetpixel`), in `app/Badges/Greenscreen.php`. Ints,
no objects, no cache. It is also ~19x faster. `BadgeRenderingTest` asserts the colour cache stays
flat across renders and fails against the old implementation.

## Where the time goes

Per side, the artwork is a background, a greenscreen overlay with the attendee photo dropped into
its green window, and text fields. Two rules keep that affordable:

- Static artwork is decoded once per process by `app/Badges/BadgeAssets.php` and copied per badge.
  The source PNGs are 2300x1500; re-decoding one per card cost ~75ms each.
- The back of the card is rendered only when `dual_side_print` is set. It used to be rendered for
  every badge and then thrown away on the single-sided ones.

Together with the raw-GD composite this took a card from ~1.5-2.7s to ~250-400ms.
