"""The verdict layer, exercised with no webcam and no OpenCV.

`CameraVerifier` is what decides whether the server is told a card printed, so
every branch here is a decision somebody at a convention desk lives with. The
three outcomes are deliberately distinct and are all pinned down below:

- **confirmed** - a card landed and it has ink on it.
- **unverified** - the camera could not speak for it. Not a failure; it is the
  state the whole system ran in before any of this existed.
- **blank** - the camera positively identified bare card stock. This one is
  allowed to stop the queue, because a consumable has run out and every card
  after it would be blank too.
"""

import os
import sys
import unittest

import numpy as np

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import config, verifier  # noqa: E402


def solid(colour, height=80, width=80):
    return np.full((height, width, 3), colour, dtype=np.uint8)


BLANK_STOCK = (238, 238, 238)
ARTWORK = (150, 70, 190)      # a saturated pink, like the EF30 badge
EMPTY_BIN = (24, 24, 26)      # dark smoked plastic


class Binding:
    """The bit of a PrinterBinding the verifier actually touches."""

    def __init__(self, camera):
        self.camera = camera


def camera_config(points=(), **kwargs):
    settings = dict(
        enabled=True,
        zones=[config.Zone(name="bin", x=0.0, y=0.0, width=1.0, height=1.0)],
        checkpoints=list(points),
        settle_seconds=0.0,
    )
    settings.update(kwargs)

    return config.CameraConfig(**settings)


def ink_point(**kwargs):
    defaults = dict(purpose=config.POINT_CARD_INK, x=0.5, y=0.5, radius=0.1)
    defaults.update(kwargs)

    return config.Checkpoint(**defaults)


def build(frames, points=(), **kwargs):
    """A verifier fed a fixed list of frames, newest last."""
    supply = list(frames)

    def next_frame():
        return supply[-1] if len(supply) == 1 else supply.pop(0)

    return verifier.CameraVerifier(
        Binding(camera_config(points, **kwargs)),
        frames=next_frame,
        sleep=lambda _seconds: None,
        clock=lambda: 0.0,
    )


class EnabledTest(unittest.TestCase):
    def test_a_printer_with_the_camera_switched_off_does_not_verify(self):
        subject = build([solid(EMPTY_BIN)])
        subject.camera.enabled = False

        self.assertFalse(subject.is_enabled())

    def test_the_switch_is_read_fresh_every_time(self):
        # An operator turning the camera off mid-run has to take effect at
        # once: it decides who makes the reprint call.
        subject = build([solid(EMPTY_BIN)])

        self.assertTrue(subject.is_enabled())

        subject.camera.enabled = False

        self.assertFalse(subject.is_enabled())

    def test_no_frame_source_is_not_enabled(self):
        subject = verifier.CameraVerifier(Binding(camera_config()), frames=None)

        self.assertFalse(subject.is_enabled())


class VerifyTest(unittest.TestCase):
    def test_an_uncalibrated_printer_is_never_confirmed(self):
        subject = build([solid(BLANK_STOCK)])
        subject.camera.zones = []

        result = subject.verify({})

        self.assertFalse(result.confirmed)
        self.assertIn("no card zone", result.detail)

    def test_a_dead_camera_is_unverified_not_a_failure(self):
        subject = verifier.CameraVerifier(
            Binding(camera_config()), frames=lambda: None,
            sleep=lambda _s: None, clock=lambda: 0.0)

        result = subject.verify({})

        self.assertFalse(result.confirmed)
        self.assertFalse(result.blank)

    def test_a_printed_card_is_confirmed(self):
        subject = build([solid(ARTWORK)], points=[ink_point()])

        result = subject.verify({})

        self.assertTrue(result.confirmed)
        self.assertFalse(result.blank)

    def test_a_blank_card_is_reported_blank_and_not_confirmed(self):
        subject = build([solid(BLANK_STOCK)], points=[ink_point()])

        result = subject.verify({})

        self.assertFalse(result.confirmed)
        self.assertTrue(result.blank)
        self.assertIn("blank", result.detail)

    def test_without_ink_points_a_card_is_not_confirmed(self):
        # The whole reason this printer has a camera is to catch blank cards.
        # Reporting every card as verified without checking would be worse than
        # reporting none.
        subject = build([solid(ARTWORK)])

        result = subject.verify({})

        self.assertFalse(result.confirmed)
        self.assertFalse(result.blank)
        self.assertIn("no ink points", result.detail)


class PresenceTest(unittest.TestCase):
    """The before/after comparison that catches a card never arriving."""

    def test_nothing_arriving_is_not_confirmed(self):
        subject = build([solid(EMPTY_BIN)], points=[ink_point()])
        subject.arm()

        result = subject.verify({})

        self.assertFalse(result.confirmed)
        self.assertIn("nothing came out", result.detail)

    def test_a_jam_is_not_reported_as_a_blank_card(self):
        # Distinct faults. A jam needs the card clearing; an empty ribbon needs
        # a consumable changing. Reporting one as the other sends an operator
        # to the wrong end of the machine.
        subject = build([solid(EMPTY_BIN)], points=[ink_point()])
        subject.arm()

        self.assertFalse(subject.verify({}).blank)

    def test_a_card_landing_on_the_empty_bin_is_confirmed(self):
        subject = build([solid(EMPTY_BIN), solid(ARTWORK)], points=[ink_point()])
        subject.arm()

        result = subject.verify({})

        self.assertTrue(result.confirmed)

    def test_a_blank_card_landing_is_caught_even_though_it_arrived(self):
        # Presence alone would pass this: something certainly landed. It is the
        # ink check that catches it, and this is the exact failure that lost
        # badges.
        subject = build([solid(EMPTY_BIN), solid(BLANK_STOCK)], points=[ink_point()])
        subject.arm()

        result = subject.verify({})

        self.assertFalse(result.confirmed)
        self.assertTrue(result.blank)

    def test_without_arming_presence_is_skipped_rather_than_guessed(self):
        # No before picture means the question cannot be answered. The ink
        # check still runs and still decides.
        subject = build([solid(ARTWORK)], points=[ink_point()])

        self.assertTrue(subject.verify({}).confirmed)

    def test_the_reference_advances_so_the_next_card_is_compared_to_this_one(self):
        subject = build([solid(EMPTY_BIN), solid(ARTWORK)], points=[ink_point()])
        subject.arm()
        subject.verify({})

        # The bin now holds a card, and that is what the next print must be
        # measured against. Comparing against the empty bin forever would call
        # every later card present no matter what happened.
        self.assertTrue(np.array_equal(subject._reference, solid(ARTWORK)))


class TrayFullTest(unittest.TestCase):
    def test_an_uncalibrated_tray_point_never_stops_the_queue(self):
        point = config.Checkpoint(purpose=config.POINT_TRAY_FULL, calibrated=False)
        subject = build([solid(ARTWORK)], points=[point])

        self.assertFalse(subject.tray_full())

    def test_a_dead_camera_does_not_report_a_full_tray(self):
        subject = verifier.CameraVerifier(
            Binding(camera_config()), frames=lambda: None)

        self.assertFalse(subject.tray_full())


if __name__ == "__main__":
    unittest.main()
