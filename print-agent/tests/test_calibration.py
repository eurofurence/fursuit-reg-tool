"""Calibration arithmetic, editing state, and the preview plumbing.

Everything the camera decides rests on rectangles and points somebody dragged
onto a preview at a convention desk. If the conversion between canvas pixels
and the fractions stored in the config is wrong, or drifts when the webcam is
swapped, the verifier silently watches the wrong part of the picture and every
card comes back unconfirmed. That conversion is pinned down here.

No display and no webcam are involved: the geometry is plain arithmetic, the
frame source is driven with a fake camera, and the widgets themselves are
smoke-tested against real Tk elsewhere.
"""

import os
import sys
import threading
import time
import types
import unittest

import numpy as np

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def _install_fake_tk():
    """Let this suite import the UI package on a machine with no Tk.

    The build box here has numpy but no `_tkinter`, and `agent.ui` pulls in the
    app module on the way to anything else. The arithmetic below has to be
    covered everywhere, so tkinter is stubbed just far enough for the imports
    to resolve. Only module-level names are touched; nothing in this file
    constructs a widget.
    """
    try:
        import tkinter  # noqa: F401
        return
    except ImportError:
        pass

    class Stub(types.ModuleType):
        def __getattr__(self, name):
            widget = type(name, (object,), {})
            setattr(self, name, widget)
            return widget

    root = Stub("tkinter")

    for name in ("ttk", "messagebox", "filedialog"):
        module = Stub("tkinter." + name)
        setattr(root, name, module)
        sys.modules["tkinter." + name] = module

    sys.modules["tkinter"] = root


_install_fake_tk()

from agent import camera, config  # noqa: E402
from agent.ui import calibration  # noqa: E402

# The calibration canvas, in pixels. Everything an operator drags is in these
# coordinates; everything stored is a fraction of them.
CANVAS = (640, 480)


def solid(height, width, colour):
    return np.full((height, width, 3), colour, dtype=np.uint8)


def green_frame():
    """A frame the colour of the tape operators stick on the chute."""
    import colorsys

    red, grn, blue = colorsys.hsv_to_rgb(60 / 180.0, 0.8, 0.7)

    return solid(120, 160, (int(blue * 255), int(grn * 255), int(red * 255)))


def wait_until(predicate, timeout=3.0):
    deadline = time.time() + timeout

    while time.time() < deadline:
        if predicate():
            return True
        time.sleep(0.01)

    return predicate()


class RectangleTest(unittest.TestCase):
    """A dragged rectangle, in canvas pixels, becoming fractions."""

    def test_a_drag_becomes_the_fraction_of_the_frame_it_covers(self):
        result = calibration.rect_fractions(160, 120, 480, 360, *CANVAS)

        self.assertEqual(result, (0.25, 0.25, 0.5, 0.5))

    def test_the_same_fractions_land_correctly_on_a_bigger_frame(self):
        # The whole reason nothing is stored in pixels: swapping the webcam or
        # its resolution must not move a single zone.
        x, y, width, height = calibration.rect_fractions(160, 120, 480, 360, *CANVAS)
        zone = config.Zone(name="chute", x=x, y=y, width=width, height=height)

        self.assertEqual(zone.pixels(1280, 720), (320, 180, 640, 360))
        self.assertEqual(zone.pixels(640, 480), (160, 120, 320, 240))

    def test_dragging_backwards_gives_the_same_rectangle(self):
        forwards = calibration.rect_fractions(160, 120, 480, 360, *CANVAS)
        backwards = calibration.rect_fractions(480, 360, 160, 120, *CANVAS)

        self.assertEqual(forwards, backwards)

    def test_a_drag_off_the_edge_is_pulled_back_inside(self):
        # A zone hanging off the frame crops to less than was drawn, which
        # looks like the camera randomly missing cards.
        x, y, width, height = calibration.rect_fractions(-90, -60, 900, 700, *CANVAS)

        self.assertEqual((x, y), (0.0, 0.0))
        self.assertEqual((width, height), (1.0, 1.0))

    def test_a_click_that_barely_moved_is_not_a_zero_sized_zone(self):
        # A zero-area zone crops to nothing and reports "no card" forever.
        _, _, width, height = calibration.rect_fractions(300, 300, 301, 300, *CANVAS)

        self.assertGreaterEqual(width, calibration.MIN_SIDE)
        self.assertGreaterEqual(height, calibration.MIN_SIDE)

    def test_fractions_come_back_as_canvas_pixels(self):
        zone = config.Zone(x=0.25, y=0.5, width=0.25, height=0.25)

        self.assertEqual(calibration.rect_pixels(zone, *CANVAS), (160.0, 240.0, 320.0, 360.0))


class PointTest(unittest.TestCase):
    def test_a_click_becomes_a_fraction_of_the_frame(self):
        self.assertEqual(calibration.point_fractions(320, 120, *CANVAS), (0.5, 0.25))

    def test_a_click_outside_the_frame_is_clamped(self):
        self.assertEqual(calibration.point_fractions(-10, 900, *CANVAS), (0.0, 1.0))

    def test_the_drawn_radius_matches_the_patch_the_sampler_reads(self):
        # camera._patch measures the radius against the shorter side. Drawing
        # it against the width would show a ring wider than what is measured,
        # and the operator would place the point trusting the wrong circle.
        point = config.Checkpoint(radius=0.05)

        self.assertAlmostEqual(calibration.point_radius_pixels(point, 640, 480), 24.0)
        self.assertAlmostEqual(calibration.point_radius_pixels(point, 480, 640), 24.0)


class HitTestingTest(unittest.TestCase):
    def setUp(self):
        self.zone = config.Zone(name="chute", x=0.25, y=0.25, width=0.5, height=0.5)

    def test_each_corner_offers_its_own_resize_handle(self):
        left, top, right, bottom = calibration.rect_pixels(self.zone, *CANVAS)

        self.assertEqual(calibration.handle_at(self.zone, left, top, *CANVAS), "nw")
        self.assertEqual(calibration.handle_at(self.zone, right, top, *CANVAS), "ne")
        self.assertEqual(calibration.handle_at(self.zone, left, bottom, *CANVAS), "sw")
        self.assertEqual(calibration.handle_at(self.zone, right, bottom, *CANVAS), "se")

    def test_the_middle_of_a_zone_is_not_a_handle(self):
        self.assertIsNone(calibration.handle_at(self.zone, 320, 240, *CANVAS))

    def test_a_click_inside_a_zone_finds_it(self):
        self.assertIs(calibration.zone_at([self.zone], 320, 240, *CANVAS), self.zone)

    def test_a_click_outside_every_zone_finds_nothing(self):
        self.assertIsNone(calibration.zone_at([self.zone], 10, 10, *CANVAS))

    def test_the_most_recently_drawn_zone_wins_when_they_overlap(self):
        # Whatever is drawn on top is what the operator thinks they clicked.
        on_top = config.Zone(name="later", x=0.3, y=0.3, width=0.2, height=0.2)

        self.assertIs(calibration.zone_at([self.zone, on_top], 250, 200, *CANVAS), on_top)

    def test_a_point_has_a_grab_area_bigger_than_its_ring(self):
        # A point is a few pixels across and nobody clicks that accurately
        # over a laggy remote session.
        point = config.Checkpoint(x=0.5, y=0.5, radius=0.02)

        self.assertIs(calibration.checkpoint_at([point], 322, 242, *CANVAS), point)
        self.assertIsNone(calibration.checkpoint_at([point], 500, 100, *CANVAS))


class DragTest(unittest.TestCase):
    def setUp(self):
        self.zone = config.Zone(x=0.25, y=0.25, width=0.5, height=0.5)

    def test_moving_a_zone_shifts_it_by_the_pointer_distance(self):
        x, y = calibration.moved_zone(self.zone, 64, -48, *CANVAS)

        self.assertAlmostEqual(x, 0.35)
        self.assertAlmostEqual(y, 0.15)

    def test_a_zone_cannot_be_dragged_off_the_frame(self):
        x, y = calibration.moved_zone(self.zone, 5000, 5000, *CANVAS)

        self.assertAlmostEqual(x, 0.5)
        self.assertAlmostEqual(y, 0.5)

    def test_resizing_holds_the_opposite_corner_still(self):
        x, y, width, height = calibration.resized_zone(self.zone, "nw", 320, 240, *CANVAS)

        # Dragging the north-west corner to the middle: the south-east corner
        # is exactly where it was.
        self.assertAlmostEqual(x, 0.5)
        self.assertAlmostEqual(y, 0.5)
        self.assertAlmostEqual(x + width, 0.75)
        self.assertAlmostEqual(y + height, 0.75)

    def test_resizing_past_the_anchor_flips_rather_than_inverting(self):
        x, y, width, height = calibration.resized_zone(self.zone, "se", 100, 100, *CANVAS)

        self.assertGreater(width, 0.0)
        self.assertGreater(height, 0.0)
        self.assertAlmostEqual(x + width, 0.25)
        self.assertAlmostEqual(y + height, 0.25)


class CalibrationStateTest(unittest.TestCase):
    """Editing happens on a copy, so Cancel means cancel."""

    def setUp(self):
        self.camera_config = config.CameraConfig()
        self.camera_config.zones = [
            config.Zone(name="card", purpose=config.ZONE_CARD,
                        x=0.1, y=0.1, width=0.4, height=0.4),
        ]
        self.camera_config.checkpoints = [
            config.Checkpoint(name="tray", purpose=config.POINT_TRAY_FULL, calibrated=True),
        ]
        self.state = calibration.CalibrationState(self.camera_config)

    def test_edits_do_not_reach_the_saved_config_until_they_are_applied(self):
        # The printer keeps running off the saved calibration while somebody is
        # halfway through dragging a new one.
        self.state.zones[0].name = "moved"
        self.state.add_zone(config.ZONE_CARD, 0.5, 0.5, 0.2, 0.1)

        self.assertEqual(self.camera_config.zones[0].name, "card")
        self.assertEqual(len(self.camera_config.zones), 1)

    def test_applying_writes_everything_back(self):
        self.state.zones[0].name = "moved"
        self.state.add_checkpoint(config.POINT_CARD_INK, 0.2, 0.8)
        self.state.apply_to(self.camera_config)

        self.assertEqual(self.camera_config.zones[0].name, "moved")
        self.assertEqual(len(self.camera_config.checkpoints), 2)
        self.assertEqual(self.camera_config.checkpoints[1].purpose, config.POINT_CARD_INK)

    def test_what_was_applied_is_a_copy_of_the_editing_state(self):
        self.state.apply_to(self.camera_config)
        self.state.zones[0].name = "changed afterwards"

        self.assertEqual(self.camera_config.zones[0].name, "card")

    def test_a_new_item_is_selected_so_it_can_be_named_at_once(self):
        zone = self.state.add_zone(config.ZONE_CARD, 0.1, 0.1, 0.2, 0.2)

        self.assertIs(self.state.selected, zone)

    def test_a_second_point_of_the_same_purpose_replaces_the_first(self):
        # One point per purpose per printer. Two spots both claiming to be the
        # full-tray sensor is not extra configuration, it is an ambiguity
        # somebody has to resolve mid-event; redrawing just moves it.
        first = self.state.add_checkpoint(config.POINT_TRAY_FULL, 0.1, 0.1)
        second = self.state.add_checkpoint(config.POINT_TRAY_FULL, 0.2, 0.2)

        self.assertEqual([p.name for p in self.state.checkpoints], ["tray_full"])
        self.assertNotIn(first, self.state.checkpoints)
        self.assertIs(self.state.selected, second)
        self.assertAlmostEqual(second.x, 0.2)

    def test_a_different_purpose_coexists(self):
        self.state.add_checkpoint(config.POINT_TRAY_FULL, 0.1, 0.1)
        self.state.add_checkpoint(config.POINT_CARD_INK, 0.2, 0.2)

        self.assertEqual(
            sorted(p.purpose for p in self.state.checkpoints),
            [config.POINT_CARD_INK, config.POINT_TRAY_FULL])

    def test_redrawing_a_zone_replaces_the_one_with_that_purpose(self):
        self.state.add_zone(config.ZONE_CARD, 0.1, 0.1, 0.2, 0.2)
        self.state.add_zone(config.ZONE_CARD, 0.5, 0.5, 0.3, 0.3)

        matching = [z for z in self.state.zones if z.purpose == config.ZONE_CARD]

        self.assertEqual(len(matching), 1)
        self.assertAlmostEqual(matching[0].x, 0.5)

    def test_deleting_removes_only_the_selected_item(self):
        # setUp holds one zone and one point. Deleting the selected point must
        # leave the zone alone, and vice versa.
        point = self.state.add_checkpoint(config.POINT_CARD_INK, 0.6, 0.6)
        self.state.selected = point

        self.assertTrue(self.state.delete_selected())
        self.assertEqual([z.name for z in self.state.zones], ["card"])
        self.assertEqual([p.name for p in self.state.checkpoints], ["tray"])
        self.assertIsNone(self.state.selected)

    def test_deleting_the_card_zone_leaves_no_zone(self):
        # There is only ever one, so removing it means the camera has nothing
        # to look at until another is drawn.
        self.state.selected = self.state.zones[0]

        self.assertTrue(self.state.delete_selected())
        self.assertEqual(self.state.zones, [])

    def test_deleting_with_nothing_selected_does_nothing(self):
        self.state.selected = None

        self.assertFalse(self.state.delete_selected())
        self.assertEqual(len(self.state.zones), 1)

    def test_rows_say_which_points_are_not_calibrated_yet(self):
        # setUp holds a calibrated tray point. Adding another of the same
        # purpose replaces it with a fresh, uncalibrated one.
        self.state.add_checkpoint(config.POINT_TRAY_FULL, 0.4, 0.4)
        labels = [label for _, _, label in self.state.rows()]

        self.assertTrue(any("NOT CALIBRATED" in label for label in labels))

    def test_an_ink_point_is_never_reported_as_uncalibrated(self):
        # It tests for bare card stock, which is absolute: there is no such
        # thing as a differently-coloured blank card, so there is nothing to
        # capture. Saying NOT CALIBRATED would be a warning nobody can clear.
        self.state.add_checkpoint(config.POINT_CARD_INK, 0.4, 0.4)
        labels = [label for _, _, label in self.state.rows()]

        self.assertFalse(any("NOT CALIBRATED" in label for label in labels))
        self.assertTrue(any("no reference needed" in label for label in labels))

    def test_uncalibrated_points_are_reported_for_the_save_warning(self):
        self.state.add_checkpoint(config.POINT_TRAY_FULL, 0.4, 0.4)

        self.assertEqual(len(self.state.uncalibrated()), 1)

    def test_ink_points_never_hold_up_the_save_warning(self):
        self.state.add_checkpoint(config.POINT_CARD_INK, 0.4, 0.4)

        self.assertEqual(self.state.uncalibrated(), [])

    def test_the_index_of_an_item_matches_the_row_it_is_shown_on(self):
        point = self.state.checkpoints[0]

        self.assertEqual(self.state.index_of(point), 1)


class CalibratePointTest(unittest.TestCase):
    """Sampling the empty tray and turning it into a reference colour."""

    def setUp(self):
        self.point = config.Checkpoint(x=0.5, y=0.5, radius=0.1)

    def test_sampling_stores_the_colour_under_the_point(self):
        self.assertTrue(calibration.calibrate_checkpoint(self.point, green_frame()))

        self.assertTrue(self.point.calibrated)
        self.assertAlmostEqual(self.point.reference_hue, 60.0, delta=1.0)
        self.assertAlmostEqual(self.point.reference_saturation, 0.8, delta=0.02)

    def test_sampling_with_no_picture_changes_nothing(self):
        # The camera is optional. Pressing Calibrate with no feed must not
        # write a black reference that then matches nothing.
        self.assertFalse(calibration.calibrate_checkpoint(self.point, None))
        self.assertFalse(self.point.calibrated)

    def test_a_calibrated_point_reads_as_matching_its_own_frame(self):
        frame = green_frame()
        calibration.calibrate_checkpoint(self.point, frame)

        text, colour, changed = calibration.checkpoint_indicator(self.point, frame)

        self.assertFalse(changed)
        self.assertIn("matches", text)
        self.assertEqual(colour, calibration.STEADY_COLOUR)

    def test_covering_the_point_reads_as_changed(self):
        # What an operator does to check the point: wave a card over it.
        calibration.calibrate_checkpoint(self.point, green_frame())

        text, colour, changed = calibration.checkpoint_indicator(
            self.point, solid(120, 160, (245, 245, 245)))

        self.assertTrue(changed)
        self.assertIn("CHANGED", text)
        self.assertEqual(colour, calibration.CHANGED_COLOUR)

    def test_the_indicator_shows_the_gap_against_the_tolerance(self):
        # "CHANGED" alone says nothing about how close to the edge a reading
        # is, which is what decides whether the tolerance wants widening.
        calibration.calibrate_checkpoint(self.point, green_frame())
        self.point.hue_tolerance = 15.0

        text, _, _ = calibration.checkpoint_indicator(self.point, green_frame())

        self.assertIn("hue", text)
        self.assertIn("15", text)

    def test_an_uncalibrated_point_says_so_rather_than_guessing(self):
        text, colour, changed = calibration.checkpoint_indicator(self.point, green_frame())

        self.assertFalse(changed)
        self.assertIn("not calibrated", text)
        self.assertEqual(colour, calibration.UNCALIBRATED_COLOUR)

    def test_no_frame_reads_as_no_camera_not_as_a_full_tray(self):
        calibration.calibrate_checkpoint(self.point, green_frame())

        text, _, changed = calibration.checkpoint_indicator(self.point, None)

        self.assertFalse(changed)
        self.assertIn("no camera", text)

    def test_a_switched_off_point_is_reported_as_such(self):
        self.point.enabled = False

        text, _, changed = calibration.checkpoint_indicator(self.point, green_frame())

        self.assertFalse(changed)
        self.assertIn("off", text)

    def test_the_reference_matches_what_the_verifier_would_sample(self):
        # Calibration and verification must agree on the reading, or every
        # freshly calibrated point trips immediately.
        frame = green_frame()
        calibration.calibrate_checkpoint(self.point, frame)

        sample = camera.sample_point(frame, self.point)

        self.assertAlmostEqual(sample.hue, self.point.reference_hue, places=6)
        self.assertFalse(camera.checkpoint_changed(frame, self.point))


class PpmConversionTest(unittest.TestCase):
    """Getting a webcam frame onto a Tk canvas with no imaging library."""

    def test_the_header_names_the_requested_size(self):
        data = calibration.frame_to_ppm(solid(8, 8, (10, 20, 30)), 4, 2)

        self.assertTrue(data.startswith(b"P6\n4 2\n255\n"))

    def test_the_body_is_three_bytes_per_requested_pixel(self):
        data = calibration.frame_to_ppm(solid(8, 8, (10, 20, 30)), 4, 2)
        header = b"P6\n4 2\n255\n"

        self.assertEqual(len(data) - len(header), 4 * 2 * 3)

    def test_channels_are_reordered_from_bgr_to_rgb(self):
        # OpenCV hands over BGR. Feeding that to Tk unchanged turns every card
        # blue, and an operator would reasonably conclude the camera is broken.
        data = calibration.frame_to_ppm(solid(4, 4, (255, 0, 0)), 1, 1)

        self.assertEqual(data[-3:], bytes((0, 0, 255)))

    def test_downscaling_keeps_the_picture_the_right_way_round(self):
        frame = np.zeros((4, 4, 3), dtype=np.uint8)
        frame[:, :2] = (0, 0, 255)   # left half red
        frame[:, 2:] = (0, 255, 0)   # right half green

        data = calibration.frame_to_ppm(frame, 2, 1)

        self.assertEqual(data[-6:], bytes((255, 0, 0, 0, 255, 0)))

    def test_a_greyscale_frame_still_converts(self):
        data = calibration.frame_to_ppm(np.full((4, 4), 128, dtype=np.uint8), 1, 1)

        self.assertEqual(data[-3:], bytes((128, 128, 128)))

    def test_an_alpha_channel_is_dropped(self):
        frame = np.full((4, 4, 4), 200, dtype=np.uint8)
        frame[:, :, 3] = 0

        data = calibration.frame_to_ppm(frame, 1, 1)

        self.assertEqual(len(data[len(b"P6\n1 1\n255\n"):]), 3)

    def test_nothing_to_show_returns_nothing(self):
        self.assertIsNone(calibration.frame_to_ppm(None, 4, 4))
        self.assertIsNone(calibration.frame_to_ppm(np.zeros((0, 0, 3), dtype=np.uint8), 4, 4))

    def test_a_zero_sized_target_does_not_divide_by_zero(self):
        self.assertIsNotNone(calibration.frame_to_ppm(solid(4, 4, (1, 2, 3)), 0, 0))


class FakeCamera:
    """Stands in for a webcam, including the ways one misbehaves."""

    def __init__(self, frames=None, fail_after=None):
        self.frames = frames
        self.fail_after = fail_after
        self.reads = 0
        self.closed = False

    def read(self):
        self.reads += 1

        if self.fail_after is not None and self.reads > self.fail_after:
            return None

        if self.frames is None:
            return None

        return self.frames

    def close(self):
        self.closed = True


class FrameSourceTest(unittest.TestCase):
    """The grab thread. Its whole job is to fail without taking the UI down."""

    def setUp(self):
        self.opened = []

    def source(self, factory, **kwargs):
        def open_camera(index):
            self.opened.append(index)
            return factory(index)

        made = calibration.FrameSource(device_index=kwargs.pop("device_index", 2),
                                       interval=0.01, open_camera=open_camera)
        made.RETRY_SECONDS = 0.02
        self.addCleanup(made.stop)

        return made

    def test_frames_reach_the_ui_thread(self):
        device = FakeCamera(frames="a frame")
        source = self.source(lambda _: device).start()

        self.assertTrue(wait_until(lambda: source.latest() == "a frame"))
        self.assertTrue(source.is_live())
        self.assertIn("live", source.status())

    def test_stopping_releases_the_device(self):
        # Two captures on one webcam hangs on Windows, so a preview that is
        # finished with the camera has to actually let go of it.
        device = FakeCamera(frames="a frame")
        source = self.source(lambda _: device).start()
        wait_until(lambda: source.latest() is not None)

        source.stop()

        self.assertTrue(device.closed)
        self.assertIsNone(source.latest())
        self.assertEqual(source.status(), "Camera off")

    def test_a_camera_that_will_not_open_becomes_a_message(self):
        # No OpenCV, or somebody unplugged it before the agent started.
        def explode(_):
            raise RuntimeError("OpenCV is not installed on this machine")

        source = self.source(explode).start()

        self.assertTrue(wait_until(lambda: "OpenCV" in source.status()))
        self.assertIsNone(source.latest())

    def test_a_camera_that_appears_later_is_picked_up(self):
        # Somebody plugs the webcam back in mid-convention. The preview has to
        # recover on its own; nobody is going to restart the agent for it.
        state = {"ready": False}

        def maybe(_):
            if not state["ready"]:
                raise RuntimeError("Camera 2 could not be opened")
            return FakeCamera(frames="back")

        source = self.source(maybe).start()
        wait_until(lambda: "could not be opened" in source.status())

        state["ready"] = True

        self.assertTrue(wait_until(lambda: source.latest() == "back"))

    def test_a_camera_that_stops_answering_is_reopened(self):
        devices = []

        def make(_):
            device = FakeCamera(frames="a frame", fail_after=2)
            devices.append(device)
            return device

        source = self.source(make).start()

        self.assertTrue(wait_until(lambda: len(self.opened) >= 2))
        self.assertTrue(devices[0].closed)

    def test_a_reader_that_raises_does_not_kill_the_thread(self):
        class Exploding:
            def read(self):
                raise IOError("device disappeared")

            def close(self):
                pass

        source = self.source(lambda _: Exploding()).start()

        self.assertTrue(wait_until(lambda: "stopped answering" in source.status()))
        self.assertTrue(any(t.name == "camera-preview" for t in threading.enumerate()))

    def test_starting_twice_does_not_open_the_camera_twice(self):
        source = self.source(lambda _: FakeCamera(frames="a frame")).start()
        wait_until(lambda: source.latest() is not None)

        source.start()
        time.sleep(0.05)

        self.assertEqual(len(self.opened), 1)

    def test_stopping_a_source_that_never_started_is_harmless(self):
        calibration.FrameSource(open_camera=lambda _: FakeCamera()).stop()


if __name__ == "__main__":
    unittest.main()


class CameraSettingsDialogTest(unittest.TestCase):
    """The driver's own property pages, opened without freezing the app.

    The dialog is modal to whichever thread opens it and it owns the capture,
    so it has to run on the grab thread. Opening it from the Tk thread would
    lock the whole application behind a window belonging to a webcam.
    """

    class FakeCamera:
        def __init__(self):
            self.settings_calls = 0
            self.thread_names = []
            self.reads = 0

        def read(self):
            self.reads += 1
            return np.zeros((4, 4, 3), dtype=np.uint8)

        def open_settings(self):
            self.settings_calls += 1
            self.thread_names.append(threading.current_thread().name)
            return True

        def close(self):
            pass

    def test_the_dialog_opens_on_the_capture_thread(self):
        fake = self.FakeCamera()
        source = calibration.FrameSource(0, interval=0.01, open_camera=lambda _: fake)
        source.start()

        try:
            self.assertTrue(wait_until(lambda: fake.reads > 0))
            self.assertTrue(source.request_settings())
            self.assertTrue(wait_until(lambda: fake.settings_calls == 1))
        finally:
            source.stop()

        # Never the caller's thread, which is the UI thread in the real app.
        self.assertNotIn(threading.current_thread().name, fake.thread_names)

    def test_requesting_returns_at_once(self):
        # Fire and forget: the button handler must not wait for the operator to
        # close a dialog belonging to the camera driver.
        fake = self.FakeCamera()
        source = calibration.FrameSource(0, interval=0.01, open_camera=lambda _: fake)
        source.start()

        try:
            started = time.time()
            source.request_settings()
            elapsed = time.time() - started
        finally:
            source.stop()

        self.assertLess(elapsed, 0.1)

    def test_requesting_with_no_preview_running_does_nothing(self):
        source = calibration.FrameSource(0, open_camera=lambda _: self.FakeCamera())

        self.assertFalse(source.request_settings())

    def test_grabbing_resumes_after_the_dialog_closes(self):
        fake = self.FakeCamera()
        source = calibration.FrameSource(0, interval=0.01, open_camera=lambda _: fake)
        source.start()

        try:
            self.assertTrue(wait_until(lambda: fake.reads > 0))
            source.request_settings()
            self.assertTrue(wait_until(lambda: fake.settings_calls == 1))

            after = fake.reads
            self.assertTrue(wait_until(lambda: fake.reads > after))
        finally:
            source.stop()
