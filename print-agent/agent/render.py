"""Turn a badge PDF into bitmaps the Windows print path can push at a card.

Laravel renders every badge to PDF server side; the agent never draws artwork.
All this module does is rasterise that PDF at the resolution the card printer
actually has, and hand the raw pixels to `printing.print_bitmap()`.

Rendering is done with pypdfium2, which bundles its own pdfium binary. That
matters: the alternatives (Ghostscript, SumatraPDF, the Acrobat COM interface)
all mean a second install on a Windows 7 machine somebody has to maintain at a
convention, and one of them silently rasterising at the wrong size is exactly
the class of problem this rewrite exists to remove.

pypdfium2 is imported lazily so the module, and its tests, work on a developer
machine that has no PDF stack installed.

**Resolution.** The Zebra ZXP Series 9 images a card at 300 dpi. Rendering above
that produces pixels the printer throws away, at quadratic cost in memory on a
machine with 4 GB of RAM, so `DEFAULT_DPI` is 300 and anything above `MAX_DPI`
is clamped rather than honoured.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from typing import List, Optional, Tuple

MM_PER_INCH = 25.4

# PDF user space is 72 units to the inch; pdfium's `scale` is relative to that.
PDF_POINTS_PER_INCH = 72.0

# CR80, the standard ID-card size the ZXP9 takes.
CARD_WIDTH_MM = 85.6
CARD_HEIGHT_MM = 54.0

# The over-bleed area the badge renderers actually draw to, so artwork runs off
# the edge of the card instead of leaving a white hairline. Matches
# App\Badges\ImagePreparer: 86.7 x 54.86 mm is 1024 x 648 px at 300 dpi.
BLEED_WIDTH_MM = 86.7
BLEED_HEIGHT_MM = 54.86

DEFAULT_DPI = 300

# Above this we are only making the file bigger. Kept above 300 so a future
# printer, or a deliberate high-resolution proof, is still possible.
MAX_DPI = 600

# Bytes per pixel by pdfium bitmap mode. GDI wants BGR order; the RGB modes are
# listed so a wrong-byte-order render is recognised and rejected loudly by the
# print path rather than printing a badge with blue fursuits.
BITS_PER_PIXEL = {
    "BGR": 24,
    "BGRA": 32,
    "BGRX": 32,
    "RGB": 24,
    "RGBA": 32,
    "L": 8,
}

_WHITE = (255, 255, 255, 255)


class RenderError(RuntimeError):
    """A PDF could not be turned into pixels."""


@dataclass
class Page:
    """One rasterised page, as raw pixel rows.

    Deliberately not a PIL image: the only consumer is `StretchDIBits`, which
    wants exactly this (a byte buffer, a stride and a pixel format), and pulling
    Pillow into the print path would add a dependency for nothing.
    """

    width: int
    height: int
    stride: int
    mode: str
    data: bytes
    dpi: int = DEFAULT_DPI
    index: int = 0

    @property
    def size(self) -> Tuple[int, int]:
        return (self.width, self.height)

    @property
    def aspect_ratio(self) -> float:
        return self.width / float(self.height) if self.height else 0.0

    def bits_per_pixel(self) -> int:
        try:
            return BITS_PER_PIXEL[self.mode.upper()]
        except KeyError:
            raise RenderError("Unsupported bitmap mode %r" % self.mode)

    def packed_stride(self) -> int:
        """Row length with no padding beyond GDI's 4-byte alignment rule."""
        return ((self.width * self.bits_per_pixel() + 31) // 32) * 4


def mm_to_pixels(mm: float, dpi: int = DEFAULT_DPI) -> int:
    return int(round(mm / MM_PER_INCH * dpi))


def card_pixel_size(dpi: int = DEFAULT_DPI) -> Tuple[int, int]:
    """Nominal CR80 card in pixels. 1011 x 638 at 300 dpi."""
    return (mm_to_pixels(CARD_WIDTH_MM, dpi), mm_to_pixels(CARD_HEIGHT_MM, dpi))


def bleed_pixel_size(dpi: int = DEFAULT_DPI) -> Tuple[int, int]:
    """The over-bleed area the badge artwork is drawn to. 1024 x 648 at 300 dpi."""
    return (mm_to_pixels(BLEED_WIDTH_MM, dpi), mm_to_pixels(BLEED_HEIGHT_MM, dpi))


def clamp_dpi(dpi: int) -> int:
    """Keep the requested resolution somewhere useful.

    Clamping rather than raising is on purpose: a silly value in a config file
    at 3am should print a card, not stop the queue.
    """
    try:
        dpi = int(dpi)
    except (TypeError, ValueError):
        raise RenderError("DPI must be a number, got %r" % (dpi,))

    if dpi <= 0:
        raise RenderError("DPI must be positive, got %d" % dpi)

    return min(dpi, MAX_DPI)


def scale_for_dpi(dpi: int = DEFAULT_DPI) -> float:
    """pdfium's render scale, relative to PDF user space at 72 dpi."""
    return clamp_dpi(dpi) / PDF_POINTS_PER_INCH


def pdf_available() -> bool:
    """Whether this machine can rasterise a PDF at all."""
    try:
        import pypdfium2  # noqa: F401
    except ImportError:
        return False
    return True


def _pdfium():
    try:
        import pypdfium2  # type: ignore
    except ImportError as error:
        raise RenderError(
            "pypdfium2 is not installed, so badge PDFs cannot be rasterised. "
            "Install the pinned version from requirements.txt."
        ) from error
    return pypdfium2


def _open(path: str):
    if not path:
        raise RenderError("No PDF path given.")

    if not os.path.exists(path):
        raise RenderError("PDF not found: %s" % path)

    pdfium = _pdfium()

    try:
        return pdfium.PdfDocument(path)
    except Exception as error:
        # A truncated download is the likely cause, and the worker needs to be
        # able to tell that apart from "the printer is broken".
        raise RenderError("Could not open PDF %s: %s" % (path, error)) from error


def _close(*objects) -> None:
    for obj in objects:
        closer = getattr(obj, "close", None)
        if closer is None:
            continue
        try:
            closer()
        except Exception:
            continue


def _render_one(page, scale: float):
    """Rasterise a single pdfium page.

    `prefer_bgrx` asks for 32 bits per pixel, whose rows are 4-byte aligned by
    definition and so need no repacking before GDI sees them. Older pypdfium2
    builds do not know the argument; 24bpp is repacked in the print path anyway.
    """
    try:
        return page.render(scale=scale, fill_color=_WHITE, prefer_bgrx=True)
    except TypeError:
        return page.render(scale=scale, fill_color=_WHITE)


def page_count(path: str) -> int:
    document = _open(str(path))
    try:
        return len(document)
    finally:
        _close(document)


def render_pdf(path: str, dpi: int = DEFAULT_DPI, pages: Optional[List[int]] = None) -> List[Page]:
    """Rasterise a badge PDF.

    Returns one `Page` per PDF page, in order, each carrying its own pixel
    dimensions. A badge is a single page; a duplex badge is two, front then
    back, and they must be printed as one spooler document or the driver will
    put them on separate cards.

    `dpi` defaults to the printer's own 300 and is clamped at `MAX_DPI`.
    """
    dpi = clamp_dpi(dpi)
    scale = dpi / PDF_POINTS_PER_INCH

    document = _open(str(path))
    rendered: List[Page] = []

    try:
        wanted = range(len(document)) if pages is None else pages

        for index in wanted:
            page = document[index]
            bitmap = None

            try:
                bitmap = _render_one(page, scale)

                rendered.append(Page(
                    width=int(bitmap.width),
                    height=int(bitmap.height),
                    stride=int(getattr(bitmap, "stride", 0)),
                    mode=str(getattr(bitmap, "mode", "BGR")).upper(),
                    # Copied, because the buffer belongs to the bitmap and dies
                    # with it a couple of lines below.
                    data=bytes(bitmap.buffer),
                    dpi=dpi,
                    index=index,
                ))
            except RenderError:
                raise
            except Exception as error:
                raise RenderError(
                    "Could not render page %d of %s: %s" % (index, path, error)
                ) from error
            finally:
                _close(bitmap, page)
    finally:
        _close(document)

    if not rendered:
        raise RenderError("PDF %s produced no pages." % path)

    return rendered
