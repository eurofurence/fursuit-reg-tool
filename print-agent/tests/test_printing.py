"""The print path, exercised without Windows and without a printer.

Two things are worth pinning down here. The spooler status mapping, because a
combined bitmask that reports the wrong condition is how a jammed card gets
recorded as printed. And the pixel maths, because a card rendered at the wrong
size fails silently: the driver scales it and the badge comes out slightly wrong
for the whole run.

Everything Windows-specific is lazily imported in the modules under test, so
this file runs on a Mac with neither pywin32 nor pypdfium2 installed.
"""

import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import printing, render  # noqa: E402


class FakePage:
    """The shape `print_bitmap` expects, without pdfium."""

    def __init__(self, width, height, mode="BGRX", stride=None, data=None):
        self.width = width
        self.height = height
        self.mode = mode
        bits = render.BITS_PER_PIXEL[mode]
        self.stride = stride if stride is not None else ((width * bits + 31) // 32) * 4
        self.data = data if data is not None else bytes(self.stride * height)


class ImportsWithoutWindowsTest(unittest.TestCase):
    """The agent is written on macOS and run on Windows 7."""

    def test_pywin32_is_not_needed_to_import(self):
        # Importing this file already proved it; these are the entry points
        # that must not have pulled win32print in at module scope.
        for name in ("print_bitmap", "print_pages", "job_status", "wait_for_job"):
            self.assertTrue(callable(getattr(printing, name)), name)

    def test_pypdfium2_is_not_needed_to_import(self):
        for name in ("render_pdf", "page_count", "card_pixel_size"):
            self.assertTrue(callable(getattr(render, name)), name)

    def test_ctypes_structures_are_defined_off_windows(self):
        # A ctypes.wintypes import would blow up here; plain ctypes types do not.
        header = printing.BITMAPINFOHEADER()
        header.biWidth = 1011
        self.assertEqual(header.biWidth, 1011)


@unittest.skipIf(printing.IS_WINDOWS, "describes the non-Windows stub behaviour")
class DegradesOffWindowsTest(unittest.TestCase):
    def test_no_spooler(self):
        self.assertFalse(printing.spooler_available())

    def test_no_printers(self):
        self.assertEqual(printing.list_printers(), [])

    def test_no_default_printer(self):
        self.assertEqual(printing.default_printer(), "")

    def test_printing_raises_something_readable(self):
        # Not an ImportError from three frames down: the UI shows this string.
        with self.assertRaises(printing.SpoolerUnavailable) as caught:
            printing.print_bitmap("Zebra ZXP Series 9", FakePage(1011, 638))

        self.assertIn("Windows", str(caught.exception))

    def test_a_spooler_error_is_a_print_error(self):
        # So callers can catch one class for the whole print path.
        self.assertTrue(issubclass(printing.SpoolerUnavailable, printing.PrintError))

    def test_status_polling_raises_too(self):
        with self.assertRaises(printing.PrintError):
            printing.job_status("Zebra ZXP Series 9", 42)

        with self.assertRaises(printing.PrintError):
            printing.wait_for_job("Zebra ZXP Series 9", 42, timeout=0.01)


class ClassifyJobStatusTest(unittest.TestCase):
    """Windows sets several flags at once; the most serious must win."""

    def test_queued_job_with_no_flags(self):
        self.assertEqual(printing.classify_job_status(0), printing.SPOOLING)

    def test_spooling(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_SPOOLING), printing.SPOOLING
        )

    def test_rendering_locally_is_still_spooling(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_RENDERING_LOCALLY),
            printing.SPOOLING,
        )

    def test_printing(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_PRINTING), printing.PRINTING
        )

    def test_printed(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_PRINTED), printing.PRINTED
        )

    def test_complete_counts_as_printed(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_COMPLETE), printing.PRINTED
        )

    def test_retained_counts_as_printed(self):
        # "Keep printed documents" leaves the job in the queue afterwards.
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_RETAINED), printing.PRINTED
        )

    def test_deleted(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_DELETED), printing.DELETED
        )

    def test_deleting_is_already_deleted_for_our_purposes(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_DELETING), printing.DELETED
        )

    def test_error(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_ERROR), printing.ERROR
        )

    def test_paper_out(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_PAPEROUT), printing.PAPER_OUT
        )

    def test_offline(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_OFFLINE), printing.OFFLINE
        )

    def test_paused(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_PAUSED), printing.PAUSED
        )

    def test_user_intervention_is_an_error(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_USER_INTERVENTION),
            printing.ERROR,
        )

    def test_blocked_device_queue_is_an_error(self):
        self.assertEqual(
            printing.classify_job_status(printing.JOB_STATUS_BLOCKED_DEVQ), printing.ERROR
        )

    def test_offline_beats_the_generic_error_flag(self):
        # Windows raises ERROR alongside the actual cause. Reporting "error" for
        # an unplugged printer tells staff nothing they can act on.
        flags = printing.JOB_STATUS_ERROR | printing.JOB_STATUS_OFFLINE
        self.assertEqual(printing.classify_job_status(flags), printing.OFFLINE)

    def test_paper_out_beats_the_generic_error_flag(self):
        flags = printing.JOB_STATUS_ERROR | printing.JOB_STATUS_PAPEROUT
        self.assertEqual(printing.classify_job_status(flags), printing.PAPER_OUT)

    def test_offline_beats_paper_out(self):
        # A printer we cannot reach may be reporting a stale hopper state.
        flags = printing.JOB_STATUS_OFFLINE | printing.JOB_STATUS_PAPEROUT
        self.assertEqual(printing.classify_job_status(flags), printing.OFFLINE)

    def test_deletion_beats_everything(self):
        flags = (
            printing.JOB_STATUS_DELETING
            | printing.JOB_STATUS_PRINTING
            | printing.JOB_STATUS_ERROR
            | printing.JOB_STATUS_OFFLINE
        )
        self.assertEqual(printing.classify_job_status(flags), printing.DELETED)

    def test_an_errored_printed_job_is_not_printed(self):
        # The whole point: never let a fault flag be outvoted by "printed".
        flags = printing.JOB_STATUS_PRINTED | printing.JOB_STATUS_ERROR
        self.assertEqual(printing.classify_job_status(flags), printing.ERROR)

    def test_a_paused_printed_job_reports_paused(self):
        flags = printing.JOB_STATUS_PRINTED | printing.JOB_STATUS_PAUSED
        self.assertEqual(printing.classify_job_status(flags), printing.PAUSED)

    def test_printing_beats_spooling(self):
        flags = printing.JOB_STATUS_SPOOLING | printing.JOB_STATUS_PRINTING
        self.assertEqual(printing.classify_job_status(flags), printing.PRINTING)

    def test_unknown_flags_do_not_read_as_finished(self):
        # A flag Windows grew after this was written must not look like success.
        self.assertEqual(printing.classify_job_status(0x80000000), printing.SPOOLING)

    def test_garbage_does_not_raise(self):
        self.assertEqual(printing.classify_job_status(None), printing.SPOOLING)

    def test_terminal_statuses(self):
        # `gone` is in here because the job cannot come back, NOT because it
        # succeeded. Windows drops finished jobs from the queue within seconds.
        self.assertEqual(
            printing.TERMINAL_STATUSES,
            {printing.PRINTED, printing.DELETED, printing.GONE},
        )

        for status in (printing.ERROR, printing.OFFLINE, printing.PAPER_OUT,
                       printing.PAUSED, printing.PRINTING, printing.SPOOLING):
            self.assertNotIn(status, printing.TERMINAL_STATUSES)


class WaitLoopTest(unittest.TestCase):
    """The polling loop, driven by a fake clock so it runs instantly."""

    def setUp(self):
        self.slept = []
        self.clock = [0.0]

    def sleep(self, seconds):
        self.slept.append(seconds)
        self.clock[0] += seconds

    def now(self):
        return self.clock[0]

    def wait(self, statuses, timeout=10.0, poll_interval=1.0):
        remaining = list(statuses)

        def read():
            return remaining.pop(0) if len(remaining) > 1 else remaining[0]

        return printing._poll_until_terminal(
            read, timeout=timeout, poll_interval=poll_interval,
            now=self.now, sleep=self.sleep,
        )

    def test_returns_as_soon_as_the_job_finishes(self):
        result = self.wait([printing.SPOOLING, printing.PRINTING, printing.PRINTED])
        self.assertEqual(result, printing.PRINTED)
        self.assertEqual(self.slept, [1.0, 1.0])

    def test_an_already_terminal_job_does_not_sleep(self):
        self.assertEqual(self.wait([printing.PRINTED]), printing.PRINTED)
        self.assertEqual(self.slept, [])

    def test_a_vanished_job_is_terminal(self):
        self.assertEqual(self.wait([printing.SPOOLING, printing.GONE]), printing.GONE)

    def test_a_deleted_job_is_terminal(self):
        self.assertEqual(self.wait([printing.PRINTING, printing.DELETED]), printing.DELETED)

    def test_a_fault_keeps_us_waiting_then_times_out(self):
        # Somebody may yet close the cover, so a fault is not terminal. But the
        # caller has to get an answer eventually.
        result = self.wait([printing.PAPER_OUT], timeout=3.0, poll_interval=1.0)
        self.assertEqual(result, printing.PAPER_OUT)
        self.assertEqual(self.slept, [1.0, 1.0, 1.0])

    def test_the_last_sleep_never_overshoots_the_deadline(self):
        self.wait([printing.PRINTING], timeout=2.5, poll_interval=1.0)
        self.assertEqual(self.slept, [1.0, 1.0, 0.5])

    def test_a_zero_timeout_polls_exactly_once(self):
        result = self.wait([printing.PRINTING], timeout=0.0)
        self.assertEqual(result, printing.PRINTING)
        self.assertEqual(self.slept, [])


class DibPackingTest(unittest.TestCase):
    """GDI reads rows padded to four bytes and nothing else."""

    def test_an_aligned_bitmap_passes_straight_through(self):
        page = FakePage(1011, 638, mode="BGRX")
        self.assertEqual(len(printing.dib_bytes(page)), 1011 * 4 * 638)

    def test_32bpp_rows_need_no_padding(self):
        self.assertEqual(FakePage(1011, 638, mode="BGRX").stride, 1011 * 4)

    def test_24bpp_rows_are_padded_to_four_bytes(self):
        # 1011 * 3 = 3033, which is not a multiple of 4.
        page = FakePage(1011, 638, mode="BGR")
        self.assertEqual(page.stride, 3036)

    def test_slack_at_the_end_of_each_row_is_dropped(self):
        # pdfium is entitled to hand back rows with slack on the end. Passing
        # that to GDI unchanged shears the image diagonally rather than failing.
        width, height = 4, 2  # 4 * 3 = 12 bytes packed, source has 16.
        rows = [bytes(range(1, 13)) + b"\xaa\xaa\xaa\xaa",
                bytes(range(21, 33)) + b"\xaa\xaa\xaa\xaa"]

        page = FakePage(width, height, mode="BGR", stride=16, data=b"".join(rows))
        packed = printing.dib_bytes(page)

        self.assertEqual(len(packed), 12 * 2)
        self.assertEqual(packed[:12], bytes(range(1, 13)))
        self.assertEqual(packed[12:], bytes(range(21, 33)))

    def test_rgb_byte_order_is_refused(self):
        # Printing it would swap red and blue on every card in the run.
        with self.assertRaises(printing.PrintError) as caught:
            printing.dib_bytes(FakePage(4, 2, mode="RGB"))

        self.assertIn("byte order", str(caught.exception))

    def test_greyscale_is_refused(self):
        with self.assertRaises(printing.PrintError):
            printing.dib_bytes(FakePage(4, 2, mode="L"))

    def test_a_truncated_bitmap_is_refused(self):
        page = FakePage(4, 2, mode="BGRX")
        page.data = page.data[:-8]

        with self.assertRaises(printing.PrintError) as caught:
            printing.dib_bytes(page)

        self.assertIn("truncated", str(caught.exception))

    def test_an_impossible_stride_is_refused(self):
        page = FakePage(100, 2, mode="BGRX", stride=8, data=bytes(16))

        with self.assertRaises(printing.PrintError):
            printing.dib_bytes(page)


class FitRectTest(unittest.TestCase):
    """The rendered card is fitted into the driver's page, never stretched."""

    def test_an_exact_match_fills_the_page(self):
        self.assertEqual(printing.fit_rect((1011, 638), (1011, 638)), (0, 0, 1011, 638))

    def test_scaling_up_keeps_the_aspect_ratio(self):
        x, y, width, height = printing.fit_rect((1011, 638), (2022, 1276))
        self.assertEqual((x, y, width, height), (0, 0, 2022, 1276))

    def test_a_taller_page_letterboxes_rather_than_distorting(self):
        # A driver page that is not CR80 must show as a white margin, not as a
        # squashed badge nobody notices until the cards are cut.
        x, y, width, height = printing.fit_rect((1000, 500), (1000, 1000))
        self.assertEqual((width, height), (1000, 500))
        self.assertEqual((x, y), (0, 250))

    def test_a_wider_page_centres_horizontally(self):
        x, y, width, height = printing.fit_rect((1000, 500), (3000, 500))
        self.assertEqual((width, height), (1000, 500))
        self.assertEqual((x, y), (1000, 0))

    def test_a_zero_sized_page_is_an_error(self):
        with self.assertRaises(printing.PrintError):
            printing.fit_rect((1011, 638), (0, 638))


class CardGeometryTest(unittest.TestCase):
    """CR80 at the printer's own 300 dpi."""

    def test_a_cr80_card_at_300dpi(self):
        # 85.6 mm / 25.4 * 300 = 1011.0, 54 mm / 25.4 * 300 = 637.8.
        self.assertEqual(render.card_pixel_size(300), (1011, 638))

    def test_the_bleed_area_matches_the_badge_renderer(self):
        # App\Badges\ImagePreparer draws 1024 x 648 on 86.7 x 54.86 mm.
        self.assertEqual(render.bleed_pixel_size(300), (1024, 648))

    def test_dpi_scales_linearly(self):
        self.assertEqual(render.card_pixel_size(600), (2022, 1276))
        self.assertEqual(render.card_pixel_size(150), (506, 319))

    def test_millimetre_conversion_rounds_rather_than_truncates(self):
        self.assertEqual(render.mm_to_pixels(25.4, 300), 300)
        self.assertEqual(render.mm_to_pixels(54.0, 300), 638)

    def test_the_card_is_landscape(self):
        width, height = render.card_pixel_size()
        self.assertGreater(width, height)
        self.assertAlmostEqual(width / float(height), 1.585, places=2)

    def test_the_default_is_the_printers_own_resolution(self):
        self.assertEqual(render.DEFAULT_DPI, 300)
        self.assertEqual(render.card_pixel_size(), render.card_pixel_size(300))


class DpiTest(unittest.TestCase):
    def test_pdfium_scale_is_relative_to_72dpi(self):
        self.assertAlmostEqual(render.scale_for_dpi(300), 300 / 72.0)
        self.assertAlmostEqual(render.scale_for_dpi(72), 1.0)

    def test_absurd_resolutions_are_clamped(self):
        # 1200 dpi on a 300 dpi printer is 16x the memory for pixels the ribbon
        # cannot resolve, on a machine with 4 GB of RAM.
        self.assertEqual(render.clamp_dpi(1200), render.MAX_DPI)
        self.assertAlmostEqual(
            render.scale_for_dpi(4800), render.MAX_DPI / 72.0
        )

    def test_a_sane_resolution_is_left_alone(self):
        self.assertEqual(render.clamp_dpi(300), 300)

    def test_a_nonsense_resolution_is_refused(self):
        for value in (0, -300):
            with self.assertRaises(render.RenderError):
                render.clamp_dpi(value)

        with self.assertRaises(render.RenderError):
            render.clamp_dpi("three hundred")


class PageTest(unittest.TestCase):
    def test_reports_its_own_row_length(self):
        page = render.Page(width=1011, height=638, stride=0, mode="BGR", data=b"")
        self.assertEqual(page.packed_stride(), 3036)
        self.assertEqual(page.bits_per_pixel(), 24)

    def test_32bpp_modes(self):
        for mode in ("BGRA", "BGRX", "RGBA"):
            page = render.Page(width=10, height=2, stride=40, mode=mode, data=b"")
            self.assertEqual(page.bits_per_pixel(), 32)

    def test_an_unknown_mode_is_refused(self):
        page = render.Page(width=10, height=2, stride=40, mode="CMYK", data=b"")
        with self.assertRaises(render.RenderError):
            page.bits_per_pixel()

    def test_carries_its_size(self):
        page = render.Page(width=1011, height=638, stride=4044, mode="BGRX", data=b"")
        self.assertEqual(page.size, (1011, 638))
        self.assertAlmostEqual(page.aspect_ratio, 1011 / 638.0)


class RenderWithoutPdfiumTest(unittest.TestCase):
    """Rendering must fail with an instruction, not an ImportError."""

    def test_a_missing_file_is_named(self):
        with self.assertRaises(render.RenderError) as caught:
            render.render_pdf("/no/such/badge.pdf")

        self.assertIn("badge.pdf", str(caught.exception))

    def test_an_empty_path_is_refused(self):
        with self.assertRaises(render.RenderError):
            render.render_pdf("")

    @unittest.skipIf(render.pdf_available(), "pypdfium2 is installed here")
    def test_no_pdf_stack(self):
        self.assertFalse(render.pdf_available())

        handle = tempfile.NamedTemporaryFile(suffix=".pdf", delete=False)
        handle.write(b"%PDF-1.4\n")
        handle.close()

        try:
            with self.assertRaises(render.RenderError) as caught:
                render.render_pdf(handle.name)

            self.assertIn("pypdfium2", str(caught.exception))
        finally:
            os.unlink(handle.name)


class DuplexDevModeTest(unittest.TestCase):
    """Two-sided printing comes from the job, not the driver's saved settings.

    The agent used to open the DC with no DEVMODE, so whether a card printed on
    both sides -- and which edge it flipped about -- was whatever the printer
    preferences were last left on. A single-sided badge could waste the back of
    a card, and a dual-sided one could put its back on the next card.
    """

    class DevMode:
        def __init__(self):
            self.Duplex = 0
            self.Fields = 0

    def patched(self, devmode, driver="ZBR"):
        win32print = mock.Mock()
        win32print.OpenPrinter.return_value = 1
        win32print.GetPrinter.return_value = {"pDevMode": devmode, "pDriverName": driver}

        return mock.patch.dict(sys.modules, {
            "win32print": win32print,
            "win32con": mock.Mock(DM_DUPLEX=printing.DM_DUPLEX),
        })

    def test_a_dual_sided_badge_asks_for_two_sided_printing(self):
        devmode = self.DevMode()

        with self.patched(devmode):
            printing.duplex_devmode("ZXP9", duplex=True, flip=printing.FLIP_SHORT)

        self.assertEqual(devmode.Duplex, printing.DMDUP_HORIZONTAL)

    def test_the_flip_edge_is_honoured(self):
        # The one setting that decides whether the pre-rotated back lands
        # upright. Short and long must not resolve to the same value.
        short, long_ = self.DevMode(), self.DevMode()

        with self.patched(short):
            printing.duplex_devmode("ZXP9", duplex=True, flip=printing.FLIP_SHORT)
        with self.patched(long_):
            printing.duplex_devmode("ZXP9", duplex=True, flip=printing.FLIP_LONG)

        self.assertEqual(short.Duplex, printing.DMDUP_HORIZONTAL)
        self.assertEqual(long_.Duplex, printing.DMDUP_VERTICAL)
        self.assertNotEqual(short.Duplex, long_.Duplex)

    def test_a_single_sided_badge_asks_for_simplex(self):
        # Explicitly simplex, not "unset". Leaving it alone is how a one-sided
        # badge ends up wasting the back of a card on a duplex-defaulted driver.
        devmode = self.DevMode()

        with self.patched(devmode):
            printing.duplex_devmode("ZXP9", duplex=False)

        self.assertEqual(devmode.Duplex, printing.DMDUP_SIMPLEX)

    def test_the_duplex_field_bit_is_set(self):
        # Without the field bit the driver ignores the value entirely.
        devmode = self.DevMode()

        with self.patched(devmode):
            printing.duplex_devmode("ZXP9", duplex=True)

        self.assertTrue(devmode.Fields & printing.DM_DUPLEX)

    def test_the_driver_name_comes_back_for_the_dc(self):
        with self.patched(self.DevMode(), driver="Zebra ZXP Series 9"):
            driver, _devmode = printing.duplex_devmode("ZXP9", duplex=True)

        self.assertEqual(driver, "Zebra ZXP Series 9")

    def test_an_unreadable_devmode_falls_back_rather_than_failing(self):
        # A card on the driver's default settings beats no card at all.
        win32print = mock.Mock()
        win32print.OpenPrinter.side_effect = RuntimeError("no such printer")

        with mock.patch.dict(sys.modules, {"win32print": win32print,
                                           "win32con": mock.Mock()}):
            self.assertIsNone(printing.duplex_devmode("ZXP9", duplex=True))

    def test_a_printer_with_no_devmode_falls_back(self):
        win32print = mock.Mock()
        win32print.OpenPrinter.return_value = 1
        win32print.GetPrinter.return_value = {"pDevMode": None}

        with mock.patch.dict(sys.modules, {"win32print": win32print,
                                           "win32con": mock.Mock()}):
            self.assertIsNone(printing.duplex_devmode("ZXP9", duplex=True))


class RotateBackTest(unittest.TestCase):
    """Turning the back of a card over, on our own raster.

    The DEVMODE duplex flip is what should decide which way up the back lands,
    but a card printer may ignore the standard field, and when it does there is
    nothing to argue with. This is the lever that always works.
    """

    def page(self, width=2, height=2, mode="BGR", data=None, stride=None):
        bpp = render.BITS_PER_PIXEL[mode] // 8

        return render.Page(
            width=width, height=height,
            stride=stride if stride is not None else width * bpp,
            mode=mode,
            data=data if data is not None else bytes(range(width * height * bpp)),
            dpi=300, index=0,
        )

    def test_a_180_rotation_reverses_rows_and_pixels(self):
        # Four one-byte pixels: 1 2 / 3 4 becomes 4 3 / 2 1.
        page = self.page(width=2, height=2, mode="L", data=bytes([1, 2, 3, 4]))

        self.assertEqual(render.rotate_180(page).data, bytes([4, 3, 2, 1]))

    def test_pixels_are_kept_whole(self):
        # Three-byte pixels must move as units, or the colours come out wrong.
        page = self.page(width=2, height=1, mode="BGR",
                         data=bytes([1, 2, 3, 4, 5, 6]))

        self.assertEqual(render.rotate_180(page).data, bytes([4, 5, 6, 1, 2, 3]))

    def test_rotating_twice_gives_the_original_back(self):
        page = self.page(width=3, height=4, mode="BGRX")

        self.assertEqual(render.rotate_180(render.rotate_180(page)).data, page.data)

    def test_row_padding_is_dropped_and_the_stride_says_so(self):
        # A stale stride against a tightly packed buffer would shear the image.
        page = self.page(width=2, height=2, mode="L",
                         data=bytes([1, 2, 0, 0, 3, 4, 0, 0]), stride=4)
        turned = render.rotate_180(page)

        self.assertEqual(turned.data, bytes([4, 3, 2, 1]))
        self.assertEqual(turned.stride, 2)

    def test_the_page_keeps_its_shape(self):
        page = self.page(width=3, height=4, mode="BGRX")
        turned = render.rotate_180(page)

        self.assertEqual((turned.width, turned.height), (3, 4))
        self.assertEqual(turned.mode, page.mode)


if __name__ == "__main__":
    unittest.main()
