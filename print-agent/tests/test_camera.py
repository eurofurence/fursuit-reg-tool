"""Camera verification, exercised on synthetic frames with no webcam attached.

The camera exists because the printer driver lies, so these functions are the
last line between "a card came out" and "we told the server a card came out".
They also decide when to stop the queue for a full tray, which means a false
positive costs throughput at a convention desk. Both directions are pinned
down here: what must trigger, and what must not.

Frames are plain numpy BGR arrays, and nothing imports cv2 or pytesseract, so
this suite runs on a build machine with neither installed.
"""

import colorsys
import os
import sys
import unittest
from unittest import mock

import numpy as np

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import camera, config  # noqa: E402


def bgr(hue: float, saturation: float, value: float) -> tuple:
    """Build a BGR pixel from OpenCV-style HSV (hue 0-180, s and v 0-1)."""
    red, green, blue = colorsys.hsv_to_rgb(hue / 180.0, saturation, value)

    return (int(round(blue * 255)), int(round(green * 255)), int(round(red * 255)))


def solid(height: int, width: int, colour: tuple) -> "np.ndarray":
    return np.full((height, width, 3), colour, dtype=np.uint8)


def checkpoint(**kwargs) -> config.Checkpoint:
    defaults = dict(x=0.5, y=0.5, radius=0.1, calibrated=True)
    defaults.update(kwargs)

    return config.Checkpoint(**defaults)


class CardPresentTest(unittest.TestCase):
    """A card is a big bright object landing in a small calibrated rectangle."""

    def setUp(self):
        self.zone = config.Zone(name="chute", x=0.25, y=0.25, width=0.5, height=0.5)
        self.empty = solid(100, 100, (30, 30, 30))

    def test_an_identical_frame_reports_no_card(self):
        result = camera.card_present(self.empty, self.empty, self.zone)

        self.assertFalse(result.present)
        self.assertEqual(result.score, 0.0)

    def test_a_bright_card_in_the_zone_is_detected(self):
        frame = self.empty.copy()
        frame[30:70, 30:70] = 230

        result = camera.card_present(frame, self.empty, self.zone)

        self.assertTrue(result.present)
        self.assertGreater(result.score, 0.5)

    def test_sensor_noise_is_not_a_card(self):
        # A few counts of drift on every pixel is what a webcam does under
        # fluorescent light. Treating that as a card would confirm prints that
        # never happened, which is the exact failure we are here to stop.
        noisy = np.clip(self.empty.astype(np.int16) + 8, 0, 255).astype(np.uint8)

        self.assertFalse(camera.card_present(noisy, self.empty, self.zone).present)

    def test_change_outside_the_zone_is_ignored(self):
        # Staff walk past the printer constantly; only the chute counts.
        frame = self.empty.copy()
        frame[0:20, 0:20] = 255

        self.assertFalse(camera.card_present(frame, self.empty, self.zone).present)

    def test_a_reference_of_another_size_reports_nothing(self):
        # Someone changed webcam or resolution mid-run. No opinion beats a
        # confident wrong answer.
        result = camera.card_present(self.empty, solid(50, 50, (30, 30, 30)), self.zone)

        self.assertFalse(result.present)
        self.assertEqual(result.score, 0.0)


class HueDistanceTest(unittest.TestCase):
    """Hue is a wheel. Forgetting that is the classic bug in this code."""

    def test_hues_either_side_of_the_wrap_are_close(self):
        # 178 and 2 are four apart on OpenCV's 0-180 wheel, not 176. Red tape
        # sits right on this seam, so a naive subtraction reads "changed"
        # every single frame and the queue never runs.
        self.assertAlmostEqual(camera.hue_distance(178, 2), 4.0, places=6)
        self.assertAlmostEqual(camera.hue_distance(2, 178), 4.0, places=6)

    def test_opposite_hues_are_the_maximum_distance(self):
        self.assertAlmostEqual(camera.hue_distance(0, 90), 90.0, places=6)

    def test_identical_hues_are_zero_apart(self):
        self.assertEqual(camera.hue_distance(60, 60), 0.0)


class SamplePointTest(unittest.TestCase):
    def test_reads_the_hue_and_saturation_under_the_point(self):
        frame = solid(100, 100, bgr(60, 0.8, 0.7))  # green

        sample = camera.sample_point(frame, checkpoint())

        self.assertAlmostEqual(sample.hue, 60.0, delta=1.0)
        self.assertAlmostEqual(sample.saturation, 0.8, delta=0.02)

    def test_a_grey_patch_has_no_saturation(self):
        sample = camera.sample_point(solid(100, 100, (128, 128, 128)), checkpoint())

        self.assertAlmostEqual(sample.saturation, 0.0, places=6)

    def test_the_median_ignores_a_few_bright_specks(self):
        # Specular highlights off a glossy card, or a dead pixel. A mean would
        # be dragged off the tape colour; the median must not move.
        frame = solid(100, 100, bgr(60, 0.8, 0.7))
        frame[48:52, 48:52] = (255, 255, 255)

        sample = camera.sample_point(frame, checkpoint())

        self.assertAlmostEqual(sample.hue, 60.0, delta=1.0)
        self.assertAlmostEqual(sample.saturation, 0.8, delta=0.05)

    def test_the_median_hue_handles_the_wrap_seam(self):
        # Half the patch reads 179 and half reads 1: the answer is 0, not 90.
        frame = solid(100, 100, bgr(179, 0.9, 0.8))
        frame[:, 50:] = bgr(1, 0.9, 0.8)

        sample = camera.sample_point(frame, checkpoint())

        self.assertLess(camera.hue_distance(sample.hue, 0.0), 3.0)

    def test_a_point_at_the_frame_edge_does_not_raise(self):
        sample = camera.sample_point(solid(40, 40, (10, 200, 10)), checkpoint(x=0.0, y=1.0))

        self.assertGreater(sample.saturation, 0.5)


class CheckpointChangedTest(unittest.TestCase):
    """The full-tray detector. Wrong in either direction costs the desk."""

    def test_the_room_lights_coming_on_is_not_a_full_tray(self):
        # The whole reason value is discarded. Same green tape, four times the
        # light: hue and saturation are unchanged, so nothing must trigger.
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)
        bright = solid(80, 80, bgr(60, 0.8, 0.95))
        dim = solid(80, 80, bgr(60, 0.8, 0.25))

        self.assertFalse(camera.checkpoint_changed(bright, point))
        self.assertFalse(camera.checkpoint_changed(dim, point))

    def test_a_white_card_covering_green_tape_is_a_full_tray(self):
        # The real signal: the stack has grown high enough to hide the tape.
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)
        covered = solid(80, 80, (245, 245, 245))

        self.assertTrue(camera.checkpoint_changed(covered, point))

    def test_a_genuine_hue_change_triggers(self):
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)
        blue = solid(80, 80, bgr(120, 0.8, 0.7))

        self.assertTrue(camera.checkpoint_changed(blue, point))

    def test_a_small_colour_drift_stays_within_tolerance(self):
        # Tolerances are generous on purpose: lighting moves these more than
        # you would expect over a day in a hall.
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)
        drifted = solid(80, 80, bgr(68, 0.72, 0.7))

        self.assertFalse(camera.checkpoint_changed(drifted, point))

    def test_hue_noise_on_a_grey_patch_does_not_trigger(self):
        # An operator who never stuck tape down leaves a grey chute as the
        # reference. Grey has no meaningful hue, so hue must be ignored there
        # rather than firing on sensor noise.
        point = checkpoint(reference_hue=0.0, reference_saturation=0.0)
        grey = solid(80, 80, bgr(150, 0.05, 0.5))

        self.assertFalse(camera.checkpoint_changed(grey, point))

    def test_an_uncalibrated_checkpoint_never_triggers(self):
        point = checkpoint(calibrated=False, reference_hue=60.0, reference_saturation=0.8)

        self.assertFalse(camera.checkpoint_changed(solid(80, 80, (255, 255, 255)), point))

    def test_a_disabled_checkpoint_never_triggers(self):
        point = checkpoint(enabled=False, reference_hue=60.0, reference_saturation=0.8)

        self.assertFalse(camera.checkpoint_changed(solid(80, 80, (255, 255, 255)), point))

    def test_distance_is_reported_for_the_calibration_ui(self):
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)
        hue_gap, saturation_gap = camera.checkpoint_distance(
            solid(80, 80, bgr(90, 0.4, 0.7)), point
        )

        self.assertAlmostEqual(hue_gap, 30.0, delta=1.0)
        self.assertAlmostEqual(saturation_gap, 0.4, delta=0.02)


class CheckpointTrackerTest(unittest.TestCase):
    """Debouncing, so a hand in the chute does not stop the print queue."""

    def setUp(self):
        self.point = checkpoint(
            reference_hue=60.0,
            reference_saturation=0.8,
            consecutive_frames=3,
        )
        self.tracker = camera.CheckpointTracker(self.point)

    def test_a_single_changed_frame_does_not_flip_the_state(self):
        self.assertFalse(self.tracker.observe(True))
        self.assertFalse(self.tracker.state)

    def test_three_agreeing_frames_flip_the_state(self):
        self.assertFalse(self.tracker.observe(True))
        self.assertFalse(self.tracker.observe(True))
        self.assertTrue(self.tracker.observe(True))

    def test_one_disagreeing_frame_resets_the_run(self):
        # An arm passing the lens: two frames changed, then back to normal.
        # The count must start again, not carry over into the next wave.
        self.tracker.observe(True)
        self.tracker.observe(True)
        self.tracker.observe(False)

        self.assertFalse(self.tracker.observe(True))
        self.assertFalse(self.tracker.observe(True))
        self.assertTrue(self.tracker.observe(True))

    def test_clearing_the_tray_also_needs_three_frames(self):
        for _ in range(3):
            self.tracker.observe(True)

        self.assertTrue(self.tracker.observe(False))
        self.assertTrue(self.tracker.observe(False))
        self.assertFalse(self.tracker.observe(False))

    def test_update_samples_a_frame(self):
        covered = solid(60, 60, (245, 245, 245))

        for _ in range(2):
            self.assertFalse(self.tracker.update(covered))

        self.assertTrue(self.tracker.update(covered))

    def test_reset_returns_to_a_known_state(self):
        self.tracker.observe(True)
        self.tracker.reset()

        self.assertFalse(self.tracker.state)
        self.assertEqual(self.tracker.streak, 0)


class CaptureBackendTest(unittest.TestCase):
    """Which OpenCV backend opens the cameras.

    Measured on the Windows 7 print station: Media Foundation, OpenCV's default
    there, opened all three attached cameras and then read a frame from none of
    them ("unsupported media type" for RGB24). DirectShow read all three. Left
    on the default, camera verification silently never sees a card.
    """

    class FakeCv2:
        CAP_DSHOW = 700
        CAP_ANY = 0

    def test_windows_uses_directshow(self):
        with mock.patch.object(camera.sys, "platform", "win32"):
            self.assertEqual(camera.capture_backend(self.FakeCv2), 700)

    def test_other_platforms_use_the_library_default(self):
        for platform in ("darwin", "linux"):
            with mock.patch.object(camera.sys, "platform", platform):
                self.assertEqual(camera.capture_backend(self.FakeCv2), 0)

    def test_an_opencv_without_directshow_does_not_explode(self):
        # Older or trimmed builds may not expose the constant at all.
        class NoDshow:
            CAP_ANY = 0

        with mock.patch.object(camera.sys, "platform", "win32"):
            self.assertEqual(camera.capture_backend(NoDshow), 0)


class CameraTest(unittest.TestCase):
    def test_opening_without_opencv_raises_a_clear_error(self):
        previous = sys.modules.get("cv2")
        sys.modules["cv2"] = None

        try:
            with self.assertRaises(RuntimeError) as caught:
                camera.Camera().open()
        finally:
            if previous is None:
                sys.modules.pop("cv2", None)
            else:
                sys.modules["cv2"] = previous

        # The operator reading this is standing at a printer, not a terminal.
        self.assertIn("opencv-python", str(caught.exception))

    def test_reading_a_closed_camera_returns_nothing(self):
        # Printing must survive an unplugged webcam.
        self.assertIsNone(camera.Camera().read())

    def test_close_is_safe_on_a_camera_that_never_opened(self):
        device = camera.Camera()
        device.close()

        self.assertFalse(device.is_open())


class GrayscaleTest(unittest.TestCase):
    def test_uses_opencv_luma_weights(self):
        # Results have to line up with cv2 on the station, where frames may
        # already have been converted before reaching these functions.
        white = camera.to_gray(solid(2, 2, (255, 255, 255)))
        blue = camera.to_gray(solid(2, 2, (255, 0, 0)))

        self.assertAlmostEqual(float(white[0, 0]), 255.0, places=4)
        self.assertAlmostEqual(float(blue[0, 0]), 255 * 0.114, places=4)

    def test_an_already_grey_frame_passes_through(self):
        self.assertEqual(camera.to_gray(np.full((2, 2), 40, dtype=np.uint8))[0, 0], 40.0)


if __name__ == "__main__":
    unittest.main()


class RotateFrameTest(unittest.TestCase):
    """A webcam clamped over a bin is rarely upright, and OCR needs it to be."""

    def setUp(self):
        # Distinguishable corners, so a wrong rotation is obvious.
        self.frame = np.zeros((2, 3, 3), dtype=np.uint8)
        self.frame[0, 0] = (1, 1, 1)
        self.frame[0, 2] = (2, 2, 2)

    def test_zero_returns_the_frame_unchanged(self):
        self.assertIs(camera.rotate_frame(self.frame, 0), self.frame)

    def test_ninety_turns_clockwise(self):
        turned = camera.rotate_frame(self.frame, 90)

        self.assertEqual(turned.shape, (3, 2, 3))
        # The old top-left must end up at the top-right.
        self.assertEqual(tuple(turned[0, 1]), (1, 1, 1))

    def test_a_full_turn_is_a_no_op(self):
        np.testing.assert_array_equal(camera.rotate_frame(self.frame, 360), self.frame)

    def test_odd_angles_snap_to_the_nearest_right_angle(self):
        # Only a bracket angle is ever meant here, and 90 is lossless.
        np.testing.assert_array_equal(
            camera.rotate_frame(self.frame, 88), camera.rotate_frame(self.frame, 90))


class StillnessTest(unittest.TestCase):
    """Cards drop from the chute and settle; a frame caught mid-fall is blurred."""

    def setUp(self):
        self.zone = config.Zone(name="card", x=0.0, y=0.0, width=1.0, height=1.0)
        self.quiet = solid(60, 60, (40, 40, 40))

    def test_identical_frames_have_no_difference(self):
        self.assertEqual(camera.frame_difference(self.quiet, self.quiet, self.zone), 0.0)

    def test_a_moving_card_registers_as_movement(self):
        moved = self.quiet.copy()
        moved[10:50, 10:50] = 230

        self.assertGreater(camera.frame_difference(moved, self.quiet, self.zone), 0.2)

    def test_stillness_needs_several_consecutive_quiet_frames(self):
        tracker = camera.StillnessTracker(self.zone, required=3)

        self.assertFalse(tracker.update(self.quiet))   # first frame, no baseline
        self.assertFalse(tracker.update(self.quiet))   # 1 quiet
        self.assertFalse(tracker.update(self.quiet))   # 2 quiet
        self.assertTrue(tracker.update(self.quiet))    # 3 quiet

    def test_movement_resets_the_count(self):
        # The whole point: a card still settling must not be photographed.
        tracker = camera.StillnessTracker(self.zone, required=2)
        moving = self.quiet.copy()
        moving[5:55, 5:55] = 240

        tracker.update(self.quiet)
        tracker.update(self.quiet)
        self.assertFalse(tracker.update(moving))
        self.assertEqual(tracker.quiet_frames, 0)

    def test_a_missing_frame_is_not_stillness(self):
        tracker = camera.StillnessTracker(self.zone, required=1)
        tracker.update(self.quiet)

        self.assertFalse(tracker.update(None))

    def test_only_the_zone_is_watched(self):
        # Staff walk behind the printer constantly. Motion outside the bin
        # must not stop the agent believing the picture has settled.
        zone = config.Zone(name="card", x=0.0, y=0.0, width=0.5, height=1.0)
        tracker = camera.StillnessTracker(zone, required=1)

        elsewhere = self.quiet.copy()
        elsewhere[:, 40:] = 255

        tracker.update(self.quiet)
        self.assertTrue(tracker.update(elsewhere))


class DarkPatchTest(unittest.TestCase):
    """A checkpoint on unlit plastic, which is what an operator tries first.

    Hue and saturation are both computed by dividing by the brightest channel,
    so near black a couple of counts of noise move them enormously. Observed on
    the real station: a point on the dark bin reported hue swinging across most
    of the range and saturation jumping between 0.05 and 0.15 with nothing in
    the room changing. Acting on that would stop the print queue at random.
    """

    def test_a_dark_patch_is_reported_as_having_no_usable_light(self):
        sample = camera.sample_point(solid(60, 60, (12, 12, 14)), checkpoint())

        self.assertFalse(sample.has_light())
        self.assertFalse(sample.is_colour())

    def test_a_dark_patch_never_triggers(self):
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)

        self.assertFalse(camera.checkpoint_changed(solid(60, 60, (10, 10, 12)), point))

    def test_noise_on_a_dark_patch_still_never_triggers(self):
        # The exact failure seen on hardware: neighbouring frames of the same
        # dark plastic reporting wildly different hue and saturation.
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)

        for colour in ((8, 10, 12), (12, 8, 10), (10, 12, 8), (9, 9, 14)):
            self.assertFalse(camera.checkpoint_changed(solid(60, 60, colour), point), colour)

    def test_a_lit_white_card_is_bright_but_has_no_hue(self):
        # Well lit, so its saturation is a real measurement worth comparing,
        # but its hue is undefined and must not be.
        sample = camera.sample_point(solid(60, 60, (245, 245, 245)), checkpoint())

        self.assertTrue(sample.has_light())
        self.assertFalse(sample.is_colour())

    def test_a_white_card_over_green_tape_still_triggers(self):
        # The regression guard: gating on colour rather than light would
        # suppress exactly the signal the full-tray detector depends on.
        point = checkpoint(reference_hue=60.0, reference_saturation=0.8)

        self.assertTrue(camera.checkpoint_changed(solid(60, 60, (245, 245, 245)), point))

    def test_the_sampled_brightness_is_reported(self):
        bright = camera.sample_point(solid(40, 40, (200, 200, 200)), checkpoint())
        dark = camera.sample_point(solid(40, 40, (20, 20, 20)), checkpoint())

        self.assertGreater(bright.value, 0.7)
        self.assertLess(dark.value, 0.15)




class BlankCardTest(unittest.TestCase):
    """Telling bare card stock from a printed badge.

    This is the check the whole camera exists for. The ribbon or the transfer
    film runs out, the card feeds through unprinted, and the ZXP driver reports
    it as a success exactly like any other card. Both directions matter: a
    blank card that slips through loses a badge, and a good card wrongly called
    blank stops a batch at a convention desk.
    """

    def setUp(self):
        # Deliberately the two things that must NOT read as blank, alongside
        # the one that must.
        self.blank = solid(60, 60, (238, 238, 238))          # bare white stock
        self.artwork = solid(60, 60, bgr(150, 0.55, 0.62))   # EF30 pink/blue
        self.unlit_bin = solid(60, 60, (22, 22, 24))         # dark smoked plastic

    def test_bare_card_stock_reads_blank(self):
        self.assertTrue(camera.point_is_blank(self.blank, checkpoint()))

    def test_printed_artwork_does_not_read_blank(self):
        self.assertFalse(camera.point_is_blank(self.artwork, checkpoint()))

    def test_the_unlit_bin_does_not_read_blank(self):
        # Colourless like a blank card, but dark. Brightness is what separates
        # them, and without it an empty bin would report every card as blank.
        self.assertFalse(camera.point_is_blank(self.unlit_bin, checkpoint()))

    def test_a_pale_patch_of_artwork_does_not_read_blank(self):
        # Bright like a blank card, but coloured. Saturation is what separates
        # them, and without it a badge with a pale sky in it would be condemned.
        pale = solid(60, 60, bgr(120, 0.40, 0.88))

        self.assertFalse(camera.point_is_blank(pale, checkpoint()))

    def test_no_points_calibrated_means_no_opinion(self):
        reading = camera.card_ink(self.blank, [])

        self.assertFalse(reading.has_opinion())
        # Explicitly not "the card is fine": an uncalibrated printer must not
        # report every card as verified.
        self.assertFalse(reading.blank)

    def test_disabled_points_are_not_counted(self):
        reading = camera.card_ink(self.blank, [checkpoint(enabled=False)])

        self.assertFalse(reading.has_opinion())

    def test_all_points_blank_condemns_the_card(self):
        points = [checkpoint(x=0.3), checkpoint(x=0.5), checkpoint(x=0.7)]
        reading = camera.card_ink(self.blank, points)

        self.assertTrue(reading.blank)
        self.assertEqual((reading.checked, reading.blank_points), (3, 3))

    def test_all_points_inked_passes_the_card(self):
        points = [checkpoint(x=0.3), checkpoint(x=0.5), checkpoint(x=0.7)]
        reading = camera.card_ink(self.artwork, points)

        self.assertFalse(reading.blank)
        self.assertEqual(reading.blank_points, 0)

    def test_one_stray_point_cannot_condemn_a_good_card(self):
        # The camera looks straight down and the top card rises towards the
        # lens as the stack grows, so a point near the edge of the card's
        # footprint can drift off it by the end of a batch. One such point must
        # not stop the queue.
        frame = solid(60, 60, bgr(150, 0.55, 0.62))
        frame[:, :20] = (238, 238, 238)

        points = [checkpoint(x=0.1), checkpoint(x=0.5), checkpoint(x=0.8)]
        reading = camera.card_ink(frame, points)

        self.assertEqual(reading.blank_points, 1)
        self.assertFalse(reading.blank)

    def test_a_majority_of_blank_points_condemns_the_card(self):
        frame = solid(60, 60, (238, 238, 238))
        frame[:, 48:] = bgr(150, 0.55, 0.62)

        points = [checkpoint(x=0.1), checkpoint(x=0.4), checkpoint(x=0.9)]
        reading = camera.card_ink(frame, points)

        self.assertEqual(reading.blank_points, 2)
        self.assertTrue(reading.blank)

    def test_thresholds_are_configurable(self):
        # The station's lighting is not the test's lighting, so the operator
        # has to be able to move these without a code change.
        dim_stock = solid(60, 60, (150, 150, 150))

        self.assertFalse(camera.point_is_blank(dim_stock, checkpoint()))
        self.assertTrue(
            camera.point_is_blank(dim_stock, checkpoint(), value_min=0.5))


class CardNumberOrderTest(unittest.TestCase):
    """Ordering badge ids for the session readout.

    An id is an attendee number and a badge number, so it has to be compared as
    two integers. String comparison gets the two interesting cases backwards.
    """

    def key(self, value):
        from agent.ui.console import card_number_key

        return card_number_key(value)

    def test_badge_ten_sorts_after_badge_nine(self):
        self.assertGreater(self.key("1068-10"), self.key("1068-9"))

    def test_a_longer_attendee_number_sorts_higher(self):
        self.assertGreater(self.key("1068-1"), self.key("999-1"))

    def test_the_badge_number_breaks_a_tie(self):
        self.assertGreater(self.key("1068-2"), self.key("1068-1"))

    def test_an_unparseable_id_sorts_below_everything_rather_than_raising(self):
        # Only drives a readout somebody glances at. A surprising id is not
        # worth an exception anywhere near the print path.
        self.assertLess(self.key("not-an-id"), self.key("1-1"))
        self.assertLess(self.key(""), self.key("1-1"))
        self.assertLess(self.key(None), self.key("1-1"))


class SessionTallyTest(unittest.TestCase):
    """The badge readout on the console, without needing a display."""

    def tally(self):
        from agent.ui.console import SessionTally

        return SessionTally()

    def test_it_starts_empty(self):
        subject = self.tally()

        self.assertEqual(subject.count, 0)
        self.assertEqual((subject.last, subject.highest, subject.lowest), ("", "", ""))

    def test_one_card_is_all_three(self):
        subject = self.tally()
        subject.record("1068-1")

        self.assertEqual(subject.count, 1)
        self.assertEqual(subject.last, "1068-1")
        self.assertEqual(subject.highest, "1068-1")
        self.assertEqual(subject.lowest, "1068-1")

    def test_last_follows_print_order_not_badge_order(self):
        # Batches print highest first, so "last" is normally the lowest id. It
        # is the most recent card, not the smallest number.
        subject = self.tally()

        for card in ("1068-2", "1068-1", "1040-3"):
            subject.record(card)

        self.assertEqual(subject.last, "1040-3")
        self.assertEqual(subject.highest, "1068-2")
        self.assertEqual(subject.lowest, "1040-3")

    def test_ordering_is_numeric(self):
        subject = self.tally()

        for card in ("1068-9", "1068-10", "999-1"):
            subject.record(card)

        self.assertEqual(subject.highest, "1068-10")
        self.assertEqual(subject.lowest, "999-1")

    def test_blank_ids_are_ignored(self):
        subject = self.tally()
        subject.record("")
        subject.record(None)

        self.assertEqual(subject.count, 0)

    def test_reset_clears_everything(self):
        subject = self.tally()
        subject.record("1068-1")
        subject.reset()

        self.assertEqual(subject.count, 0)
        self.assertEqual(subject.last, "")
