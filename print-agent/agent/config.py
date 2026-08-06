"""Agent configuration, persisted to disk next to the user's profile.

Windows 7 is the target, so the config lives in %APPDATA% and everything is
plain JSON that a human can open and fix when the machine misbehaves at 3am
during a convention.
"""

from __future__ import annotations

import json
import os
from dataclasses import asdict, dataclass, field, fields
from pathlib import Path
from typing import Any, Dict, List, Optional

APP_DIR_NAME = "BadgePrintAgent"
CONFIG_FILENAME = "config.json"

ROLE_CARD = "card"
ROLE_RECEIPT = "receipt"

# What a calibrated rectangle is for.
# One zone, over the output bin.
#
# There is no point calibrating separate rectangles for the badge id and the
# name. The camera watches the receiving bin, cards stack up in it, and the top
# card rises towards the lens as the stack grows, so nothing sits at a fixed
# place in the frame. The one region answers the one question the zone is for:
# did something land in the bin.
ZONE_CARD = "card"

# Old name, still accepted when reading a config written before the zones were
# collapsed into one.
ZONE_CARD_PRESENT = "card_present"

# What a calibrated point is for.
POINT_TRAY_FULL = "tray_full"

# Points dropped on spots the artwork always covers. A card that comes out of
# the printer unprinted is the failure that loses badges: the ribbon or the
# transfer film runs out, the card feeds through anyway and the driver reports
# success. A blank card is bright and colourless where the badge should be
# dark and saturated, which is a far larger signal than anything the artwork
# or the badge number could give us.
POINT_CARD_INK = "card_ink"


def config_dir() -> Path:
    """Where we keep config, the local queue and cached PDFs."""
    base = os.environ.get("APPDATA") or os.path.expanduser("~")
    path = Path(base) / APP_DIR_NAME
    path.mkdir(parents=True, exist_ok=True)
    return path


def _build(cls, data: Optional[Dict[str, Any]]):
    """Construct a dataclass from JSON, ignoring keys we no longer understand.

    Config files outlive code changes. Dropping unknown keys means an old file
    still loads after a rename instead of crashing the agent on startup.
    """
    if not isinstance(data, dict):
        return cls()

    known = {f.name for f in fields(cls)}
    return cls(**{k: v for k, v in data.items() if k in known})


@dataclass
class Zone:
    """A rectangle the operator drew on the camera preview.

    Stored as fractions of frame width and height so the calibration survives a
    change of webcam or resolution.
    """

    name: str = ""
    purpose: str = ZONE_CARD
    x: float = 0.0
    y: float = 0.0
    width: float = 1.0
    height: float = 1.0
    enabled: bool = True

    def pixels(self, frame_width: int, frame_height: int) -> tuple:
        """(x, y, w, h) in pixels for a given frame size."""
        return (
            int(self.x * frame_width),
            int(self.y * frame_height),
            max(1, int(self.width * frame_width)),
            max(1, int(self.height * frame_height)),
        )


@dataclass
class Checkpoint:
    """A single spot the operator dropped on the camera preview.

    Used for things the printer cannot tell us: chiefly whether the output tray
    has filled up, which previously overflowed and jammed the machine. Operators
    can stick coloured tape at the spot to make it easier to see.

    Matching is on hue and saturation rather than brightness, because somebody
    switching the room lights on must not read as a full tray.
    """

    name: str = ""
    purpose: str = POINT_TRAY_FULL
    x: float = 0.5
    y: float = 0.5

    # Sampled as a small patch, not one pixel, so sensor noise does not decide.
    radius: float = 0.02

    # Reference colour captured during calibration with the tray empty.
    reference_hue: float = 0.0
    reference_saturation: float = 0.0

    # Brightness when calibrated. Over the output bin this is the signal that
    # matters: the bin is black and a card is not, so "is it dark?" answers
    # "is a card there?" far more reliably than hue, which is pure noise on
    # unlit plastic.
    #
    # None means no brightness was captured, which is what every point
    # calibrated before this field existed looks like. It cannot default to 0.0
    # instead: 0.0 is a real reading -- an unlit black bin -- and treating a
    # config written last week as "reference black" would make every existing
    # point read as changed the moment the agent was updated.
    reference_value: Optional[float] = None
    calibrated: bool = False

    # How far the patch may drift before we call it changed. Generous on
    # purpose: lighting shifts move these more than you would expect.
    hue_tolerance: float = 15.0
    saturation_tolerance: float = 0.30

    # How far brightness may drift before the point counts as changed. Wide
    # enough to ignore the hall lights moving, far narrower than the gap
    # between black plastic and a printed card.
    value_tolerance: float = 0.18

    # Consecutive frames that must agree before acting, so a hand passing in
    # front of the lens does not stop the queue.
    consecutive_frames: int = 3

    enabled: bool = True


@dataclass
class CameraConfig:
    """Optional webcam verification for one printer.

    Can be switched off from the UI while the agent is running: printing must
    never depend on the camera being healthy, it only adds confidence.
    """

    enabled: bool = False
    device_index: int = 0

    zones: List[Zone] = field(default_factory=list)
    checkpoints: List[Checkpoint] = field(default_factory=list)

    # A zone differing from its empty reference by more than this fraction of
    # pixels means a card appeared.
    card_detect_threshold: float = 0.04

    # When a card_ink point counts as unprinted: bright *and* colourless.
    #
    # Both conditions are required. Brightness alone would flag a badge with a
    # pale patch in its artwork, and low saturation alone would flag the unlit
    # bin. A blank CR80 card under the bin light is the only thing that is both.
    blank_value_min: float = 0.70
    blank_saturation_max: float = 0.18

    # Fraction of the card_ink points that must read blank before the card is
    # called unprinted. Above a half by default, so one point drifting off the
    # card as the stack rises cannot condemn a good card on its own.
    blank_point_fraction: float = 0.6

    # Manual focus, in whatever units the driver's own settings dialog shows.
    #
    # The camera looks down into the output bin and the top card rises towards
    # the lens as the stack grows: measured on the station, about 5 with a
    # single card at the bottom and about 10 when the bin is nearly full. It is
    # set once and left alone. Nothing here reads text any more, and the colour
    # of a patch survives a soft picture perfectly well, so focus is for the
    # operator's benefit on the preview rather than a thing the verdict
    # depends on.
    focus_enabled: bool = True
    focus: int = 5
    focus_min: int = 0
    focus_max: int = 30

    # Degrees to rotate the frame clockwise before reading anything from it.
    #
    # A webcam clamped over an output bin is rarely upright, and Tesseract will
    # not read sideways text. Rotating here rather than asking the operator to
    # remount the camera means the calibration, the artwork match and OCR all
    # see the same upright picture.
    rotation: int = 0

    # How long to wait for a card to drop after the printer says it is done.
    settle_seconds: float = 2.0

    def card_zone(self) -> Optional[Zone]:
        """The single zone over the output bin, if one has been drawn."""
        for zone in self.zones:
            if zone.enabled:
                return zone

        return None

    def zones_for(self, purpose: str) -> List[Zone]:
        return [z for z in self.zones if z.enabled and z.purpose == purpose]

    def checkpoints_for(self, purpose: str) -> List[Checkpoint]:
        return [c for c in self.checkpoints if c.enabled and c.purpose == purpose]


@dataclass
class PushoverConfig:
    """Local alerting. Staff need to know about a stopped printer immediately,
    and the agent is the only thing that can see the hardware."""

    enabled: bool = False
    user_key: str = ""
    api_token: str = ""

    # Do not alert more than once per this many seconds for the same condition,
    # so a jam does not turn into a hundred notifications.
    cooldown_seconds: int = 300


@dataclass
class TelegramConfig:
    """A photo of every card, and a pause button, in a Telegram channel.

    The automated blank-card check is a threshold somebody guessed at. A human
    glancing at a photo catches things no threshold was written for: a colour
    cast, a half-transferred card, artwork that is simply wrong. This is the
    channel that lets them, from anywhere, and stop the run without walking to
    the machine or opening a remote session.

    A station prints roughly one card a minute, comfortably inside Telegram's
    rate limit of about twenty messages a minute to one chat, so every card is
    photographed rather than sampled.
    """

    enabled: bool = False
    bot_token: str = ""
    chat_id: str = ""

    # Photograph every card, rather than only the ones that failed a check.
    # The point of the channel is catching what the checks do not test for.
    photo_every_card: bool = True

    # How often to ask Telegram for button presses. This is the delay between
    # somebody hitting Pause and the printer noticing, so it is deliberately
    # short; getUpdates long-polls, so it costs one idle connection, not a
    # request every two seconds.
    poll_seconds: float = 2.0

    # Seconds Telegram holds a getUpdates request open when there is nothing to
    # report. Bounded well under the socket timeout so a quiet channel does not
    # look like a hung connection.
    long_poll_seconds: int = 20

    def is_configured(self) -> bool:
        return bool(self.enabled and self.bot_token and self.chat_id)


@dataclass
class PrinterBinding:
    """One Windows printer this station drives.

    A station can have several: two card printers to double throughput, plus a
    thermal receipt printer. Each is independent, with its own SNMP address, its
    own camera and its own worker, so a jam on one does not stop the others.
    """

    name: str = ""            # Windows printer name, exactly as the spooler reports it
    role: str = ROLE_CARD
    label: str = ""           # what staff call it, e.g. "Card printer left"
    enabled: bool = True

    # Card printers answer SNMP and are worth watching. Receipt printers are
    # thermal units with nothing useful to report.
    snmp_host: str = ""
    snmp_community: str = "public"

    camera: CameraConfig = field(default_factory=CameraConfig)

    def is_card(self) -> bool:
        return self.role == ROLE_CARD

    def display_name(self) -> str:
        return self.label or self.name or "(unnamed printer)"


@dataclass
class AgentConfig:
    server_url: str = ""
    api_token: str = ""

    printers: List[PrinterBinding] = field(default_factory=list)

    poll_seconds: float = 3.0
    heartbeat_seconds: float = 45.0

    # Cards left on the ribbon below which we warn staff to fetch a new one.
    ribbon_warn_threshold: int = 50

    pushover: PushoverConfig = field(default_factory=PushoverConfig)
    telegram: TelegramConfig = field(default_factory=TelegramConfig)

    @property
    def path(self) -> Path:
        return config_dir() / CONFIG_FILENAME

    def is_configured(self) -> bool:
        return bool(self.server_url and self.api_token and self.printers)

    def card_printers(self) -> List[PrinterBinding]:
        return [p for p in self.printers if p.enabled and p.is_card()]

    def receipt_printers(self) -> List[PrinterBinding]:
        return [p for p in self.printers if p.enabled and not p.is_card()]

    def printer(self, name: str) -> Optional[PrinterBinding]:
        for binding in self.printers:
            if binding.name == name:
                return binding
        return None

    def save(self) -> None:
        with open(self.path, "w") as handle:
            json.dump(asdict(self), handle, indent=2)

    @classmethod
    def load(cls) -> "AgentConfig":
        path = config_dir() / CONFIG_FILENAME

        if not path.exists():
            return cls()

        try:
            with open(path) as handle:
                data = json.load(handle)
        except (ValueError, OSError):
            # A corrupt config must not stop the agent from starting; the
            # operator can re-enter it in the UI faster than they can fix JSON.
            return cls()

        return cls.from_dict(data)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "AgentConfig":
        known = {f.name for f in fields(cls)}
        payload = {k: v for k, v in data.items() if k in known}

        printers = payload.pop("printers", None)
        pushover = payload.pop("pushover", None)
        telegram = payload.pop("telegram", None)

        config = cls(**payload)

        if isinstance(printers, list):
            config.printers = [
                cls._build_printer(entry) for entry in printers if isinstance(entry, dict)
            ]

        config.pushover = _build(PushoverConfig, pushover)
        config.telegram = _build(TelegramConfig, telegram)

        return config

    @staticmethod
    def _build_printer(data: Dict[str, Any]) -> PrinterBinding:
        camera = data.get("camera")
        binding = _build(PrinterBinding, data)

        binding.camera = _build(CameraConfig, camera)

        if isinstance(camera, dict):
            binding.camera.zones = _migrate_zones([
                _build(Zone, z) for z in camera.get("zones", []) if isinstance(z, dict)
            ])
            binding.camera.checkpoints = [
                _build(Checkpoint, c) for c in camera.get("checkpoints", []) if isinstance(c, dict)
            ]

        return binding


def _migrate_zones(zones: List[Zone]) -> List[Zone]:
    """Fold a pre-collapse calibration down to the single card zone.

    Older configs could hold separate rectangles for the badge id and the name.
    Those never worked in practice: cards stack in the output bin and the top
    one drifts towards the lens, so no feature sits still in the frame. Keep the
    card zone if there is one, otherwise promote the first, and drop the rest
    rather than leave a calibration the code no longer reads.
    """
    if not zones:
        return []

    preferred = None

    for zone in zones:
        if zone.purpose in (ZONE_CARD, ZONE_CARD_PRESENT):
            preferred = zone
            break

    keeper = preferred or zones[0]
    keeper.purpose = ZONE_CARD

    return [keeper]


def cache_dir() -> Path:
    """Where downloaded PDFs live until their job is done."""
    path = config_dir() / "cache"
    path.mkdir(parents=True, exist_ok=True)
    return path


def capture_dir() -> Path:
    """Camera frames, kept locally for diagnosing a bad verification.

    Never uploaded. The server only ever learns the verdict.
    """
    path = config_dir() / "captures"
    path.mkdir(parents=True, exist_ok=True)
    return path
