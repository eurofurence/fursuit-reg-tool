"""Live camera preview, and the page where an operator calibrates it.

Two jobs live here. The first is getting webcam frames onto a Tk canvas at all:
there is no Pillow on the target machine and there is not going to be, so a BGR
numpy frame is downscaled and handed to Tk as a PPM, which Tk can read by
itself. `FrameSource` does the grabbing on its own thread so a camera that has
been unplugged, or taken by another program, turns into a message on screen
rather than a frozen UI. The console camera panel uses both of those too.

The second is the calibration page. Everything the camera decides depends on
rectangles and points that somebody drew by hand at a convention desk, so the
page has to show the picture, the shapes on top of it, and - for the coloured
points - whether the spot is reading as changed *right now*. Waving a card over
the chute and watching the indicator flip is the only way an operator can
believe the full-tray detector before it stops the print queue for real.

Geometry is stored as fractions of the frame, never pixels, so swapping the
webcam or its resolution does not silently move every zone. The arithmetic that
does that conversion is plain functions at the top of this module, tested
without a display.

Nothing here may raise because the camera is missing. No OpenCV, no numpy, no
camera plugged in: the page still opens and says so.
"""

from __future__ import annotations

import threading
import tkinter as tk
from dataclasses import replace
from tkinter import ttk
from typing import Callable, List, Optional, Sequence, Tuple

from .. import config as config_module
from ..config import Checkpoint, Zone

# A drag that barely moved is a slip of the hand, not a zone. Keeping a floor
# under the size also stops a zero-area rectangle reaching the verifier, where
# it would crop to nothing and quietly report "no card" forever.
MIN_SIDE = 0.01

# How close to a corner counts as grabbing its resize handle.
HANDLE_PIXELS = 9.0

# One zone, over the output bin. Cards stack up in it and the top one rises
# towards the lens, so there is nothing stationary to draw a smaller rectangle
# around; the one region answers the one question, did something land.
ZONE_PURPOSES = [config_module.ZONE_CARD]

POINT_PURPOSES = [
    config_module.POINT_CARD_INK,
    config_module.POINT_TRAY_FULL,
]

# Purposes whose verdict is a comparison against a colour captured while the
# bin was in a known state, and which therefore have to be calibrated before
# they mean anything.
#
# Ink points are not among them and never need calibrating: they ask whether a
# spot is bare card stock, which is an absolute question. There is no such
# thing as a differently-coloured blank card, so there is nothing to store.
REFERENCE_POINT_PURPOSES = {config_module.POINT_TRAY_FULL}

# Purposes an operator may drop more than one point for.
#
# Everything else is one-per-purpose: two points both claiming to be the
# tray-full sensor is a calibration mistake, and the second silently replaces
# the first. Ink points are the exception by design. The verdict is a majority
# of them precisely so that one point drifting off the card as the stack rises
# cannot condemn a good card on its own, and a majority of one is no majority.
MULTI_POINT_PURPOSES = {config_module.POINT_CARD_INK}

ZONE_COLOUR = "#38d16a"
POINT_COLOUR = "#ffb020"
UNCALIBRATED_COLOUR = "#a81f1f"
CHANGED_COLOUR = "#ff4d4d"
SELECTED_COLOUR = "#ffd633"
MUTED_COLOUR = "#7a7a7a"
STEADY_COLOUR = "#1b7f3b"


# --- Geometry ---------------------------------------------------------------


def clamp01(value: float) -> float:
    return 0.0 if value < 0.0 else (1.0 if value > 1.0 else float(value))


def rect_fractions(x0, y0, x1, y1, width, height) -> Tuple[float, float, float, float]:
    """A rectangle dragged in canvas pixels, as (x, y, w, h) fractions.

    Accepts the corners in any order, because operators drag right-to-left as
    often as not, and keeps the result inside the frame: a zone hanging off the
    edge crops to less than the operator drew and makes detection look flaky.
    """
    width = float(width) if width else 1.0
    height = float(height) if height else 1.0

    left, right = (x0, x1) if x0 <= x1 else (x1, x0)
    top, bottom = (y0, y1) if y0 <= y1 else (y1, y0)

    x = clamp01(left / width)
    y = clamp01(top / height)

    zone_width = max(MIN_SIDE, clamp01(right / width) - x)
    zone_height = max(MIN_SIDE, clamp01(bottom / height) - y)

    return (min(x, 1.0 - zone_width), min(y, 1.0 - zone_height), zone_width, zone_height)


def rect_pixels(zone: Zone, width, height) -> Tuple[float, float, float, float]:
    """(left, top, right, bottom) in canvas pixels.

    Floats, unlike `Zone.pixels`, which rounds because it is indexing a numpy
    array. Here the numbers only have to land on a canvas.
    """
    left = zone.x * width
    top = zone.y * height

    return (left, top, left + zone.width * width, top + zone.height * height)


def point_fractions(x, y, width, height) -> Tuple[float, float]:
    width = float(width) if width else 1.0
    height = float(height) if height else 1.0

    return (clamp01(x / width), clamp01(y / height))


def point_pixels(checkpoint: Checkpoint, width, height) -> Tuple[float, float]:
    return (checkpoint.x * width, checkpoint.y * height)


def point_radius_pixels(checkpoint: Checkpoint, width, height) -> float:
    """The drawn radius, matching the patch `camera._patch` actually samples.

    Against the shorter side, so the circle on screen is the same disc the
    sampler reads. Drawing it against the width instead would show the operator
    a ring wider than the pixels being measured on a 16:9 frame.
    """
    return max(3.0, checkpoint.radius * min(float(width), float(height)))


def handle_at(zone: Zone, x, y, width, height, tolerance=HANDLE_PIXELS) -> Optional[str]:
    """Which corner handle, if any, is under the pointer."""
    left, top, right, bottom = rect_pixels(zone, width, height)

    for name, corner_x, corner_y in (
        ("nw", left, top), ("ne", right, top),
        ("sw", left, bottom), ("se", right, bottom),
    ):
        if abs(x - corner_x) <= tolerance and abs(y - corner_y) <= tolerance:
            return name

    return None


def zone_at(zones: Sequence[Zone], x, y, width, height) -> Optional[Zone]:
    """The topmost zone under the pointer.

    Last drawn wins, matching what the operator sees when zones overlap.
    """
    for zone in reversed(list(zones)):
        left, top, right, bottom = rect_pixels(zone, width, height)

        if left <= x <= right and top <= y <= bottom:
            return zone

    return None


def checkpoint_at(
    checkpoints: Sequence[Checkpoint], x, y, width, height, tolerance=HANDLE_PIXELS
) -> Optional[Checkpoint]:
    """The topmost point under the pointer.

    The grab area is the drawn ring plus a margin, because a point is a few
    pixels across and nobody clicks that accurately on a remote session.
    """
    for checkpoint in reversed(list(checkpoints)):
        centre_x, centre_y = point_pixels(checkpoint, width, height)
        reach = point_radius_pixels(checkpoint, width, height) + tolerance

        if (x - centre_x) ** 2 + (y - centre_y) ** 2 <= reach ** 2:
            return checkpoint

    return None


def moved_zone(zone: Zone, dx, dy, width, height) -> Tuple[float, float]:
    """New (x, y) after dragging a zone, kept wholly inside the frame."""
    width = float(width) if width else 1.0
    height = float(height) if height else 1.0

    x = zone.x + dx / width
    y = zone.y + dy / height

    return (
        min(max(0.0, x), max(0.0, 1.0 - zone.width)),
        min(max(0.0, y), max(0.0, 1.0 - zone.height)),
    )


def resized_zone(zone: Zone, handle: str, x, y, width, height):
    """New (x, y, w, h) after dragging one corner; the opposite one stays put."""
    left, top, right, bottom = rect_pixels(zone, width, height)

    anchor_x = right if "w" in handle else left
    anchor_y = bottom if "n" in handle else top

    return rect_fractions(anchor_x, anchor_y, x, y, width, height)


# --- Talking to the camera module -------------------------------------------


def camera_module():
    """The camera module, or None on a machine without it.

    numpy and OpenCV are both optional on the print station: the camera only
    ever adds confidence, and the agent has to run without it. Everything below
    checks for None rather than letting an ImportError out of a button handler.
    """
    try:
        from .. import camera as module
    except Exception:
        return None

    return module


def calibrate_checkpoint(checkpoint: Checkpoint, frame) -> bool:
    """Store the colour currently under a point as its empty-tray reference.

    Done with the tray empty, which the page says in as many words: whatever is
    under the point at this moment becomes "normal", and anything else becomes
    "the tray is full, stop claiming cards".
    """
    module = camera_module()

    if module is None or frame is None:
        return False

    sample = module.sample_point(frame, checkpoint)

    checkpoint.reference_hue = float(sample.hue)
    checkpoint.reference_saturation = float(sample.saturation)
    checkpoint.calibrated = True

    return True


def needs_reference(checkpoint: Checkpoint) -> bool:
    """Whether this point's verdict is a comparison against a stored colour."""
    return checkpoint.purpose in REFERENCE_POINT_PURPOSES


def _calibration_label(checkpoint: Checkpoint) -> str:
    if not needs_reference(checkpoint):
        return "no reference needed"

    return "calibrated" if checkpoint.calibrated else "NOT CALIBRATED"


def _ink_indicator(checkpoint: Checkpoint, frame) -> Tuple[str, str, bool]:
    """Live readout for an ink point: is this spot reading as bare card stock?

    Shown while calibrating so an operator can drop a point, hold a printed
    card under the lens and see "ink" and then a blank one and see "BLANK".
    Without that there is no way to know a point is even on the card until a
    batch goes wrong.
    """
    module = camera_module()

    if module is None or frame is None:
        return ("no picture", MUTED_COLOUR, False)

    sample = module.sample_point(frame, checkpoint)
    blank = module.point_is_blank(frame, checkpoint)

    text = "%s  value %.2f  sat %.2f" % (
        "BLANK CARD" if blank else "ink", sample.value, sample.saturation)

    return (text, CHANGED_COLOUR if blank else STEADY_COLOUR, blank)


def checkpoint_indicator(checkpoint: Checkpoint, frame) -> Tuple[str, str, bool]:
    """(text, colour, changed) for the live readout on one point.

    The numbers are shown next to the verdict on purpose. "CHANGED" alone tells
    an operator nothing about how close to the edge the reading is, and whether
    the tolerance wants widening before the hall lights change again.
    """
    if not checkpoint.enabled:
        return ("switched off", MUTED_COLOUR, False)

    if checkpoint.purpose == config_module.POINT_CARD_INK:
        return _ink_indicator(checkpoint, frame)

    if not checkpoint.calibrated:
        return ("not calibrated yet", UNCALIBRATED_COLOUR, False)

    module = camera_module()

    if module is None or frame is None:
        return ("no camera", MUTED_COLOUR, False)

    sample = module.sample_point(frame, checkpoint)

    # A dark patch has no usable colour: hue is an angle around a cone whose tip
    # is black, so near the tip it wanders the whole range on sensor noise
    # alone, and saturation swings by tenths with nothing moving. Say so plainly
    # rather than showing numbers that look like a live measurement, because the
    # fix is physical.
    if not sample.has_light():
        return (
            "too dark to read a colour here (brightness %.2f, saturation %.2f). "
            "Stick coloured tape on this spot, or light it better."
            % (sample.value, sample.saturation),
            UNCALIBRATED_COLOUR,
            False,
        )

    hue_gap, saturation_gap = module.checkpoint_distance(frame, checkpoint)
    changed = module.checkpoint_changed(frame, checkpoint)

    text = "%s   hue %.0f of %.0f   sat %.2f of %.2f   bright %.2f" % (
        "CHANGED" if changed else "matches reference",
        hue_gap, checkpoint.hue_tolerance,
        saturation_gap, checkpoint.saturation_tolerance,
        sample.value,
    )

    return (text, CHANGED_COLOUR if changed else STEADY_COLOUR, changed)


# --- Frames onto a canvas ----------------------------------------------------


def frame_to_ppm(frame, width: int, height: int) -> Optional[bytes]:
    """A BGR frame as binary PPM at the requested size, or None.

    Tk reads PPM natively, which is how a webcam frame reaches a canvas on a
    machine with no imaging library installed. Downscaling happens here rather
    than in Tk because pushing a full 1280x720 frame through the Tcl
    interpreter several times a second is what makes these previews crawl.

    Nearest-neighbour sampling: this is a picture for a human to look at, not
    an image anything measures, and it runs on every frame.
    """
    try:
        import numpy as np
    except ImportError:
        return None

    if frame is None:
        return None

    array = np.asarray(frame)

    if array.size == 0:
        return None

    if array.ndim == 2:
        array = np.repeat(array[:, :, None], 3, axis=2)

    source_height, source_width = array.shape[:2]

    width = max(1, int(width))
    height = max(1, int(height))

    rows = np.minimum((np.arange(height) * (source_height / float(height))).astype(int),
                      source_height - 1)
    columns = np.minimum((np.arange(width) * (source_width / float(width))).astype(int),
                         source_width - 1)

    small = array[rows[:, None], columns[None, :]]

    # BGR to RGB, dropping any alpha channel a capture device decided to add.
    rgb = np.ascontiguousarray(small[:, :, :3][:, :, ::-1].astype(np.uint8))

    return b"P6\n%d %d\n255\n" % (width, height) + rgb.tobytes()


def photo_from_frame(frame, width: int, height: int, master=None):
    """A tkinter PhotoImage of a frame, or None if that is not possible."""
    data = frame_to_ppm(frame, width, height)

    if data is None:
        return None

    try:
        return tk.PhotoImage(master=master, data=data)
    except Exception:
        # A Tk build that cannot read PPM would be a surprise, but a blank
        # preview beats an exception inside the redraw timer.
        return None


class FrameSource:
    """A webcam, read on its own thread.

    Reading a frame blocks for as long as the device feels like, so it never
    happens on the Tk thread: the UI reads whatever the last frame was and
    carries on. The retry policy lives here as well, because a camera at a
    convention gets knocked out of its socket, and the agent has to notice, say
    so, and pick it back up when somebody plugs it in again.
    """

    RETRY_SECONDS = 3.0
    LOST_AFTER = 5

    # Fast retries before falling back to RETRY_SECONDS. Covers the moment just
    # after another part of the UI released the device, where waiting three
    # seconds would show a blank preview for no reason.
    QUICK_RETRIES = 5

    def __init__(self, device_index: int = 0, interval: float = 0.08,
                 open_camera: Optional[Callable] = None):
        self.device_index = device_index
        self.interval = interval

        # Injectable so the retry behaviour can be tested with no webcam.
        self._open_camera = open_camera or self._open_default

        self._frame = None
        self._status = "Camera off"
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self._thread = None

        # Set by the UI, acted on by the capture thread. The driver's settings
        # dialog is modal to whichever thread opens it, and it owns the device,
        # so it has to be the grab loop: opening it from the Tk thread would
        # freeze the whole application behind a dialog belonging to a webcam.
        self._settings_wanted = threading.Event()

    def _open_default(self, device_index: int):
        module = camera_module()

        if module is None:
            raise RuntimeError(
                "OpenCV is not installed on this machine, so there is no camera preview.")

        return module.Camera(device_index).open()

    # -- state, read from the Tk thread ------------------------------------

    def latest(self):
        with self._lock:
            return self._frame

    def status(self) -> str:
        with self._lock:
            return self._status

    def is_live(self) -> bool:
        return self.latest() is not None

    def request_settings(self) -> bool:
        """Ask the capture thread to show the driver's settings dialog.

        Returns immediately. The dialog appears on the grab thread, so the UI
        stays interactive while somebody drags the exposure slider and watches
        what it does.
        """
        if self._thread is None:
            return False

        self._settings_wanted.set()

        return True

    def _set_frame(self, frame) -> None:
        with self._lock:
            self._frame = frame

    def _set_status(self, message: str) -> None:
        with self._lock:
            self._status = message

    # -- lifecycle ---------------------------------------------------------

    def start(self) -> "FrameSource":
        if self._thread is not None:
            return self

        self._stop.clear()
        self._set_status("Opening camera %d" % self.device_index)

        self._thread = threading.Thread(target=self._run, name="camera-preview")
        self._thread.daemon = True
        self._thread.start()

        return self

    # A blocking read on Windows DirectShow can sit for a second or more, and
    # the device is not free until the loop past it has run its release. Give
    # the thread room to finish rather than handing a still-held camera to
    # whoever asked for it next.
    STOP_TIMEOUT = 6.0

    def stop(self, timeout: Optional[float] = None) -> bool:
        """Stop grabbing and release the device.

        Called on window close, and before handing the camera to another part
        of the UI: two cv2 captures on one device is a hang on Windows.

        Returns whether the capture thread actually finished. False means the
        device may still be held, which is worth knowing before opening it
        again: it is the difference between a preview that is briefly late and
        one that never appears.
        """
        self._stop.set()

        thread = self._thread
        self._thread = None

        released = True

        if thread is not None:
            thread.join(timeout=self.STOP_TIMEOUT if timeout is None else timeout)
            released = not thread.is_alive()

        self._set_frame(None)
        self._set_status("Camera off" if released else "Camera still releasing")

        return released

    def _run(self) -> None:
        device = None
        failures = 0

        open_attempts = 0

        while not self._stop.is_set():
            if device is None:
                try:
                    device = self._open_camera(self.device_index)
                except Exception as error:
                    open_attempts += 1
                    self._set_frame(None)
                    self._set_status(str(error) or "Camera %d unavailable" % self.device_index)

                    # The usual reason the first attempt fails is that another
                    # part of this UI has only just let go of the device, so try
                    # again quickly a few times before settling into the slow
                    # retry meant for a camera nobody has plugged in.
                    self._stop.wait(
                        0.4 if open_attempts <= self.QUICK_RETRIES else self.RETRY_SECONDS)
                    continue

                open_attempts = 0
                failures = 0

            if self._settings_wanted.is_set():
                self._settings_wanted.clear()
                self._set_status("Camera settings open; preview paused")

                try:
                    device.open_settings()
                except Exception:
                    pass

                # Frames stop while the dialog is up, because the driver has the
                # device. The application itself stays usable, which is the part
                # that matters.
                continue

            try:
                frame = device.read()
            except Exception:
                frame = None

            if frame is None:
                failures += 1

                if failures >= self.LOST_AFTER:
                    # Unplugged, asleep, or another program grabbed it. Drop the
                    # handle and start again rather than sit on a dead device.
                    self._release(device)
                    device = None
                    failures = 0

                    self._set_frame(None)
                    self._set_status(
                        "Camera %d stopped answering. Check it is plugged in; "
                        "retrying." % self.device_index)
                    self._stop.wait(self.RETRY_SECONDS)
                    continue
            else:
                failures = 0
                self._set_frame(frame)
                self._set_status("Camera %d live" % self.device_index)

            self._stop.wait(self.interval)

        self._release(device)

    @staticmethod
    def _release(device) -> None:
        if device is None:
            return

        try:
            device.close()
        except Exception:
            pass


# --- The calibration being edited -------------------------------------------


class CalibrationState:
    """Zones and points as the operator is currently arranging them.

    Deliberately a copy of what is in the config. Half-finished calibration
    must not reach the verifier while the page is open, and Cancel has to mean
    cancel: the printer keeps running off the saved calibration until somebody
    presses Save.
    """

    def __init__(self, camera_config: config_module.CameraConfig):
        self.zones: List[Zone] = [replace(zone) for zone in camera_config.zones]
        self.checkpoints: List[Checkpoint] = [
            replace(point) for point in camera_config.checkpoints
        ]
        self.selected = None

    # -- adding and removing -----------------------------------------------

    def add_zone(self, purpose: str, x: float, y: float,
                 width: float, height: float) -> Zone:
        """Add a zone, replacing any existing one with the same purpose.

        One zone per purpose per printer. Two rectangles both claiming to be
        where the card lands is not a richer configuration, it is an ambiguity
        somebody has to resolve at 3am; redrawing simply moves the zone.
        """
        self.replace_purpose(self.zones, purpose)

        zone = Zone(name=self.next_name(purpose), purpose=purpose,
                    x=x, y=y, width=width, height=height)

        self.zones.append(zone)
        self.selected = zone

        return zone

    def add_checkpoint(self, purpose: str, x: float, y: float) -> Checkpoint:
        """Add a point, replacing any existing one with the same purpose.

        Ink points are exempt and accumulate instead: their verdict is a
        majority, so replacing the previous one on every drop would leave the
        operator permanently unable to calibrate more than one.
        """
        if purpose not in MULTI_POINT_PURPOSES:
            self.replace_purpose(self.checkpoints, purpose)

        checkpoint = Checkpoint(name=self.next_name(purpose), purpose=purpose, x=x, y=y)

        self.checkpoints.append(checkpoint)
        self.selected = checkpoint

        return checkpoint

    def replace_purpose(self, collection: list, purpose: str, keep=None) -> int:
        """Drop everything in `collection` already claiming `purpose`.

        Returns how many were removed, so the UI can tell the operator that
        redrawing replaced what was there rather than silently losing it.
        """
        doomed = [item for item in collection
                  if item.purpose == purpose and item is not keep]

        for item in doomed:
            collection.remove(item)
            if self.selected is item:
                self.selected = None

        return len(doomed)

    def delete_selected(self) -> bool:
        item = self.selected

        if item is None:
            return False

        for collection in (self.zones, self.checkpoints):
            for index, existing in enumerate(collection):
                if existing is item:
                    collection.pop(index)
                    self.selected = None
                    return True

        return False

    def next_name(self, purpose: str) -> str:
        """A default name that does not collide.

        Names end up in the activity log and in the config file a human opens
        at 3am, so two points both called "tray_full" would be a nuisance.
        """
        taken = {item.name for item in self.items()}

        if purpose not in taken:
            return purpose

        index = 2
        while "%s %d" % (purpose, index) in taken:
            index += 1

        return "%s %d" % (purpose, index)

    # -- reading -----------------------------------------------------------

    def items(self) -> List:
        return list(self.zones) + list(self.checkpoints)

    def kind_of(self, item) -> str:
        return "zone" if isinstance(item, Zone) else "point"

    def rows(self) -> List[Tuple[str, object, str]]:
        """(kind, item, label) for the list on the right of the page."""
        rows = []

        for zone in self.zones:
            rows.append(("zone", zone, "zone   %-16s %s%s" % (
                zone.purpose, zone.name or "(unnamed)",
                "" if zone.enabled else "  (off)")))

        for point in self.checkpoints:
            rows.append(("point", point, "point  %-16s %s  [%s]%s" % (
                point.purpose, point.name or "(unnamed)",
                _calibration_label(point),
                "" if point.enabled else "  (off)")))

        return rows

    def index_of(self, item) -> Optional[int]:
        for index, (_, existing, _) in enumerate(self.rows()):
            if existing is item:
                return index

        return None

    def uncalibrated(self) -> List[Checkpoint]:
        """Points that still need a reference colour captured.

        Ink points are never in here. They test for bare card stock, which is
        an absolute question with nothing to calibrate against, and listing
        them would put a permanent unresolvable warning on the save button.
        """
        return [point for point in self.checkpoints
                if point.enabled and needs_reference(point) and not point.calibrated]

    # -- saving ------------------------------------------------------------

    def apply_to(self, camera_config: config_module.CameraConfig) -> None:
        camera_config.zones = [replace(zone) for zone in self.zones]
        camera_config.checkpoints = [replace(point) for point in self.checkpoints]


# --- The page ----------------------------------------------------------------


class CalibrationPage(ttk.Frame):
    """Live preview with the calibration drawn on top, and the controls for it.

    A frame rather than a window so it can be built without asking Tk to map
    anything, which is how the whole UI gets smoke-tested. The caller decides
    whether it lives in a Toplevel.
    """

    # Sized so the whole page fits a 1120x820 window without scrolling: the
    # picture, the tools, the live point readout and the Save button all have
    # to be on screen at once, because an operator calibrating is looking at
    # the bin and the screen alternately.
    CANVAS_WIDTH = 560
    CANVAS_HEIGHT = 420
    TICK_MS = 120

    MODE_SELECT = "select"
    MODE_ZONE = "zone"
    MODE_POINT = "point"

    def __init__(self, parent, binding, config_data, on_close=None, on_saved=None,
                 source: Optional[FrameSource] = None, headless: bool = False):
        super().__init__(parent, padding=12)

        self.binding = binding
        self.config_data = config_data
        self.on_close = on_close
        self.on_saved = on_saved
        self.headless = headless

        self.state = CalibrationState(binding.camera)
        self.source = source

        self._photo = None
        self._tick_id = None
        self._drag = None
        self._loading = False
        self._last_frame = None

        self._build()
        self._refresh_list()
        self._load_editor()

        if not headless:
            self._tick()

    # -- construction ------------------------------------------------------

    def _build(self) -> None:
        ttk.Label(self, text="Calibrating the camera for %s" % self.binding.display_name(),
                  font=("Helvetica", 14, "bold")).pack(anchor="w")

        ttk.Label(
            self,
            text=("Draw one zone over the output bin, and drop a point where the tray filling up "
                  "will change the colour. Everything is read from inside that zone and stored as "
                  "a fraction of the picture, so it survives changing the webcam."),
            wraplength=940, style="Sub.TLabel", justify="left",
        ).pack(anchor="w", pady=(4, 10))

        # Footer first, anchored to the bottom.
        #
        # Packed last it was the first thing pushed off the screen when the
        # content grew, and Save became unreachable without resizing the
        # window by hand. Reserving its space up front means the picture and
        # the list give way instead, which is the right way round.
        self._build_footer()

        body = ttk.Frame(self)
        body.pack(fill="both", expand=True)

        self._build_canvas(body)
        self._build_sidebar(body)

    def _build_canvas(self, parent) -> None:
        left = ttk.Frame(parent)
        left.pack(side="left", fill="both", expand=True)

        self.canvas = tk.Canvas(left, width=self.CANVAS_WIDTH, height=self.CANVAS_HEIGHT,
                                bg="#141414", highlightthickness=1,
                                highlightbackground="#555555", cursor="crosshair")
        self.canvas.pack()

        self.canvas.bind("<ButtonPress-1>", self._on_press)
        self.canvas.bind("<B1-Motion>", self._on_drag)
        self.canvas.bind("<ButtonRelease-1>", self._on_release)
        self.canvas.bind("<Delete>", lambda _: self._delete_selected())
        self.canvas.bind("<BackSpace>", lambda _: self._delete_selected())

        # One-shot tools rather than sticky modes. You press "Draw zone", draw
        # one, and you are back in select. Leaving the canvas armed is how you
        # end up dropping a stray point while trying to nudge a rectangle.
        tools = ttk.Frame(left)
        tools.pack(fill="x", pady=(8, 0))

        self.mode = tk.StringVar(value=self.MODE_SELECT)

        self.draw_zone_button = ttk.Button(tools, text="Draw zone",
                                           command=lambda: self._arm(self.MODE_ZONE))
        self.draw_zone_button.pack(side="left")

        self.add_point_button = ttk.Button(tools, text="Add point",
                                           command=lambda: self._arm(self.MODE_POINT))
        self.add_point_button.pack(side="left", padx=6)

        self.cancel_button = ttk.Button(tools, text="Cancel",
                                        command=lambda: self._arm(self.MODE_SELECT),
                                        state="disabled")
        self.cancel_button.pack(side="left", padx=(0, 12))

        self.mode_hint = ttk.Label(tools, text="Select and move", style="Sub.TLabel")
        self.mode_hint.pack(side="left")

        # A webcam clamped over an output bin is rarely upright, and Tesseract
        # will not read sideways text.
        rotate = ttk.Frame(left)
        rotate.pack(fill="x", pady=(6, 0))

        ttk.Label(rotate, text="Rotate picture").pack(side="left")
        self.rotation_box = ttk.Combobox(rotate, width=6, state="readonly",
                                         values=["0", "90", "180", "270"])
        self.rotation_box.set(str(getattr(self.binding.camera, "rotation", 0) or 0))
        self.rotation_box.pack(side="left", padx=(6, 6))
        self.rotation_box.bind("<<ComboboxSelected>>", lambda _: self._on_rotation_changed())

        ttk.Label(rotate, text="degrees clockwise, so the card reads the right way up",
                  style="Sub.TLabel").pack(side="left")

        # The driver's own panel: zoom, focus, exposure, low light compensation.
        # Worth having rather than reimplementing, because a card in a dim
        # output bin lives or dies on exposure and focus.
        ttk.Button(rotate, text="Camera settings...",
                   command=self._open_camera_settings).pack(side="right")

        # Purpose is chosen after the fact, in the sidebar, on whatever is
        # selected. New items take the first purpose that is not spoken for.
        self.new_zone_purpose = None
        self.new_point_purpose = None

        self.preview_status = ttk.Label(left, text="Camera off", style="Sub.TLabel")
        self.preview_status.pack(anchor="w", pady=(8, 0))

        live = ttk.LabelFrame(left, text="Points, right now", padding=8)
        live.pack(fill="x", pady=(8, 0))

        ttk.Label(
            live,
            text=("Wave a card over a point and this should flip to CHANGED, then back. "
                  "If it does not, the point is not where you think it is."),
            wraplength=600, style="Sub.TLabel", justify="left",
        ).pack(anchor="w")

        self.live_readout = tk.Label(live, text="No points yet", justify="left", anchor="w",
                                     font=("Menlo", 10), fg=MUTED_COLOUR)
        self.live_readout.pack(anchor="w", pady=(6, 0), fill="x")

    def _build_sidebar(self, parent) -> None:
        side = ttk.Frame(parent, padding=(14, 0, 0, 0))
        side.pack(side="left", fill="both")

        ttk.Label(side, text="What is calibrated",
                  font=("Helvetica", 12, "bold")).pack(anchor="w")

        self.item_list = tk.Listbox(side, height=9, width=44, exportselection=False,
                                    font=("Menlo", 10))
        self.item_list.pack(pady=(6, 0))
        self.item_list.bind("<<ListboxSelect>>", lambda _: self._on_list_selected())

        buttons = ttk.Frame(side)
        buttons.pack(anchor="w", pady=(6, 0))
        ttk.Button(buttons, text="Delete", width=9,
                   command=self._delete_selected).pack(side="left")

        editor = ttk.LabelFrame(side, text="Selected", padding=10)
        editor.pack(fill="x", pady=(12, 0))
        self.editor = editor

        ttk.Label(editor, text="Name").grid(row=0, column=0, sticky="w", pady=3)
        self.name_entry = ttk.Entry(editor, width=26)
        self.name_entry.grid(row=0, column=1, sticky="w")
        self.name_entry.bind("<KeyRelease>", lambda _: self._on_name_typed())

        ttk.Label(editor, text="Purpose").grid(row=1, column=0, sticky="w", pady=3)
        self.purpose_box = ttk.Combobox(editor, width=24, state="readonly", values=ZONE_PURPOSES)
        self.purpose_box.grid(row=1, column=1, sticky="w")
        self.purpose_box.bind("<<ComboboxSelected>>", lambda _: self._on_purpose_changed())

        self.item_enabled = tk.BooleanVar(value=True)
        ttk.Checkbutton(editor, text="In use", variable=self.item_enabled,
                        command=self._on_enabled_toggled).grid(row=2, column=1, sticky="w",
                                                               pady=(2, 0))

        # -- point-only controls, hidden while a zone is selected ----------
        self.point_controls = ttk.Frame(editor)
        self.point_controls.grid(row=3, column=0, columnspan=2, sticky="we", pady=(10, 0))

        ttk.Label(
            self.point_controls,
            text=("Empty the output tray first. Whatever this point can see now becomes "
                  "normal, and anything else means the tray has filled up."),
            wraplength=250, style="Sub.TLabel", justify="left",
        ).pack(anchor="w")

        ttk.Button(self.point_controls, text="Calibrate this point",
                   command=self._calibrate_selected).pack(anchor="w", pady=(6, 0))

        self.point_state = tk.Label(self.point_controls, text="", justify="left",
                                    wraplength=250, fg=MUTED_COLOUR, font=("Helvetica", 10))
        self.point_state.pack(anchor="w", pady=(6, 0))

        self.reference_label = ttk.Label(self.point_controls, text="", style="Sub.TLabel")
        self.reference_label.pack(anchor="w", pady=(2, 6))

        ttk.Label(
            self.point_controls,
            text=("Tolerances are wide on purpose. Room lighting drifts over a day in a hall "
                  "far more than people expect, and a point that trips on a cloud passing the "
                  "window stops the print queue."),
            wraplength=250, style="Sub.TLabel", justify="left",
        ).pack(anchor="w", pady=(0, 4))

        self.hue_tolerance = tk.DoubleVar(value=15.0)
        self._scale(self.point_controls, "Hue tolerance", self.hue_tolerance,
                    0.0, 90.0, 1.0, self._on_tolerance_changed)

        self.saturation_tolerance = tk.DoubleVar(value=0.30)
        self._scale(self.point_controls, "Saturation tolerance", self.saturation_tolerance,
                    0.0, 1.0, 0.01, self._on_tolerance_changed)

        frames = ttk.Frame(self.point_controls)
        frames.pack(anchor="w", pady=(6, 0))
        ttk.Label(frames, text="Frames that must agree").pack(side="left")
        self.frames_spin = tk.Spinbox(frames, from_=1, to=30, width=4,
                                      command=self._on_tolerance_changed)
        self.frames_spin.pack(side="left", padx=(6, 0))
        self.frames_spin.bind("<KeyRelease>", lambda _: self._on_tolerance_changed())

    def _scale(self, parent, label, variable, low, high, resolution, command):
        row = ttk.Frame(parent)
        row.pack(fill="x", pady=(4, 0))
        ttk.Label(row, text=label).pack(anchor="w")
        scale = tk.Scale(row, from_=low, to=high, resolution=resolution, orient="horizontal",
                         variable=variable, length=240, command=lambda _: command())
        scale.pack(anchor="w")

        return scale

    def _build_footer(self) -> None:
        footer = ttk.Frame(self)
        footer.pack(side="bottom", fill="x", pady=(12, 0))

        ttk.Button(footer, text="Save calibration", style="Big.TButton",
                   command=self.save).pack(side="left")
        ttk.Button(footer, text="Cancel", command=self.close).pack(side="left", padx=8)

        self.footer_note = ttk.Label(footer, text="", style="Sub.TLabel")
        self.footer_note.pack(side="left", padx=12)

    # -- canvas interaction ------------------------------------------------

    def _arm(self, mode: str) -> None:
        """Switch tool, and show which one is live.

        Drawing is one-shot: the canvas returns to select as soon as the shape
        exists, so the next click moves something instead of creating another.
        """
        self.mode.set(mode)

        hints = {
            self.MODE_SELECT: "Select and move",
            self.MODE_ZONE: "Drag a rectangle over the area to watch",
            self.MODE_POINT: "Click the spot to watch, on the side of the chute",
        }

        armed = mode != self.MODE_SELECT

        self.mode_hint.config(text=hints.get(mode, ""))
        self.cancel_button.config(state="normal" if armed else "disabled")

        try:
            self.canvas.config(cursor="crosshair" if armed else "arrow")
        except Exception:
            pass

    @staticmethod
    def _free_purpose(purposes: Sequence[str], existing: Sequence) -> str:
        """The first purpose nothing has claimed yet.

        Only one zone and one point per purpose per printer, so a new shape
        takes the next unused role rather than duplicating one. If they are all
        taken, the first is reused and replaces what was there.
        """
        taken = {item.purpose for item in existing}

        for purpose in purposes:
            if purpose not in taken:
                return purpose

        return purposes[0]

    def _on_press(self, event) -> None:
        try:
            self.canvas.focus_set()
        except Exception:
            pass

        x, y = float(event.x), float(event.y)
        mode = self.mode.get()

        if mode == self.MODE_POINT:
            point = self.state.add_checkpoint(
                self._free_purpose(POINT_PURPOSES, self.state.checkpoints),
                *point_fractions(x, y, self.CANVAS_WIDTH, self.CANVAS_HEIGHT))
            self._arm(self.MODE_SELECT)
            self._after_change(point)
            return

        if mode == self.MODE_ZONE:
            self._drag = {"kind": "new", "x": x, "y": y}
            return

        selected = self.state.selected

        if isinstance(selected, Zone):
            handle = handle_at(selected, x, y, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)

            if handle:
                self._drag = {"kind": "resize", "item": selected, "handle": handle}
                return

        point = checkpoint_at(self.state.checkpoints, x, y,
                              self.CANVAS_WIDTH, self.CANVAS_HEIGHT)

        if point is not None:
            self._drag = {"kind": "move_point", "item": point}
            self._after_change(point)
            return

        zone = zone_at(self.state.zones, x, y, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)

        if zone is not None:
            self._drag = {"kind": "move_zone", "item": zone, "x": x, "y": y}
            self._after_change(zone)
            return

        self._after_change(None)

    def _on_drag(self, event) -> None:
        if self._drag is None:
            return

        x, y = float(event.x), float(event.y)
        kind = self._drag["kind"]

        if kind == "new":
            self._drag["to"] = (x, y)
        elif kind == "resize":
            zone = self._drag["item"]
            zone.x, zone.y, zone.width, zone.height = resized_zone(
                zone, self._drag["handle"], x, y, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)
        elif kind == "move_zone":
            zone = self._drag["item"]
            zone.x, zone.y = moved_zone(zone, x - self._drag["x"], y - self._drag["y"],
                                        self.CANVAS_WIDTH, self.CANVAS_HEIGHT)
            self._drag["x"], self._drag["y"] = x, y
        elif kind == "move_point":
            point = self._drag["item"]
            point.x, point.y = point_fractions(x, y, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)

        self._redraw()

    def _on_release(self, event) -> None:
        drag = self._drag
        self._drag = None

        if drag is None:
            return

        if drag["kind"] == "new":
            x, y = float(event.x), float(event.y)
            zone = self.state.add_zone(
                self._free_purpose(ZONE_PURPOSES, self.state.zones),
                *rect_fractions(drag["x"], drag["y"], x, y,
                                self.CANVAS_WIDTH, self.CANVAS_HEIGHT))
            self._arm(self.MODE_SELECT)
            self._after_change(zone)
            return

        self._after_change(drag.get("item"))

    def _after_change(self, item) -> None:
        """One place for "the calibration changed": reselect, relist, redraw."""
        self.state.selected = item

        self._refresh_list()
        self._load_editor()
        self._redraw()

        # Straight away rather than on the next tick: an operator who has just
        # dropped a point looks at the indicator immediately, and a tenth of a
        # second of stale text reads as the point not working.
        self._refresh_live()

    # -- list and editor ---------------------------------------------------

    def _refresh_list(self) -> None:
        self.item_list.delete(0, "end")

        rows = self.state.rows()

        for _, _, label in rows:
            self.item_list.insert("end", label)

        if not rows:
            self.item_list.insert("end", "Nothing calibrated yet.")

        index = self.state.index_of(self.state.selected)

        if index is not None:
            self.item_list.selection_clear(0, "end")
            self.item_list.selection_set(index)

    def _on_list_selected(self) -> None:
        selection = self.item_list.curselection()
        rows = self.state.rows()

        if not selection or selection[0] >= len(rows):
            return

        self.state.selected = rows[selection[0]][1]

        self._load_editor()
        self._redraw()
        self._refresh_live()

    def _load_editor(self) -> None:
        item = self.state.selected

        # Guarded so filling the widgets does not look like the operator
        # editing them and write half-loaded values straight back.
        self._loading = True

        try:
            if item is None:
                self.name_entry.delete(0, "end")
                self.purpose_box.set("")
                self.point_state.config(text="")
                self.reference_label.config(text="")
                self.point_controls.grid_remove()
                return

            self.name_entry.delete(0, "end")
            self.name_entry.insert(0, item.name)

            is_point = isinstance(item, Checkpoint)

            self.purpose_box.config(values=POINT_PURPOSES if is_point else ZONE_PURPOSES)
            self.purpose_box.set(item.purpose)
            self.item_enabled.set(item.enabled)

            if is_point:
                self.hue_tolerance.set(item.hue_tolerance)
                self.saturation_tolerance.set(item.saturation_tolerance)
                self.frames_spin.delete(0, "end")
                self.frames_spin.insert(0, str(item.consecutive_frames))
                if not needs_reference(item):
                    # Tolerances and a reference colour mean nothing to an ink
                    # point, so say what it actually does rather than leaving
                    # somebody hunting for a Capture button it does not use.
                    self.reference_label.config(
                        text="reads blank card stock directly; nothing to calibrate")
                else:
                    self.reference_label.config(
                        text=("reference hue %.0f, saturation %.2f" % (
                            item.reference_hue, item.reference_saturation))
                        if item.calibrated else "no reference colour stored yet")
                self.point_controls.grid()
            else:
                self.point_controls.grid_remove()
        finally:
            self._loading = False

    def _on_name_typed(self) -> None:
        if self._loading or self.state.selected is None:
            return

        self.state.selected.name = self.name_entry.get()
        self._refresh_list()

    def _open_camera_settings(self) -> None:
        """Hand over to the webcam driver's own property pages.

        Fire and forget: the dialog opens on the capture thread, so this page
        stays usable and the operator can watch the preview react as they change
        exposure. Frames pause while the driver holds the device.
        """
        if self.source is None or not self.source.request_settings():
            self.mode_hint.config(
                text="Start the preview first: the settings belong to the open camera.")
            return

        self.mode_hint.config(text="Camera settings opened in its own window")

    def _on_rotation_changed(self) -> None:
        """Apply the mount angle to the working camera config.

        Takes effect on the preview immediately; Save is what keeps it.
        """
        try:
            degrees = int(self.rotation_box.get())
        except (TypeError, ValueError):
            degrees = 0

        self.binding.camera.rotation = degrees
        self.mode_hint.config(text="Picture rotated %d degrees" % degrees)
        self._redraw()

    def _on_purpose_changed(self) -> None:
        if self._loading or self.state.selected is None:
            return

        item = self.state.selected
        purpose = self.purpose_box.get()

        # One per purpose per printer. Reassigning a shape to a role something
        # else already holds takes the role over rather than creating a second
        # claim on it.
        collection = self.state.zones if isinstance(item, Zone) else self.state.checkpoints
        replaced = self.state.replace_purpose(collection, purpose, keep=item)

        item.purpose = purpose
        self.state.selected = item

        if replaced:
            self.mode_hint.config(text="Replaced the previous %s" % purpose)

        self._refresh_list()
        self._redraw()

    def _on_enabled_toggled(self) -> None:
        if self._loading or self.state.selected is None:
            return

        self.state.selected.enabled = bool(self.item_enabled.get())
        self._refresh_list()
        self._redraw()

    def _on_tolerance_changed(self) -> None:
        item = self.state.selected

        if self._loading or not isinstance(item, Checkpoint):
            return

        item.hue_tolerance = float(self.hue_tolerance.get())
        item.saturation_tolerance = float(self.saturation_tolerance.get())

        try:
            item.consecutive_frames = max(1, int(self.frames_spin.get()))
        except (TypeError, ValueError):
            # Mid-edit the box can hold an empty string. Leave the old value.
            pass

    def _delete_selected(self) -> None:
        if self.state.delete_selected():
            self._after_change(None)

    def _calibrate_selected(self) -> None:
        item = self.state.selected

        if not isinstance(item, Checkpoint):
            return

        frame = self._last_frame

        if not calibrate_checkpoint(item, frame):
            self.footer_note.config(
                text="No camera picture to sample. Nothing was changed.")
            return

        self.footer_note.config(
            text="Sampled %s: hue %.0f, saturation %.2f." % (
                item.name or item.purpose, item.reference_hue, item.reference_saturation))

        self._after_change(item)

    # -- drawing -----------------------------------------------------------

    def _tick(self) -> None:
        """Pull the newest frame, redraw, and re-read the live indicators."""
        frame = self.source.latest() if self.source is not None else None
        status = self.source.status() if self.source is not None else "No camera attached"

        self._last_frame = frame
        self.preview_status.config(text=status)

        self._redraw()
        self._refresh_live()

        self._tick_id = self.after(self.TICK_MS, self._tick)

    def _redraw(self) -> None:
        canvas = self.canvas
        canvas.delete("all")

        frame = self._last_frame
        photo = photo_from_frame(frame, self.CANVAS_WIDTH, self.CANVAS_HEIGHT,
                                 master=self) if frame is not None else None

        if photo is not None:
            # Held on the instance: Tk throws away an image nothing references.
            self._photo = photo
            canvas.create_image(0, 0, anchor="nw", image=photo)
        else:
            self._photo = None
            canvas.create_rectangle(0, 0, self.CANVAS_WIDTH, self.CANVAS_HEIGHT,
                                    fill="#141414", width=0)
            canvas.create_text(
                self.CANVAS_WIDTH / 2, self.CANVAS_HEIGHT / 2,
                text=self.preview_status.cget("text"),
                fill=MUTED_COLOUR, font=("Helvetica", 12), width=self.CANVAS_WIDTH - 40)

        for zone in self.state.zones:
            self._draw_zone(zone)

        for point in self.state.checkpoints:
            self._draw_point(point)

        if self._drag is not None and self._drag["kind"] == "new" and "to" in self._drag:
            to_x, to_y = self._drag["to"]
            canvas.create_rectangle(self._drag["x"], self._drag["y"], to_x, to_y,
                                    outline=SELECTED_COLOUR, dash=(4, 3), width=2)

    def _draw_zone(self, zone: Zone) -> None:
        left, top, right, bottom = rect_pixels(zone, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)

        selected = zone is self.state.selected
        colour = SELECTED_COLOUR if selected else (ZONE_COLOUR if zone.enabled else MUTED_COLOUR)

        self.canvas.create_rectangle(left, top, right, bottom, outline=colour,
                                     width=3 if selected else 2)
        self.canvas.create_text(left + 4, top + 9, anchor="w", fill=colour,
                                text=zone.name or zone.purpose, font=("Helvetica", 9))

        if not selected:
            return

        for corner_x, corner_y in ((left, top), (right, top), (left, bottom), (right, bottom)):
            self.canvas.create_rectangle(
                corner_x - 4, corner_y - 4, corner_x + 4, corner_y + 4,
                outline=colour, fill=colour)

    def _draw_point(self, point: Checkpoint) -> None:
        x, y = point_pixels(point, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)
        radius = point_radius_pixels(point, self.CANVAS_WIDTH, self.CANVAS_HEIGHT)

        _, colour, changed = checkpoint_indicator(point, self._last_frame)

        if point is self.state.selected:
            colour = SELECTED_COLOUR

        self.canvas.create_oval(x - radius, y - radius, x + radius, y + radius,
                                outline=colour, width=3 if changed else 2)
        self.canvas.create_line(x - 3, y, x + 3, y, fill=colour)
        self.canvas.create_line(x, y - 3, x, y + 3, fill=colour)
        self.canvas.create_text(x, y - radius - 9, fill=colour,
                                text=point.name or point.purpose, font=("Helvetica", 9))

    def _refresh_live(self) -> None:
        points = self.state.checkpoints

        if not points:
            self.live_readout.config(text="No points yet", fg=MUTED_COLOUR)
            return

        lines = []
        any_changed = False

        for point in points:
            text, _, changed = checkpoint_indicator(point, self._last_frame)
            any_changed = any_changed or changed
            lines.append("%-18s %s" % (point.name or point.purpose, text))

        self.live_readout.config(text="\n".join(lines),
                                 fg=CHANGED_COLOUR if any_changed else STEADY_COLOUR)

        item = self.state.selected

        if isinstance(item, Checkpoint):
            text, colour, _ = checkpoint_indicator(item, self._last_frame)
            self.point_state.config(text=text, fg=colour)

    # -- leaving -----------------------------------------------------------

    def save(self) -> None:
        self.state.apply_to(self.binding.camera)
        self.config_data.save()

        pending = self.state.uncalibrated()

        if self.on_saved is not None:
            self.on_saved(len(self.state.zones), len(self.state.checkpoints), len(pending))

        self.close()

    def close(self) -> None:
        if self._tick_id is not None:
            try:
                self.after_cancel(self._tick_id)
            except Exception:
                pass
            self._tick_id = None

        if self.source is not None:
            self.source.stop()

        if self.on_close is not None:
            self.on_close()
