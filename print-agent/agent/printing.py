"""Windows spooler access: enumerate printers, send a card, watch the job.

Two jobs live here. Enumerating installed printers, so the operator can pick
which one is the card printer and which is the receipt printer. And the print
path itself: a rasterised page (see `render.py`) goes to the driver over a
printer DC, and the spooler job id that comes back is polled until the job
leaves the queue.

**What the spooler is worth knowing.** Very little. The ZXP driver reports
success regardless of what the hardware did, so everything in here is
spooler-level truth only: it says what Windows believes about a job, never that
a card physically exists. A `printed` status means the driver accepted the data,
which is precisely the signal the old QZ system mistook for a finished badge
while the printer sat jammed. Real completion comes from the printer's own job
table over SNMP (`zebra.py`), and from the camera. This module is one input of
three, and the weakest.

Every pywin32 import is lazy and everything degrades on non-Windows, so the
agent can be developed and reviewed on a Mac without pretending a printer
exists.
"""

from __future__ import annotations

import ctypes
import sys
import time
from typing import Callable, List, Sequence

from .render import BITS_PER_PIXEL

IS_WINDOWS = sys.platform.startswith("win")


class PrintError(RuntimeError):
    """Something went wrong on the way to the printer driver."""


class SpoolerUnavailable(PrintError):
    """There is no Windows spooler here (wrong OS, or pywin32 missing)."""


# --- Job status vocabulary --------------------------------------------------
# Small on purpose. These describe the spooler's opinion of a job and nothing
# more; the server's completion sources are a separate, stronger vocabulary.

SPOOLING = "spooling"
PRINTING = "printing"
PRINTED = "printed"
DELETED = "deleted"
ERROR = "error"
PAPER_OUT = "paper_out"
OFFLINE = "offline"
PAUSED = "paused"
GONE = "gone"

# Statuses the job will never move on from, so polling can stop.
TERMINAL_STATUSES = {PRINTED, DELETED, GONE}

# JOB_STATUS_* from winspool.h. Spelled out rather than imported from
# win32print so the mapping can be tested on any OS.
JOB_STATUS_PAUSED = 0x00000001
JOB_STATUS_ERROR = 0x00000002
JOB_STATUS_DELETING = 0x00000004
JOB_STATUS_SPOOLING = 0x00000008
JOB_STATUS_PRINTING = 0x00000010
JOB_STATUS_OFFLINE = 0x00000020
JOB_STATUS_PAPEROUT = 0x00000040
JOB_STATUS_PRINTED = 0x00000080
JOB_STATUS_DELETED = 0x00000100
JOB_STATUS_BLOCKED_DEVQ = 0x00000200
JOB_STATUS_USER_INTERVENTION = 0x00000400
JOB_STATUS_RESTART = 0x00000800
JOB_STATUS_COMPLETE = 0x00001000
JOB_STATUS_RETAINED = 0x00002000
JOB_STATUS_RENDERING_LOCALLY = 0x00004000

# Checked in order, so the most serious condition in a combined bitmask wins.
# Windows raises JOB_STATUS_ERROR alongside the specific cause, so the specific
# causes are tested first and plain ERROR is the catch-all underneath them.
# `printed` sits below every fault flag: a job flagged printed *and* errored has
# not produced a card we would trust.
STATUS_FLAGS = [
    (JOB_STATUS_DELETING | JOB_STATUS_DELETED, DELETED),
    (JOB_STATUS_OFFLINE, OFFLINE),
    (JOB_STATUS_PAPEROUT, PAPER_OUT),
    (JOB_STATUS_USER_INTERVENTION | JOB_STATUS_BLOCKED_DEVQ | JOB_STATUS_ERROR, ERROR),
    (JOB_STATUS_PAUSED, PAUSED),
    (JOB_STATUS_PRINTED | JOB_STATUS_COMPLETE | JOB_STATUS_RETAINED, PRINTED),
    (JOB_STATUS_PRINTING, PRINTING),
    (JOB_STATUS_SPOOLING | JOB_STATUS_RENDERING_LOCALLY | JOB_STATUS_RESTART, SPOOLING),
]

# --- GDI --------------------------------------------------------------------

DIB_RGB_COLORS = 0
SRCCOPY = 0x00CC0020
HALFTONE = 4

HORZRES = 8
VERTRES = 10

# Pixel orders GDI understands. pdfium's RGB modes are the same pixels in the
# wrong order and would print with the red and blue channels swapped, so they
# are rejected rather than quietly mangled.
GDI_MODES = ("BGR", "BGRA", "BGRX")


class BITMAPINFOHEADER(ctypes.Structure):
    """winGDI.h BITMAPINFOHEADER.

    Plain ctypes types rather than ctypes.wintypes, because importing wintypes
    fails outright on macOS and this module must stay importable there.
    """

    _fields_ = [
        ("biSize", ctypes.c_uint32),
        ("biWidth", ctypes.c_int32),
        ("biHeight", ctypes.c_int32),
        ("biPlanes", ctypes.c_uint16),
        ("biBitCount", ctypes.c_uint16),
        ("biCompression", ctypes.c_uint32),
        ("biSizeImage", ctypes.c_uint32),
        ("biXPelsPerMeter", ctypes.c_int32),
        ("biYPelsPerMeter", ctypes.c_int32),
        ("biClrUsed", ctypes.c_uint32),
        ("biClrImportant", ctypes.c_uint32),
    ]


# --- Enumeration ------------------------------------------------------------


def spooler_available() -> bool:
    if not IS_WINDOWS:
        return False
    try:
        import win32print  # noqa: F401
    except ImportError:
        return False
    return True


def list_printers() -> List[str]:
    """Installed Windows printer names.

    Returns everything the OS knows about, including nonsense like
    "Microsoft Print to PDF". The operator picks the real ones in the UI; unlike
    the QZ integration this replaces, nothing is registered with the server
    until they do.
    """
    if not spooler_available():
        return []

    import win32print

    flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS

    return [printer[2] for printer in win32print.EnumPrinters(flags, None, 1)]


def default_printer() -> str:
    if not spooler_available():
        return ""

    import win32print

    try:
        return win32print.GetDefaultPrinter()
    except Exception:
        return ""


def _require_spooler() -> None:
    """Fail with something an operator can act on, not an ImportError."""
    if spooler_available():
        return

    if not IS_WINDOWS:
        raise SpoolerUnavailable(
            "Printing needs Windows; this machine is %s. Printer enumeration "
            "and status polling are stubbed out here for development." % sys.platform
        )

    raise SpoolerUnavailable(
        "pywin32 is not installed, so the Windows spooler cannot be reached. "
        "Install the pinned version from requirements.txt."
    )


# --- Bitmap plumbing --------------------------------------------------------


def dib_bytes(page) -> bytes:
    """Pixel rows packed the way `StretchDIBits` insists on reading them.

    A DIB has no stride field: rows are assumed to be padded to a 4-byte
    boundary and nothing else. pdfium usually hands back exactly that, but not
    always, and a stride mismatch shears the image diagonally instead of
    failing, which is the kind of bug that gets noticed after 400 cards.
    """
    mode = str(getattr(page, "mode", "")).upper()

    if mode not in GDI_MODES:
        raise PrintError(
            "Bitmap mode %r cannot be sent to GDI; expected one of %s. An RGB "
            "mode means the render used the wrong byte order."
            % (mode or "(none)", ", ".join(GDI_MODES))
        )

    width = int(page.width)
    height = int(page.height)
    packed = ((width * BITS_PER_PIXEL[mode] + 31) // 32) * 4

    data = bytes(page.data)
    stride = int(getattr(page, "stride", 0)) or packed

    if stride < packed:
        raise PrintError(
            "Bitmap stride %d is shorter than a %d-pixel %s row (%d bytes)."
            % (stride, width, mode, packed)
        )

    if len(data) < stride * height:
        raise PrintError(
            "Bitmap is truncated: %d bytes for %d rows of %d."
            % (len(data), height, stride)
        )

    if stride == packed and len(data) == packed * height:
        return data

    return b"".join(data[y * stride:y * stride + packed] for y in range(height))


def fit_rect(source: Sequence, target: Sequence) -> tuple:
    """Largest centred (x, y, w, h) of `source`'s shape that fits `target`.

    The driver's page and the rendered card are both meant to be CR80, but the
    driver page depends on whatever the operator set in the Windows driver.
    Fitting rather than stretching means a mismatch shows up as a white margin,
    not as a badge with a squashed fursuit name on it.
    """
    source_width, source_height = int(source[0]), int(source[1])
    target_width, target_height = int(target[0]), int(target[1])

    if min(source_width, source_height, target_width, target_height) <= 0:
        raise PrintError("Cannot fit %s into %s." % (tuple(source), tuple(target)))

    scale = min(target_width / float(source_width), target_height / float(source_height))

    width = max(1, int(round(source_width * scale)))
    height = max(1, int(round(source_height * scale)))

    return ((target_width - width) // 2, (target_height - height) // 2, width, height)


def _gdi32():
    """gdi32 with argument types declared.

    Without argtypes ctypes passes handles as 32-bit ints, which truncates them
    on 64-bit Windows and turns every call into a silent no-op.
    """
    gdi32 = ctypes.windll.gdi32

    gdi32.GetDeviceCaps.argtypes = [ctypes.c_void_p, ctypes.c_int]
    gdi32.GetDeviceCaps.restype = ctypes.c_int

    gdi32.SetStretchBltMode.argtypes = [ctypes.c_void_p, ctypes.c_int]
    gdi32.SetStretchBltMode.restype = ctypes.c_int

    gdi32.SetBrushOrgEx.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.c_int, ctypes.c_void_p]
    gdi32.SetBrushOrgEx.restype = ctypes.c_int

    gdi32.StretchDIBits.argtypes = [
        ctypes.c_void_p,
        ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_int,
        ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_int,
        ctypes.c_void_p, ctypes.c_void_p, ctypes.c_uint, ctypes.c_uint,
    ]
    gdi32.StretchDIBits.restype = ctypes.c_int

    return gdi32


def _draw(hdc: int, page) -> None:
    """Blit one page onto an open printer DC, scaled to the printable area."""
    gdi32 = _gdi32()

    bits = dib_bytes(page)
    width = int(page.width)
    height = int(page.height)
    mode = str(page.mode).upper()

    header = BITMAPINFOHEADER()
    header.biSize = ctypes.sizeof(BITMAPINFOHEADER)
    header.biWidth = width
    # Negative height means a top-down DIB, which is the row order pdfium
    # produces. The alternative is copying the whole buffer just to flip it.
    header.biHeight = -height
    header.biPlanes = 1
    header.biBitCount = BITS_PER_PIXEL[mode]
    header.biCompression = 0  # BI_RGB
    header.biSizeImage = len(bits)

    handle = ctypes.c_void_p(hdc)

    printable = (gdi32.GetDeviceCaps(handle, HORZRES), gdi32.GetDeviceCaps(handle, VERTRES))
    x, y, dest_width, dest_height = fit_rect((width, height), printable)

    # Averages pixels when scaling instead of dropping them, which matters on
    # small text at the edge of legibility, like a badge number.
    gdi32.SetStretchBltMode(handle, HALFTONE)
    gdi32.SetBrushOrgEx(handle, 0, 0, None)

    result = gdi32.StretchDIBits(
        handle,
        x, y, dest_width, dest_height,
        0, 0, width, height,
        ctypes.c_char_p(bits),
        ctypes.byref(header),
        DIB_RGB_COLORS,
        SRCCOPY,
    )

    # GDI_ERROR. The driver rejected the bitmap; carrying on would spool a blank
    # card, which is worse than failing the job.
    if result == 0 or result == -1:
        raise PrintError("The printer driver rejected the page bitmap.")


# --- Printing ---------------------------------------------------------------


def print_bitmap(printer_name: str, bitmap, job_name: str = "Badge") -> int:
    """Send one rasterised page to a printer. Returns the spooler job id.

    The job id is the only handle we ever get on the job, so it is captured from
    `StartDoc` and returned even when the rest of the call went fine: without it
    `job_status()` has nothing to poll and the card can only be confirmed by
    firmware or by the camera.

    A driver that declines to give us one returns 0. That is not a failure of
    the print, only of our ability to watch it.
    """
    return print_pages(printer_name, [bitmap], job_name)


def print_pages(printer_name: str, bitmaps: Sequence, job_name: str = "Badge") -> int:
    """Send several pages as one spooler document. Returns the job id.

    Duplex badges are two pages that must arrive as a single document, or the
    driver treats them as two cards and the back of one badge lands on the front
    of the next.

    The DC route is used rather than raw `StartDocPrinter`/`WritePrinter`: raw
    mode expects data the device already understands, and what we have is a
    bitmap that only the ZXP GDI driver knows how to turn into ribbon passes.
    """
    _require_spooler()

    if not printer_name:
        raise PrintError("No printer name given.")

    if not bitmaps:
        raise PrintError("Nothing to print.")

    import win32ui

    try:
        dc = win32ui.CreateDC()
        dc.CreatePrinterDC(printer_name)
    except Exception as error:
        raise PrintError("Could not open printer %r: %s" % (printer_name, error)) from error

    job_id = 0

    try:
        try:
            # StartDoc hands back the spooler job id, and this is the only
            # moment we can learn it.
            job_id = int(dc.StartDoc(job_name) or 0)
        except Exception as error:
            raise PrintError("Printer %r refused the job: %s" % (printer_name, error)) from error

        try:
            for bitmap in bitmaps:
                dc.StartPage()
                _draw(dc.GetSafeHdc(), bitmap)
                dc.EndPage()

            dc.EndDoc()
        except Exception:
            # Leave nothing half-spooled: a partial document on a card printer
            # is a card with half a badge on it.
            try:
                dc.AbortDoc()
            except Exception:
                pass
            raise
    finally:
        try:
            dc.DeleteDC()
        except Exception:
            pass

    return job_id


# --- Job status -------------------------------------------------------------


def classify_job_status(flags: int) -> str:
    """Map a Windows `JOB_STATUS_*` bitmask to our small vocabulary.

    Spooler truth only. `printed` means Windows finished handing the job to the
    driver, and the ZXP driver reports success whatever the hardware did, so it
    is **not** evidence that a card exists. Treating it as evidence is the exact
    mistake that lost badges under QZ Tray. The printer's own job table over
    SNMP, and the camera, are what confirm a card.

    Windows sets several flags at once, so the most serious wins: a job that is
    both deleting and printing is going away, and a job that is both errored and
    offline reports the specific cause rather than the generic error.

    A bitmask of zero is a job sitting in the queue with nothing happening to it
    yet, which reads as `spooling`.
    """
    try:
        flags = int(flags)
    except (TypeError, ValueError):
        return SPOOLING

    for mask, status in STATUS_FLAGS:
        if flags & mask:
            return status

    return SPOOLING


def job_status(printer_name: str, job_id: int) -> str:
    """Ask the spooler what it thinks of a job. See `classify_job_status`.

    Returns `gone` when the job is no longer in the queue. **`gone` is not
    success.** Windows drops a finished job from the queue within a second or
    two unless the printer is set to retain documents, so a job that vanished
    and a job that printed look identical from here. The previous system mapped
    a spooler-deleted job to success and recorded jammed cards as printed.
    """
    _require_spooler()

    if not printer_name:
        raise PrintError("No printer name given.")

    try:
        job_id = int(job_id)
    except (TypeError, ValueError):
        raise PrintError("Job id %r is not a number." % (job_id,))

    if job_id <= 0:
        # We never got a handle on this job, so there is nothing to look up.
        return GONE

    import win32print

    try:
        handle = win32print.OpenPrinter(printer_name)
    except Exception as error:
        raise PrintError("Could not open printer %r: %s" % (printer_name, error)) from error

    try:
        try:
            info = win32print.GetJob(handle, job_id, 1)
        except Exception:
            # The job left the queue, one way or another.
            return GONE
    finally:
        try:
            win32print.ClosePrinter(handle)
        except Exception:
            pass

    return classify_job_status(info.get("Status", 0) if hasattr(info, "get") else 0)


def _poll_until_terminal(
    read_status: Callable[[], str],
    timeout: float,
    poll_interval: float,
    now: Callable[[], float] = time.monotonic,
    sleep: Callable[[float], None] = time.sleep,
) -> str:
    """Poll until the job stops moving or the clock runs out.

    Split out from `wait_for_job` so the timing can be tested without a printer.
    """
    deadline = now() + max(0.0, float(timeout))
    interval = max(0.01, float(poll_interval))

    status = read_status()

    while status not in TERMINAL_STATUSES:
        remaining = deadline - now()

        if remaining <= 0:
            return status

        sleep(min(interval, remaining))
        status = read_status()

    return status


def wait_for_job(
    printer_name: str,
    job_id: int,
    timeout: float = 120.0,
    poll_interval: float = 1.0,
) -> str:
    """Poll a job until it leaves the queue, and return its last status.

    Terminal statuses are `printed`, `deleted` and `gone`. Everything else can
    still change: a printer that is offline or out of cards comes back when
    somebody attends to it, so those keep the job in the queue and keep us
    waiting.

    On timeout the last observed status is returned, so a non-terminal return
    value means the wait expired rather than the job finishing. The caller
    decides what to do; this function never asserts a card was produced, because
    it cannot know that. See `classify_job_status`.
    """
    _require_spooler()

    return _poll_until_terminal(
        lambda: job_status(printer_name, job_id),
        timeout=timeout,
        poll_interval=poll_interval,
    )
