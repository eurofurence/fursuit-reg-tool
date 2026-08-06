"""Webcam verification of cards that actually came out of the printer.

The ZXP driver reports success unconditionally, so the only honest answer to
"did a printed card come out?" comes from looking at the output bin. Two
questions are asked of the picture, in order:

1. **Did anything land?** A frame difference over the calibrated zone. A jam,
   an empty hopper or a dead print produces no change at all.
2. **Was it printed on?** Points the operator dropped on spots the artwork
   always covers. A card that comes out blank is the failure that actually
   loses badges: the ribbon or the transfer film runs out, the card feeds
   through unprinted, and the driver still reports success. Blank card stock is
   bright and colourless where the badge should be dark and saturated.

Neither question involves reading the card. Matching artwork was tried and
abandoned: every badge in a batch shares one template, the smoked bin mirrors
the cards back at the lens, and two spare copies of the same badge are
pixel-identical by definition, so a hash cannot separate them even in
principle. Reading the badge number was abandoned with it. Both were answering
"which card is this", which the agent already knows: it prints strictly one job
at a time, and the server refuses stale artwork before it ever reaches here.

Everything except `Camera` is a pure function over numpy BGR frames, so every
decision is testable without a webcam and without OpenCV. OpenCV is imported
lazily inside `Camera`; the agent must keep printing on a machine where it is
not installed, because the camera only ever adds confidence.

Hue is on OpenCV's 0-180 scale and saturation is a 0-1 fraction, matching what
calibration writes into `config.Checkpoint`. The two kinds of point treat
brightness oppositely, on purpose:

- **Tray-full points ignore it.** They are watching a fixed piece of coloured
  tape, so switching the room lights on changes value and nothing else. Acting
  on that would stop the queue every time somebody hit a light switch.
- **Ink points require it.** Bare card stock is defined by being bright, and
  the whole point is to separate it from the dark bin beneath. Brightness is
  paired with an absence of colour so that a pale patch of artwork and the
  unlit bin are each ruled out by the other half of the test.

Packaging note: opencv-python==4.5.5.64 is the last release with a working
Windows 7 wheel. Pin it when the installer is built; do not let it float.
"""

from __future__ import annotations

import sys
import time
from typing import List, NamedTuple, Optional, Tuple

import numpy as np

from . import config

# Per-pixel greyscale difference, out of 255, that counts as "this pixel
# changed". Below this is sensor noise on a cheap webcam under fluorescent
# light, not a card.
PIXEL_CHANGE_DELTA = 25.0

# Below this saturation a patch is effectively grey and its hue is whatever
# the sensor noise decided that frame. Comparing hue there produces random
# large distances, so we only trust hue when both patches have real colour.
HUE_VALID_SATURATION = 0.15

# Below this brightness a patch has no usable colour. Hue and saturation are
# both derived by dividing by the brightest channel, so on a nearly black patch
# a couple of counts of sensor noise move them enormously: pointing a
# checkpoint at dark plastic gives a hue that wanders the whole range and a
# saturation that jumps by tenths while nothing in the room has changed. The
# answer is coloured tape on the spot, not a cleverer filter.
MIN_VALID_VALUE = 0.20

# Fraction of pixels that may move between two frames and still count as a
# still picture. Cheap webcams never produce two identical frames.
STILL_THRESHOLD = 0.01


class Detection(NamedTuple):
    """Whether a card is in the zone, and how strongly."""

    present: bool
    score: float


class InkReading(NamedTuple):
    """Whether the card in the bin came out unprinted.

    `checked` is zero when no card_ink points are calibrated, which means "no
    opinion" rather than "the card is fine". Callers must not read `blank` on
    its own.
    """

    blank: bool
    checked: int
    blank_points: int

    def has_opinion(self) -> bool:
        return self.checked > 0


class Sample(NamedTuple):
    """A checkpoint patch reduced to the channels we care about.

    `value` is carried but never compared. It is here to answer a different
    question: whether this patch has enough light on it for its hue and
    saturation to mean anything at all.
    """

    hue: float          # OpenCV scale, 0-180
    saturation: float   # fraction, 0-1
    value: float = 0.0  # fraction, 0-1

    def has_light(self) -> bool:
        """Whether there is enough light here to measure anything at all.

        Hue and saturation are both derived by dividing by the brightest
        channel, so near black a couple of counts of sensor noise move them
        enormously: a checkpoint on unlit plastic reports a hue that wanders
        the whole range and a saturation that jumps by tenths with nothing in
        the room moving. Nothing measured on such a patch means anything.
        """
        return self.value >= MIN_VALID_VALUE

    def is_colour(self) -> bool:
        """Whether this patch has a *hue* worth comparing.

        Stricter than has_light(). A white card is perfectly well lit and its
        saturation is a real, usable measurement, but its hue is not: at zero
        saturation the angle is undefined. So saturation may be compared
        whenever there is light, and hue only when there is also colour.
        """
        return self.has_light() and self.saturation >= HUE_VALID_SATURATION


# --- Frame helpers ----------------------------------------------------------


def crop(frame: "np.ndarray", zone: "config.Zone") -> "np.ndarray":
    """Cut a calibrated zone out of a frame.

    Zones are stored as fractions so a calibration survives a new webcam, but
    rounding and a badly drawn rectangle can still land outside the frame. An
    out-of-bounds zone yields an empty array rather than an exception: a bad
    calibration must degrade verification, not stop the queue.
    """
    if frame is None or getattr(frame, "size", 0) == 0:
        return np.zeros((0, 0), dtype=np.uint8)

    height, width = frame.shape[:2]
    x, y, zone_width, zone_height = zone.pixels(width, height)

    x0 = max(0, min(x, width))
    y0 = max(0, min(y, height))
    x1 = max(x0, min(x + zone_width, width))
    y1 = max(y0, min(y + zone_height, height))

    return frame[y0:y1, x0:x1]


def to_gray(frame: "np.ndarray") -> "np.ndarray":
    """BGR to luma, using OpenCV's coefficients so results match cv2."""
    array = np.asarray(frame)

    if array.ndim == 2:
        return array.astype(np.float64)

    channels = array.astype(np.float64)

    return (
        channels[:, :, 0] * 0.114
        + channels[:, :, 1] * 0.587
        + channels[:, :, 2] * 0.299
    )


def to_hue_saturation(frame: "np.ndarray") -> Tuple["np.ndarray", "np.ndarray"]:
    """BGR to (hue 0-180, saturation 0-1), value discarded.

    Written out in numpy rather than calling cv2.cvtColor so the colour logic,
    which is the part that decides whether the queue stops, is testable on a
    machine with no OpenCV.
    """
    array = np.asarray(frame, dtype=np.float64)

    if array.ndim == 2:
        array = np.repeat(array[:, :, None], 3, axis=2)

    blue = array[..., 0] / 255.0
    green = array[..., 1] / 255.0
    red = array[..., 2] / 255.0

    high = np.maximum(np.maximum(blue, green), red)
    low = np.minimum(np.minimum(blue, green), red)
    span = high - low

    # Grey pixels have no hue; leave them at 0 and let saturation say so.
    safe_span = np.where(span == 0, 1.0, span)

    hue = np.zeros_like(high)
    hue = np.where(high == red, ((green - blue) / safe_span) % 6.0, hue)
    hue = np.where(high == green, ((blue - red) / safe_span) + 2.0, hue)
    hue = np.where(high == blue, ((red - green) / safe_span) + 4.0, hue)
    hue = np.where(span == 0, 0.0, hue * 30.0)  # 60 degrees per sector, halved

    saturation = np.where(high == 0, 0.0, span / np.where(high == 0, 1.0, high))

    return hue, saturation


# --- 1. Did a card appear? --------------------------------------------------


def card_present(
    frame: "np.ndarray",
    reference_frame: "np.ndarray",
    zone: "config.Zone",
    threshold: float = 0.04,
) -> Detection:
    """Compare a zone against its empty reference.

    The score is the fraction of pixels in the zone that moved by more than
    `PIXEL_CHANGE_DELTA`. A card is a large, bright, high-contrast object in a
    small rectangle, so it moves most of them; a shadow or a passing sleeve
    moves few. Only the zone is looked at, so people walking behind the
    printer are invisible to this.
    """
    current = crop(frame, zone)
    reference = crop(reference_frame, zone)

    if current.size == 0 or current.shape[:2] != reference.shape[:2]:
        # Mismatched sizes mean the reference was captured at another
        # resolution. Report nothing rather than a nonsense verdict.
        return Detection(False, 0.0)

    difference = np.abs(to_gray(current) - to_gray(reference))
    score = float(np.count_nonzero(difference > PIXEL_CHANGE_DELTA)) / difference.size

    return Detection(score >= threshold, score)


# --- 2. Did it come out printed? --------------------------------------------


def point_is_blank(
    frame: "np.ndarray",
    checkpoint: "config.Checkpoint",
    value_min: float = 0.70,
    saturation_max: float = 0.18,
) -> bool:
    """Whether the spot under a point is bare card stock.

    Both conditions have to hold, and each rules out the other's false
    positive. Brightness alone condemns a badge with a pale patch in its
    artwork; absence of colour alone condemns the unlit bin, which is dark and
    grey. Blank card stock under the bin light is the only thing in the scene
    that is bright *and* colourless at once.

    Nothing is compared against a calibrated reference, unlike the tray-full
    points. There is no such thing as a differently-coloured blank card, so
    there is nothing to calibrate: white is white.
    """
    sample = sample_point(frame, checkpoint)

    return sample.value >= value_min and sample.saturation <= saturation_max


def card_ink(
    frame: "np.ndarray",
    checkpoints: List["config.Checkpoint"],
    value_min: float = 0.70,
    saturation_max: float = 0.18,
    blank_fraction: float = 0.6,
) -> InkReading:
    """Decide whether the card in the bin came out unprinted.

    A majority of points rather than any single one. The camera looks straight
    down and the top card rises towards the lens as the stack grows, so a point
    near the edge of the card's footprint can drift off it entirely by the end
    of a batch. One stray point must not condemn a good card, and the operator
    is told to place them well inside the artwork.

    With no points calibrated this returns `checked == 0`, which means "no
    opinion". It deliberately does not mean "fine": an uncalibrated printer
    must not silently report every card as verified.
    """
    usable = [point for point in checkpoints if point.enabled]

    if not usable:
        return InkReading(False, 0, 0)

    blank_points = sum(
        1 for point in usable
        if point_is_blank(frame, point, value_min, saturation_max)
    )

    return InkReading(
        blank_points >= max(1, int(round(len(usable) * blank_fraction))),
        len(usable),
        blank_points,
    )


# --- 3. Frame orientation ----------------------------------------------------


def rotate_frame(frame: "np.ndarray", degrees: int) -> "np.ndarray":
    """Rotate clockwise by a multiple of 90.

    A webcam clamped over an output bin is rarely upright, and an operator
    watching the preview should not have to tilt their head. Restricted to
    right angles because that is all a mounting bracket ever produces, and
    because it is lossless.
    """
    turns = int(round((degrees or 0) / 90.0)) % 4

    if turns == 0:
        return frame

    # np.rot90 turns anticlockwise, so invert to make the argument mean
    # clockwise, which is how somebody describes a camera they are looking at.
    return np.ascontiguousarray(np.rot90(frame, -turns))


def frame_difference(current: "np.ndarray", previous: "np.ndarray",
                     zone: Optional["config.Zone"] = None) -> float:
    """Fraction of pixels that moved between two frames, inside the zone."""
    if current is None or previous is None:
        return 1.0

    left = crop(current, zone) if zone is not None else current
    right = crop(previous, zone) if zone is not None else previous

    if left.size == 0 or left.shape[:2] != right.shape[:2]:
        return 1.0

    difference = np.abs(to_gray(left) - to_gray(right))

    return float(np.count_nonzero(difference > PIXEL_CHANGE_DELTA)) / difference.size


class StillnessTracker:
    """Waits for the picture to stop moving before anybody reads it.

    Cards drop into the bin from the chute above and settle. A frame caught
    mid-fall has the card in the wrong place and motion-blurred, so the ink
    points are sampling whatever the card has not covered yet and a perfectly
    good card reads as blank. Requiring several consecutive quiet frames costs
    a fraction of a second and removes that whole class of false alarm.
    """

    def __init__(self, zone: Optional["config.Zone"] = None,
                 threshold: float = STILL_THRESHOLD, required: int = 3):
        self.zone = zone
        self.threshold = threshold
        self.required = max(1, int(required))

        self._previous = None
        self._quiet = 0

    def update(self, frame: "np.ndarray") -> bool:
        """Feed a frame. True once enough consecutive frames have been still."""
        if frame is None:
            self._quiet = 0
            return False

        if self._previous is None:
            self._previous = frame
            self._quiet = 0
            return False

        moved = frame_difference(frame, self._previous, self.zone)
        self._previous = frame

        if moved <= self.threshold:
            self._quiet += 1
        else:
            self._quiet = 0

        return self._quiet >= self.required

    def reset(self) -> None:
        self._previous = None
        self._quiet = 0

    @property
    def quiet_frames(self) -> int:
        return self._quiet


# --- 4. Coloured checkpoints ----------------------------------------------


def hue_distance(left: float, right: float) -> float:
    """Shortest distance between two hues on OpenCV's 0-180 wheel.

    Hue wraps: 178 and 2 are four apart, not 176. Getting this wrong reads as
    a colour change every time the tape sits near the red end of the wheel,
    which stops the queue for no reason.
    """
    return abs(_signed_hue_delta(float(left), float(right)))


def _signed_hue_delta(hue: float, reference: float) -> float:
    return ((hue - reference + 90.0) % 180.0) - 90.0


def _circular_median(hues: "np.ndarray") -> float:
    """Median hue that respects the wheel.

    A plain median of [179, 0, 1] is 1, which is fine, but of [179, 180, 1] it
    is 179 when the answer is 0. Anchor on the circular mean, take the median
    of the signed offsets from it, then rotate back.
    """
    if hues.size == 0:
        return 0.0

    angles = hues * (np.pi / 90.0)
    anchor = float(np.arctan2(np.sin(angles).mean(), np.cos(angles).mean()) * (90.0 / np.pi))

    offsets = ((hues - anchor + 90.0) % 180.0) - 90.0

    return float((anchor + np.median(offsets)) % 180.0)


def _patch(frame: "np.ndarray", checkpoint: "config.Checkpoint") -> "np.ndarray":
    """The pixels under a checkpoint, as a flat (n, 3) array.

    A disc rather than one pixel, because a single pixel on a webcam is mostly
    noise, and the operator dropped the point by hand on a preview.
    """
    array = np.asarray(frame)

    if array.size == 0:
        return np.zeros((0, 3), dtype=np.uint8)

    height, width = array.shape[:2]

    # Radius against the shorter side so the patch stays round on a 16:9 frame.
    radius = max(1.0, checkpoint.radius * min(width, height))

    centre_x = checkpoint.x * width
    centre_y = checkpoint.y * height

    x0 = max(0, int(centre_x - radius))
    y0 = max(0, int(centre_y - radius))
    x1 = min(width, int(centre_x + radius) + 1)
    y1 = min(height, int(centre_y + radius) + 1)

    if x1 <= x0 or y1 <= y0:
        return np.zeros((0, 3), dtype=np.uint8)

    box = array[y0:y1, x0:x1]

    ys = np.arange(y0, y1)[:, None] + 0.5
    xs = np.arange(x0, x1)[None, :] + 0.5
    inside = ((xs - centre_x) ** 2 + (ys - centre_y) ** 2) <= radius ** 2

    if not inside.any():
        inside = np.ones(box.shape[:2], dtype=bool)

    if box.ndim == 2:
        box = np.repeat(box[:, :, None], 3, axis=2)

    return box[inside]


def sample_point(frame: "np.ndarray", checkpoint: "config.Checkpoint") -> Sample:
    """Median hue and saturation of the patch under a checkpoint.

    Median, not mean: a couple of specular highlights or dead pixels drag a
    mean off the tape colour entirely, and this reading is what decides
    whether the output tray is declared full.
    """
    pixels = _patch(frame, checkpoint)

    if pixels.size == 0:
        return Sample(0.0, 0.0, 0.0)

    flat = pixels.reshape(-1, 1, 3)
    hue, saturation = to_hue_saturation(flat)

    # Brightest channel, which is HSV's value. Only used to decide whether the
    # hue and saturation above are worth believing.
    value = float(np.median(np.max(flat.astype(np.float64), axis=2) / 255.0))

    return Sample(_circular_median(hue.reshape(-1)), float(np.median(saturation)), value)


def checkpoint_distance(
    frame: "np.ndarray",
    checkpoint: "config.Checkpoint",
) -> Tuple[float, float]:
    """(hue distance, saturation distance) from the calibrated reference."""
    sample = sample_point(frame, checkpoint)

    return (
        hue_distance(sample.hue, checkpoint.reference_hue),
        abs(sample.saturation - checkpoint.reference_saturation),
    )


def checkpoint_changed(frame: "np.ndarray", checkpoint: "config.Checkpoint") -> bool:
    """Has the spot changed colour since calibration?

    Value is never looked at. The tray is watched by colour precisely so that
    somebody hitting the light switch, or the sun coming round to that side of
    the hall, does not read as a full tray and stop the queue.

    Hue only counts when both the reference and the sample actually have
    colour: the hue of a grey patch is noise, and comparing it produces
    spurious changes.
    """
    if not checkpoint.enabled or not checkpoint.calibrated:
        return False

    sample = sample_point(frame, checkpoint)

    # Brightness first, and darkness is never a reason to stop looking.
    #
    # This used to bail out on any dark patch, on the grounds that hue and
    # saturation are noise without light. True, but it threw away the one
    # signal that actually works over an output bin: the bin is black and a
    # card is not. Bailing out meant a point there could never see a card
    # arrive, and -- if calibrated with a card present -- never see one leave.
    if checkpoint.reference_value is not None:
        if abs(sample.value - checkpoint.reference_value) > checkpoint.value_tolerance:
            return True

    # Saturation is a real measurement wherever there is light, and it is the
    # signal that matters most here: a white card dropping onto green tape
    # barely moves the hue but collapses the saturation.
    # Colour comparisons still need light on both sides: the hue of an unlit
    # patch wanders the whole range on sensor noise alone.
    if not sample.has_light():
        return False

    if abs(sample.saturation - checkpoint.reference_saturation) > checkpoint.saturation_tolerance:
        return True

    coloured = (
        sample.is_colour()
        and checkpoint.reference_saturation >= HUE_VALID_SATURATION
    )

    if coloured and hue_distance(sample.hue, checkpoint.reference_hue) > checkpoint.hue_tolerance:
        return True

    return False


# --- 5. Debouncing ----------------------------------------------------------


class CheckpointTracker:
    """Holds a checkpoint's state until enough frames agree.

    A hand reaching into the chute changes the colour under the point for a
    few frames. Acting on one frame would stop the print queue every time an
    operator collected a stack of cards, so a change has to hold for
    `consecutive_frames` before it counts, and one disagreeing frame resets
    the run.
    """

    def __init__(self, checkpoint: "config.Checkpoint", initial: bool = False):
        self.checkpoint = checkpoint
        self.state = initial
        self.streak = 0

    def observe(self, changed: bool) -> bool:
        """Feed one verdict in, get the debounced state out."""
        changed = bool(changed)

        if changed == self.state:
            self.streak = 0
            return self.state

        self.streak += 1

        if self.streak >= max(1, self.checkpoint.consecutive_frames):
            self.state = changed
            self.streak = 0

        return self.state

    def update(self, frame: "np.ndarray") -> bool:
        """Sample a frame and fold the result in."""
        return self.observe(checkpoint_changed(frame, self.checkpoint))

    def reset(self, state: bool = False) -> None:
        self.state = bool(state)
        self.streak = 0


def trackers_for(checkpoints: List["config.Checkpoint"]) -> List[CheckpointTracker]:
    return [CheckpointTracker(checkpoint) for checkpoint in checkpoints]


# --- 6. The webcam itself ---------------------------------------------------


def capture_backend(cv2_module) -> int:
    """Which OpenCV capture backend to open cameras with.

    DirectShow on Windows, the library default everywhere else.

    OpenCV prefers Media Foundation on Windows, and on Windows 7 that is a dead
    end: measured on the print station, MSMF opened all three attached cameras
    and then read a frame from none of them, failing with "unsupported media
    type" for RGB24. DirectShow read every one of them at 640x480. Media
    Foundation on Windows 7 predates the camera stack OpenCV expects.
    """
    if sys.platform.startswith("win"):
        return getattr(cv2_module, "CAP_DSHOW", 0)

    return getattr(cv2_module, "CAP_ANY", 0)


def list_cameras(max_index: int = 8) -> "list":
    """Find the cameras attached to this machine.

    Returned as (index, label) pairs so the UI can offer a dropdown instead of
    asking an operator to guess a device number. OpenCV exposes no friendly
    device names, so the label carries the resolution, which is enough to tell
    a built-in webcam from the one pointed at the card hopper.

    Probing means briefly opening each device, so this is for the settings
    screen, not for anything on the printing path. Returns an empty list when
    OpenCV is missing rather than raising: the camera is optional.
    """
    try:
        import cv2  # type: ignore
    except ImportError:
        return []

    found = []

    for index in range(max_index):
        capture = None
        try:
            capture = cv2.VideoCapture(index, capture_backend(cv2))
            if not capture.isOpened():
                continue

            ok, frame = capture.read()
            if not ok or frame is None:
                continue

            height, width = frame.shape[:2]
            found.append((index, "Camera %d  (%dx%d)" % (index, width, height)))
        except Exception:
            # A device that refuses to open is simply not offered.
            continue
        finally:
            if capture is not None:
                capture.release()

    return found


class Camera:
    """Thin wrapper over cv2.VideoCapture.

    cv2 is imported inside the methods so importing this module, and running
    its tests, works on a machine with no OpenCV. `open()` is the one place
    that raises: everything after it returns None on failure, because a camera
    that dies mid-convention must degrade to unverified printing rather than
    take the agent down.
    """

    def __init__(self, device_index: int = 0, warmup_frames: int = 3):
        self.device_index = device_index
        self.warmup_frames = warmup_frames
        self._capture = None

    def open(self) -> "Camera":
        try:
            import cv2  # type: ignore
        except ImportError as error:
            raise RuntimeError(
                "OpenCV is not installed, so camera verification cannot start. "
                "Install opencv-python==4.5.5.64 (the last build with a working "
                "Windows 7 wheel)."
            ) from error

        capture = cv2.VideoCapture(self.device_index, capture_backend(cv2))

        if not capture.isOpened():
            capture.release()
            raise RuntimeError(
                "Camera %d could not be opened. Check it is plugged in and that "
                "nothing else is holding it." % self.device_index
            )

        self._capture = capture

        # The first frames off a cheap webcam are black or still auto-exposing,
        # and one of them would otherwise become the empty-chute reference.
        for _ in range(self.warmup_frames):
            capture.read()

        return self

    def is_open(self) -> bool:
        return self._capture is not None

    def set_focus(self, value: int) -> bool:
        """Move the lens to an absolute focus setting.

        Autofocus is switched off first. Left on, the camera hunts every time a
        card lands and settles on the bin rather than the card, which is the
        thing we need sharp.
        """
        if self._capture is None:
            return False

        try:
            import cv2  # type: ignore
        except ImportError:
            return False

        try:
            self._capture.set(cv2.CAP_PROP_AUTOFOCUS, 0)

            return bool(self._capture.set(cv2.CAP_PROP_FOCUS, float(value)))
        except Exception:
            return False

    def get_focus(self) -> Optional[float]:
        if self._capture is None:
            return None

        try:
            import cv2  # type: ignore

            return float(self._capture.get(cv2.CAP_PROP_FOCUS))
        except Exception:
            return None

    def open_settings(self) -> bool:
        """Show the camera driver's own settings dialog.

        This is the DirectShow property pages: the Zoom, Focus, Exposure and
        Low Light Compensation panel that OBS puts behind its Configure Video
        button. It belongs to the driver, not to us, which is exactly why it is
        worth opening rather than reimplementing: whatever the webcam supports
        appears, including the controls that decide whether a card in a dim
        output bin is legible at all.

        Must be called on the thread that owns the capture, and blocks there
        until the operator closes it, so the caller should be the capture
        thread rather than the UI. Returns False when the backend has no such
        dialog.
        """
        if self._capture is None:
            return False

        try:
            import cv2  # type: ignore
        except ImportError:
            return False

        try:
            return bool(self._capture.set(cv2.CAP_PROP_SETTINGS, 1))
        except Exception:
            return False

    def read(self) -> Optional["np.ndarray"]:
        """One frame, or None if the camera is shut or the grab failed."""
        if self._capture is None:
            return None

        try:
            ok, frame = self._capture.read()
        except Exception:
            return None

        if not ok or frame is None:
            return None

        return frame

    def close(self) -> None:
        if self._capture is None:
            return

        try:
            self._capture.release()
        except Exception:
            pass

        self._capture = None

    def __enter__(self) -> "Camera":
        return self.open()

    def __exit__(self, *_) -> bool:
        self.close()
        return False
