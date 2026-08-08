"""Deciding whether a printed card actually came out of the printer.

This is the piece the whole rework exists for. The ZXP driver reports success
whatever happened, so the only honest evidence is a picture of the output bin.

What one verification does, in order:

1. **Wait for the picture to settle.** Cards drop from the chute above and take
   a moment to land. A frame caught mid-fall has the card in the wrong place,
   so the ink points would be sampling bin rather than card.
2. **Did anything land?** The zone is compared against the reference frame
   taken before the print. A jam, an empty hopper or a print the firmware
   invented produces no change at all, and that is the failure that loses
   badges.
3. **Did it come out printed?** The calibrated ink points are sampled. A card
   the ribbon or transfer film ran out on feeds through blank, and the driver
   reports it as a success exactly like any other card.

Note what is *not* here. There is no attempt to identify which card it is: no
artwork hash, no reading the badge number. Both were tried on the real rig and
both were answering a question the agent does not have. It prints strictly one
job at a time, so the card in the bin is the card it just sent; the server
refuses stale artwork before a job is ever claimed; and two spare copies of one
badge are pixel-identical, so no image comparison could separate them even in
principle.

Nothing here may raise into the worker. A dead camera or a missing OpenCV means
*unverified*, which is the state the whole system ran in before any of this
existed. Unverified is a card a human should glance at; it is not a failure.

A **blank** card is different, and is the one verdict here that is allowed to
stop the queue: it means a consumable has run out, and every card printed after
it would be blank too.
"""

from __future__ import annotations

import time
from typing import Any, Callable, Dict, Optional

from . import camera as camera_module
from . import config as config_module


class Result:
    """What the camera concluded, in the shape the worker expects."""

    def __init__(self, confirmed: bool, detail: str = "", blank: bool = False):
        self.confirmed = bool(confirmed)
        self.detail = detail

        # Distinct from "not confirmed". An unverified card is one the camera
        # could not speak for; a blank card is one it positively identified as
        # unprinted, which means a consumable is exhausted and the batch has to
        # stop rather than carry on producing blanks.
        self.blank = bool(blank)

    def __repr__(self) -> str:
        return "Result(confirmed=%r, blank=%r, detail=%r)" % (
            self.confirmed, self.blank, self.detail)


class CameraVerifier:
    """Confirms printed cards from the output bin.

    Holds no camera of its own: frames come from whatever `FrameSource` the UI
    already has open, because two captures on one device hangs on Windows.
    """

    def __init__(
        self,
        binding,
        frames: Optional[Callable[[], Any]] = None,
        sleep: Optional[Callable[[float], None]] = None,
        clock: Optional[Callable[[], float]] = None,
    ):
        self.binding = binding
        self._frames = frames
        self._sleep = sleep or time.sleep
        self._clock = clock or time.monotonic

        # The bin as it looked before the current card. Refreshed after every
        # verification so "what changed" always means "since the last card
        # landed" rather than "since the agent started".
        self._reference = None

        # The settled frame the last verdict was reached on, kept so whoever
        # wants to show a human what the agent saw does not have to grab its
        # own frame and get a different moment.
        self.last_frame = None

    # -- what the worker asks -------------------------------------------

    @property
    def camera(self):
        return self.binding.camera

    def is_enabled(self) -> bool:
        """Whether this printer is verifying at all.

        Read fresh every time: an operator can switch the camera off mid-run
        and that has to take effect immediately, because it decides who makes
        the reprint call.
        """
        return bool(getattr(self.camera, "enabled", False)) and self._frames is not None

    def arm(self) -> None:
        """Take the before picture, immediately before a card is sent.

        Called by the worker rather than done inside `verify`, because the
        reference has to predate the print. Taking it afterwards would compare
        the bin against itself and never see a card.
        """
        frame = self._frame()

        if frame is not None:
            self._reference = frame

    def tray_full(self) -> bool:
        """Whether a tray-full checkpoint is reading as covered."""
        frame = self._frame()

        if frame is None:
            return False

        points = self.camera.checkpoints_for(config_module.POINT_TRAY_FULL)

        return any(camera_module.checkpoint_changed(frame, point) for point in points)

    def verify(self, job: Dict[str, Any]) -> Result:
        """Look at the bin and decide whether this job's card is in it."""
        zone = self.camera.card_zone()

        if zone is None:
            return Result(False, "no card zone calibrated for this printer")

        frame = self._settled_frame(zone)

        if frame is None:
            return Result(False, "no picture from the camera")

        self.last_frame = frame

        detail = []

        # 1. Did anything land? Needs a before picture to compare against; if
        #    the worker never armed us, this check is skipped rather than
        #    guessed at.
        if self._reference is not None:
            detection = camera_module.card_present(
                frame, self._reference, zone,
                threshold=getattr(self.camera, "card_detect_threshold", 0.04))

            if not detection.present:
                self._reference = frame

                return Result(False, "nothing came out of the printer (zone changed %.1f%%)"
                              % (detection.score * 100.0))

            detail.append("card seen (%.0f%% of zone changed)" % (detection.score * 100.0))

        # 2. Was it printed on?
        ink = camera_module.card_ink(
            frame,
            self.camera.checkpoints_for(config_module.POINT_CARD_INK),
            value_min=getattr(self.camera, "blank_value_min", 0.70),
            saturation_max=getattr(self.camera, "blank_saturation_max", 0.18),
            blank_fraction=getattr(self.camera, "blank_point_fraction", 0.6),
        )

        self._reference = frame

        if not ink.has_opinion():
            # No ink points calibrated. Deliberately not confirmed: the whole
            # reason this printer has a camera is to catch blank cards, and
            # reporting every card as verified without checking would be worse
            # than reporting none.
            detail.append("no ink points calibrated")

            return Result(False, ", ".join(detail))

        detail.append("%d of %d ink points blank" % (ink.blank_points, ink.checked))

        if ink.blank:
            return Result(False, "the card came out blank (%s)" % ", ".join(detail),
                          blank=True)

        return Result(True, ", ".join(detail))

    # -- internals ------------------------------------------------------

    def _frame(self):
        if self._frames is None:
            return None

        try:
            return self._frames()
        except Exception:
            return None

    def _settled_frame(self, zone):
        """Wait for the bin to stop moving, then return a frame.

        Bounded: if the picture never settles, something is moving in shot and
        the best available frame beats holding the queue.
        """
        tracker = camera_module.StillnessTracker(zone, required=STILL_FRAMES)
        deadline = self._clock() + float(getattr(self.camera, "settle_seconds", 2.0))

        latest = self._frame()

        while self._clock() < deadline:
            frame = self._frame()

            if frame is not None:
                latest = frame

                if tracker.update(frame):
                    return frame

            self._sleep(SETTLE_POLL_SECONDS)

        return latest


# Consecutive quiet frames before the picture counts as settled.
STILL_FRAMES = 3

# How often to sample while waiting for it.
SETTLE_POLL_SECONDS = 0.08
