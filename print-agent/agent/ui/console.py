"""Operator console widgets: what the agent is doing, right now.

The print station is usually watched remotely over AnyDesk rather than by
somebody standing at it, so the screen has to answer "is it working, and if not
why not" from across a room and through a laggy remote session. That means
large state, plain words, and showing the checks as they happen rather than only
their result.
"""

from __future__ import annotations

import copy
import tkinter as tk
from tkinter import ttk
from typing import Dict, List, Optional, Sequence, Tuple

from .calibration import photo_from_frame, point_radius_pixels

# The life of one card, in the order it happens. Named for what an operator
# would say, not for the method that implements it.
STEPS: List[Tuple[str, str]] = [
    ("claim", "Load next print job"),
    ("fetch", "Download print file"),
    ("spool", "Send to printer"),
    ("firmware", "Print card"),
    ("report", "Tell the server"),
]

def card_number_key(custom_id: str) -> tuple:
    """Sort key for a badge id like "1068-1".

    The id is an attendee number and a badge number, so it has to be compared
    as two integers rather than as text: "1068-10" comes after "1068-9", which
    string comparison gets backwards, and "999-1" comes before "1068-1", which
    it gets backwards too.

    Anything unparseable sorts below everything else rather than raising. This
    only drives a readout an operator glances at; a surprising id is not worth
    an exception on the print path.
    """
    parts = str(custom_id or "").strip().split("-")
    numbers = []

    for part in parts:
        if not part.isdigit():
            return (0, ())

        numbers.append(int(part))

    return (1, tuple(numbers)) if numbers else (0, ())


class SessionTally:
    """Which badges a printer has put out since the agent started.

    Deliberately session-scoped and not read back from the local history: the
    question it answers is "where has this run got to", which somebody asks
    when they are deciding whether to go and look in the output bin. Restarting
    the agent starts the count again, which is the honest answer.

    Kept apart from the widget so the bookkeeping can be tested on a machine
    with no display.
    """

    def __init__(self):
        self.reset()

    def reset(self) -> None:
        self.count = 0
        self.last = ""
        self.highest = ""
        self.lowest = ""

    def record(self, custom_id: str) -> None:
        custom_id = str(custom_id or "").strip()

        if not custom_id:
            return

        self.count += 1
        self.last = custom_id

        if not self.highest or card_number_key(custom_id) > card_number_key(self.highest):
            self.highest = custom_id

        if not self.lowest or card_number_key(custom_id) < card_number_key(self.lowest):
            self.lowest = custom_id


class SessionCards(ttk.Frame):
    """The readout for a `SessionTally`."""

    ROWS = (("last", "Last printed"), ("highest", "Highest"), ("lowest", "Lowest"))

    def __init__(self, parent):
        super().__init__(parent)

        self.tally = SessionTally()
        self._values = {}

        for row, (key, label) in enumerate(self.ROWS):
            ttk.Label(self, text=label, style="Sub.TLabel").grid(
                row=row, column=0, sticky="w", padx=(0, 12))

            value = ttk.Label(self, text="-", font=("Menlo", 11))
            value.grid(row=row, column=1, sticky="w")

            self._values[key] = value

    def record(self, custom_id: str) -> None:
        self.tally.record(custom_id)
        self.refresh()

    def reset(self) -> None:
        self.tally.reset()
        self.refresh()

    def refresh(self) -> None:
        for key, _label in self.ROWS:
            self._values[key].config(text=getattr(self.tally, key) or "-")


PENDING = "pending"
ACTIVE = "active"
DONE = "done"
FAILED = "failed"
SKIPPED = "skipped"

MARKS = {
    PENDING: ("  ", "#8a8a8a"),
    ACTIVE: ("->", "#1f5fa8"),
    DONE: ("ok", "#1b7f3b"),
    FAILED: ("!!", "#a81f1f"),
    SKIPPED: ("--", "#8a8a8a"),
}


class PipelineView(ttk.Frame):
    """One row per step, showing where the current card has got to.

    Deliberately shows every step even before it runs. Somebody watching wants
    to know what the machine is going to check, not just what it already did.
    """

    def __init__(self, parent):
        super().__init__(parent)

        self.rows: Dict[str, Dict[str, tk.Widget]] = {}

        for index, (key, label) in enumerate(STEPS):
            mark = tk.Label(self, text="  ", font=("Menlo", 12, "bold"),
                            fg=MARKS[PENDING][1], width=3, anchor="w")
            mark.grid(row=index, column=0, sticky="w", pady=3)

            name = ttk.Label(self, text=label, font=("Helvetica", 11))
            name.grid(row=index, column=1, sticky="w")

            detail = ttk.Label(self, text="", style="Sub.TLabel")
            detail.grid(row=index, column=2, sticky="w", padx=(12, 0))

            self.rows[key] = {"mark": mark, "name": name, "detail": detail}

        self.columnconfigure(2, weight=1)

    def set(self, key: str, state: str, detail: str = "") -> None:
        row = self.rows.get(key)

        if row is None:
            return

        symbol, colour = MARKS.get(state, MARKS[PENDING])
        row["mark"].config(text=symbol, fg=colour)
        row["detail"].config(text=detail)

    def reset(self) -> None:
        """Start a fresh card."""
        for key, _ in STEPS:
            self.set(key, PENDING, "")

    def state_of(self, key: str) -> str:
        """Current state of a step, by its mark. Used by the headless check."""
        row = self.rows.get(key)

        if row is None:
            return PENDING

        symbol = row["mark"].cget("text")

        for state, (mark, _) in MARKS.items():
            if mark == symbol:
                return state

        return PENDING


class CameraPanel(ttk.Frame):
    """Live camera view with the calibration overlaid.

    Showing the zones and points on top of the picture is the quickest way for
    somebody to tell that the camera has drifted, or that a card is landing
    outside the area being watched.

    Frames arrive from a `calibration.FrameSource`, which grabs them on its own
    thread; this panel only ever reads the newest one, so a slow or unplugged
    webcam costs a stale picture and a line of text, never a frozen console.
    """

    WIDTH = 420
    HEIGHT = 300

    # Eight frames a second. This is a confidence display, not the verifier,
    # and the redraw shares a thread with everything else on the screen.
    INTERVAL_MS = 125

    def __init__(self, parent):
        super().__init__(parent)

        self._source = None
        self._pump_id = None
        self._zones: Sequence = ()
        self._checkpoints: Sequence = ()

        self.canvas = tk.Canvas(self, width=self.WIDTH, height=self.HEIGHT,
                                bg="#141414", highlightthickness=0)
        self.canvas.pack()

        self.status = ttk.Label(self, text="Camera off", style="Sub.TLabel")
        self.status.pack(anchor="w", pady=(6, 0))

        self.checking = ttk.Label(self, text="", font=("Helvetica", 11, "bold"))
        self.checking.pack(anchor="w", pady=(2, 0))

        self.readouts = ttk.Label(self, text="", style="Sub.TLabel", justify="left")
        self.readouts.pack(anchor="w", pady=(4, 0))

        self._photo = None  # kept alive; Tk drops images that are only local
        self.show_placeholder("Camera off")

    def show_placeholder(self, message: str) -> None:
        self.canvas.delete("all")
        self.canvas.create_rectangle(0, 0, self.WIDTH, self.HEIGHT, fill="#141414", width=0)
        self.canvas.create_text(self.WIDTH / 2, self.HEIGHT / 2, text=message,
                                fill="#7a7a7a", font=("Helvetica", 12))

    def show_frame(self, photo, zones=None, checkpoints=None) -> None:
        """Draw a frame plus the calibration on top.

        `photo` is a tkinter PhotoImage, already at panel size. Nothing here
        touches OpenCV or numpy: the conversion lives in `calibration`, and a
        machine without either simply gets a placeholder.
        """
        self._photo = photo

        self.canvas.delete("all")
        self.canvas.create_image(0, 0, anchor="nw", image=photo)

        for zone in zones or []:
            x = zone.x * self.WIDTH
            y = zone.y * self.HEIGHT
            self.canvas.create_rectangle(
                x, y, x + zone.width * self.WIDTH, y + zone.height * self.HEIGHT,
                outline="#38d16a", width=2)
            self.canvas.create_text(x + 4, y + 8, anchor="w", text=zone.name or zone.purpose,
                                    fill="#38d16a", font=("Helvetica", 9))

        for point in checkpoints or []:
            x = point.x * self.WIDTH
            y = point.y * self.HEIGHT
            # Against the shorter side, which is the patch the sampler reads.
            # Drawing it wider would show a ring bigger than what is measured.
            radius = point_radius_pixels(point, self.WIDTH, self.HEIGHT)
            colour = "#ffb020" if point.calibrated else "#a81f1f"
            self.canvas.create_oval(x - radius, y - radius, x + radius, y + radius,
                                    outline=colour, width=2)
            self.canvas.create_text(x, y - radius - 8, text=point.name or point.purpose,
                                    fill=colour, font=("Helvetica", 9))

    def set_overlay(self, zones=(), checkpoints=()) -> None:
        """Which calibration to draw over the picture."""
        self._zones = zones or ()
        self._checkpoints = checkpoints or ()

    def attach(self, source, zones=(), checkpoints=()) -> None:
        """Start showing what `source` is grabbing.

        The panel does not own the camera: whoever attached it opened it and is
        responsible for stopping it. A station can have several printers, and
        two captures on one device hangs on Windows, so ownership stays in one
        place rather than being split across widgets.
        """
        self.detach()

        self._source = source
        self.set_overlay(zones, checkpoints)
        self._pump()

    def detach(self) -> None:
        if self._pump_id is not None:
            try:
                self.after_cancel(self._pump_id)
            except Exception:
                pass
            self._pump_id = None

        self._source = None
        self._photo = None
        self.show_placeholder("Camera off")

    def _zone(self):
        """The single card zone, if one is calibrated."""
        for zone in self._zones or ():
            if getattr(zone, "enabled", True):
                return zone

        return None

    def _zoomed(self, frame):
        """The frame cropped to the card zone, or whole if none is drawn."""
        zone = self._zone()

        if zone is None:
            return frame

        try:
            height, width = frame.shape[:2]
            x, y, zone_width, zone_height = zone.pixels(width, height)
            patch = frame[y:y + zone_height, x:x + zone_width]
        except Exception:
            return frame

        return patch if getattr(patch, "size", 0) else frame

    def _checkpoints_in_zone(self):
        """Points re-expressed as fractions of the cropped picture.

        Their stored coordinates are fractions of the whole frame; once the
        preview is cropped they have to be rebased or they would be drawn in
        the wrong place, which is worse than not drawing them.
        """
        zone = self._zone()

        if zone is None:
            return self._checkpoints

        rebased = []

        for point in self._checkpoints or ():
            if zone.width <= 0 or zone.height <= 0:
                continue

            x = (point.x - zone.x) / zone.width
            y = (point.y - zone.y) / zone.height

            if not (0.0 <= x <= 1.0 and 0.0 <= y <= 1.0):
                continue  # outside the crop, so nothing to draw

            moved = copy.copy(point)
            moved.x = x
            moved.y = y
            moved.radius = point.radius / max(zone.width, zone.height)

            rebased.append(moved)

        return rebased

    def _pump(self) -> None:
        source = self._source

        if source is None:
            return

        frame = source.latest()
        photo = None

        if frame is not None:
            # Only the calibrated zone. The console is a confidence display for
            # somebody watching over a remote session, and the bin is a small
            # part of a wide shot: showing the desk, the cables and the wall
            # around it just makes the cards harder to see. Everything outside
            # the zone is ignored by the verifier anyway.
            photo = photo_from_frame(
                self._zoomed(frame), self.WIDTH, self.HEIGHT, master=self)

        if photo is None:
            # No frame yet, camera gone, or no imaging library on this box. The
            # source's own words are more useful than a generic failure.
            self._photo = None
            self.show_placeholder(source.status())
        else:
            # The picture is already cropped to the zone, so the zone outline
            # would just trace the border. Points still matter: they sit inside
            # the zone and their state is worth watching.
            self.show_frame(photo, (), self._checkpoints_in_zone())

        self.set_status(source.status())

        self._pump_id = self.after(self.INTERVAL_MS, self._pump)

    def set_checking(self, message: str) -> None:
        """The single line describing what is being examined this instant."""
        self.checking.config(text=message)

    def set_readouts(self, lines: List[str]) -> None:
        self.readouts.config(text="\n".join(lines))

    def set_status(self, message: str) -> None:
        self.status.config(text=message)


class BatchPicker(ttk.Frame):
    """Full-page batch chooser.

    A page rather than a dropdown because picking the wrong batch means
    printing the wrong hundred cards, and because it has to be readable over a
    remote session.
    """

    def __init__(self, parent, on_select, on_back, on_refresh):
        super().__init__(parent, padding=16)

        self.on_select = on_select

        header = ttk.Frame(self)
        header.pack(fill="x")
        ttk.Label(header, text="Choose a print batch",
                  font=("Helvetica", 15, "bold")).pack(side="left")
        ttk.Button(header, text="Back", command=on_back).pack(side="right")
        ttk.Button(header, text="Refresh", command=on_refresh).pack(side="right", padx=6)

        columns = ("name", "status", "progress", "printer")
        self.tree = ttk.Treeview(self, columns=columns, show="headings", height=14)

        for column, heading, width in (
            ("name", "Batch", 300),
            ("status", "Status", 120),
            ("progress", "Progress", 140),
            ("printer", "Printer", 180),
        ):
            self.tree.heading(column, text=heading)
            self.tree.column(column, width=width, anchor="w")

        self.tree.pack(fill="both", expand=True, pady=(12, 0))
        self.tree.bind("<Double-1>", lambda _: self._choose())

        self.empty = ttk.Label(self, text="", style="Sub.TLabel")
        self.empty.pack(anchor="w", pady=(8, 0))

        ttk.Button(self, text="Print this batch", style="Big.TButton",
                   command=self._choose).pack(anchor="w", pady=(12, 0))

        self._batches: Dict[str, dict] = {}

    def show(self, batches: List[dict]) -> None:
        self.tree.delete(*self.tree.get_children())
        self._batches = {}

        for batch in batches:
            totals = batch.get("totals") or {}
            progress = "%s / %s" % (totals.get("printed", 0), totals.get("jobs", 0))

            item = self.tree.insert("", "end", values=(
                batch.get("name", "?"),
                batch.get("status", "?"),
                progress,
                batch.get("printer_name") or "Not assigned",
            ))
            self._batches[item] = batch

        self.empty.config(
            text="" if batches else "No batches are waiting. Build one in the admin panel.")

    def _choose(self) -> None:
        selection = self.tree.selection()

        if not selection:
            return

        batch = self._batches.get(selection[0])

        if batch:
            self.on_select(batch)

    def selected_batch(self) -> Optional[dict]:
        selection = self.tree.selection()

        return self._batches.get(selection[0]) if selection else None
