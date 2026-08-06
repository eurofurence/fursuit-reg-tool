"""Badge Print Agent desktop UI.

Runs on the Windows 7 machine wired to the Zebra card printer. tkinter because
it ships with Python and the target box is locked down; nothing to install.

The design goal is that a volunteer on a convention floor can tell at a glance
whether cards are coming out, and if they are not, what to physically go and do
about it. Printer faults are the loudest thing on the screen.
"""

from __future__ import annotations

import queue
import threading
import tkinter as tk
from datetime import datetime
from tkinter import messagebox, ttk
from typing import Optional

from . import calibration, console
from .. import config as config_module
from .. import monitor as monitor_module
from .. import printing, vocabulary, zebra
from ..config import AgentConfig, config_dir

POLL_SECONDS = 3.0

# Condition colours. Green only when it is genuinely safe to print.
COLOURS = {
    zebra.OK: ("#1b7f3b", "#ffffff"),
    zebra.PRINTING: ("#1f5fa8", "#ffffff"),
    zebra.RIBBON_LOW: ("#a86a00", "#ffffff"),
    zebra.FILM_LOW: ("#a86a00", "#ffffff"),
    zebra.CARDS_LOW: ("#a86a00", "#ffffff"),
}
STOP_COLOUR = ("#a81f1f", "#ffffff")
IDLE_COLOUR = ("#5a5a5a", "#ffffff")

LABELS = {
    zebra.OK: "READY",
    zebra.PRINTING: "PRINTING",
    zebra.RIBBON_LOW: "RIBBON LOW",
    zebra.FILM_LOW: "FILM LOW",
    zebra.CARDS_LOW: "CARDS LOW",
    zebra.RIBBON_OUT: "RIBBON EMPTY",
    zebra.FILM_OUT: "FILM EMPTY",
    zebra.CARDS_OUT: "OUT OF CARDS",
    zebra.CARD_JAM: "CARD JAM",
    zebra.COVER_OPEN: "COVER OPEN",
    zebra.REJECT_BIN_FULL: "REJECT BIN FULL",
    zebra.SERVICE_REQUIRED: "SERVICE REQUIRED",
    zebra.OFFLINE: "PRINTER OFFLINE",
    zebra.UNKNOWN: "UNKNOWN STATE",
}


class DemoPoller:
    """Cycles through states so the UI can be reviewed without hardware."""

    SCRIPT = [
        dict(printer_state="idle", supply_level=313),
        dict(printer_state="printing", supply_level=312),
        dict(printer_state="idle", supply_level=311),
        dict(printer_state="idle", supply_level=40),
        dict(printer_state="idle", supply_level=40, error_bits=["jammed"]),
        dict(printer_state="idle", supply_level=40, alarms=["flipper_stall_17"]),
        dict(printer_state="idle", supply_level=0),
        dict(printer_state="idle", supply_level=310),
    ]

    def __init__(self):
        self.index = 0

    def read(self) -> zebra.Reading:
        step = dict(self.SCRIPT[self.index % len(self.SCRIPT)])
        self.index += 1
        step.setdefault("supply_max", 627)
        step.setdefault("reachable", True)
        step.setdefault("jobs", [zebra.JobRow(index=1, job_id="58", state="done_ok",
                                              location="not_in_printer")])
        return zebra.Reading(**step)


class AgentApp(tk.Tk):
    def __init__(self, demo: bool = False, headless: bool = False):
        super().__init__()

        # headless builds every widget but never asks Tk to draw, so the whole
        # UI can be smoke-tested on a machine whose Tk cannot open a window.
        self.headless = headless
        self.demo = demo
        self.config_data = AgentConfig.load()
        self.active_batch: Optional[dict] = None

        # Built on first use so the window still opens on a machine that is
        # missing a dependency or has never been configured.
        self.worker = None
        self._cameras_probed = False
        self._client = None
        self._store = None
        self._notifier = None
        self.events: "queue.Queue" = queue.Queue()
        self.stop_flag = threading.Event()
        self.monitor: Optional[monitor_module.PrinterMonitor] = None

        # The webcam is opened in exactly one place at a time: either the
        # console preview or the calibration page holds it, never both. Two
        # cv2 captures on one device hangs on Windows.
        self.frame_source: Optional[calibration.FrameSource] = None
        self.calibration_window = None
        self.calibration_page: Optional[calibration.CalibrationPage] = None

        # Brought up with a run, and only when a channel is configured. The
        # sender and poller each own a thread; neither is on the print path.
        self.telegram_channel = None
        self.telegram_sender = None
        self.telegram_poller = None

        self.title("Badge Print Agent" + (" [demo]" if demo else ""))
        self.geometry("940x660")
        self.minsize(860, 600)

        self._build_style()
        self._build_header()
        self._build_tabs()
        self._build_statusbar()
        self._sync_session_panel()

        if not headless:
            self._start_monitor()
            self._sync_camera_preview()
            self.after(200, self._drain_events)
            self.protocol("WM_DELETE_WINDOW", self._on_close)
            self._force_first_paint()

    def _force_first_paint(self) -> None:
        """Work around Apple's Tcl/Tk 8.5, which often paints nothing at all.

        The system Python on macOS links against Tk 8.5.9, where a freshly
        mapped window frequently stays blank until something changes its
        geometry. Nudging the size by a pixel and putting it back forces the
        first real draw. Harmless everywhere else, including on the Windows 7
        target, which is the machine that actually matters.
        """
        self.update_idletasks()
        self.lift()

        width = self.winfo_width() or 940
        height = self.winfo_height() or 660

        self.geometry("%dx%d" % (width, height + 1))
        self.update_idletasks()
        self.after(60, lambda: self.geometry("%dx%d" % (width, height)))

    # -- chrome ------------------------------------------------------------

    def _build_style(self) -> None:
        style = ttk.Style(self)
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass
        style.configure("Head.TLabel", font=("Helvetica", 15, "bold"))
        style.configure("Sub.TLabel", foreground="#555555")
        style.configure("Big.TButton", font=("Helvetica", 12, "bold"), padding=8)

    def _build_header(self) -> None:
        header = ttk.Frame(self, padding=(14, 12))
        header.pack(fill="x")

        left = ttk.Frame(header)
        left.pack(side="left", fill="x", expand=True)

        ttk.Label(left, text="Badge Print Agent", style="Head.TLabel").pack(anchor="w")
        self.machine_label = ttk.Label(left, text="Not configured", style="Sub.TLabel")
        self.machine_label.pack(anchor="w")

        # Which printer the header and camera tab are showing. A station can run
        # several at once, each with its own condition.
        chooser = ttk.Frame(left)
        chooser.pack(anchor="w", pady=(8, 0))
        ttk.Label(chooser, text="Showing:").pack(side="left")
        self.printer_selector = ttk.Combobox(chooser, state="readonly", width=30,
                                             values=["(no printers)"])
        self.printer_selector.pack(side="left", padx=6)
        self.printer_selector.bind("<<ComboboxSelected>>", lambda _: self._on_selector_changed())

        right = ttk.Frame(header)
        right.pack(side="right")

        # The condition pill is the single most important thing on screen.
        self.condition_pill = tk.Label(
            right, text="CONNECTING", font=("Helvetica", 15, "bold"),
            bg=IDLE_COLOUR[0], fg=IDLE_COLOUR[1], padx=18, pady=10,
        )
        self.condition_pill.pack(anchor="e")

        self.ribbon_label = ttk.Label(right, text="Ribbon: unknown", style="Sub.TLabel")
        self.ribbon_label.pack(anchor="e", pady=(6, 0))

        self.ribbon_bar = ttk.Progressbar(right, length=220, maximum=100)
        self.ribbon_bar.pack(anchor="e", pady=(2, 0))

        # Fault banner, hidden while healthy so it means something when it appears.
        self.banner = tk.Frame(self, bg=STOP_COLOUR[0])
        self.banner_label = tk.Label(
            self.banner, text="", bg=STOP_COLOUR[0], fg="#ffffff",
            font=("Helvetica", 12, "bold"), wraplength=880, justify="left", padx=14, pady=10,
        )
        self.banner_label.pack(fill="x")

    def _build_tabs(self) -> None:
        self.tabs = ttk.Notebook(self)
        self.tabs.pack(fill="both", expand=True, padx=12, pady=(8, 4))

        self._build_run_tab()
        self._build_setup_tab()
        self._build_camera_tab()
        self._build_history_tab()
        self._build_diagnostics_tab()

        # Probing cameras opens each device in turn, which is too slow to do on
        # every redraw but fine once, the moment somebody looks at the Camera
        # tab. Doing it there means the dropdown is populated before they can
        # wonder why it is empty.
        self.tabs.bind("<<NotebookTabChanged>>", self._on_tab_changed)

        # Only now that every tab's widgets exist is it safe to select a printer,
        # which fills in both the setup detail pane and the camera tab.
        self._reload_printer_list()

    def _build_run_tab(self) -> None:
        """The operator console, plus the batch chooser it swaps to.

        Two full pages rather than a dropdown, because this screen is usually
        read remotely over AnyDesk and picking the wrong batch means printing
        the wrong hundred cards.
        """
        tab = ttk.Frame(self.tabs)
        self.tabs.add(tab, text="  Run  ")

        self.console_view = ttk.Frame(tab, padding=14)
        self.picker_view = console.BatchPicker(
            tab,
            on_select=self._select_batch,
            on_back=self._show_console,
            on_refresh=self._refresh_batches,
        )

        self._build_console(self.console_view)
        self._show_console()

    def _build_console(self, root) -> None:
        # -- batch bar -----------------------------------------------------
        bar = ttk.Frame(root)
        bar.pack(fill="x")

        left = ttk.Frame(bar)
        left.pack(side="left", fill="x", expand=True)

        self.batch_title = ttk.Label(left, text="No batch selected",
                                     font=("Helvetica", 14, "bold"))
        self.batch_title.pack(anchor="w")

        self.batch_progress = ttk.Progressbar(left, length=320, maximum=100)
        self.batch_progress.pack(anchor="w", pady=(6, 2))

        self.batch_counts = ttk.Label(left, text="Nothing queued", style="Sub.TLabel")
        self.batch_counts.pack(anchor="w")

        controls = ttk.Frame(bar)
        controls.pack(side="right")

        self.choose_button = ttk.Button(controls, text="Choose batch", style="Big.TButton",
                                        command=self._show_picker)
        self.choose_button.grid(row=0, column=0, padx=3)

        self.start_button = ttk.Button(controls, text="Start", command=self._start_printing)
        self.start_button.grid(row=0, column=1, padx=3)

        self.pause_button = ttk.Button(controls, text="Pause", command=self._pause_printing,
                                       state="disabled")
        self.pause_button.grid(row=0, column=2, padx=3)

        self.cancel_button = ttk.Button(controls, text="Cancel batch",
                                        command=self._cancel_batch, state="disabled")
        self.cancel_button.grid(row=0, column=3, padx=3)

        # Unattended mode. With this on, finishing a batch pulls the next one
        # and the station can be left alone; with it off an operator chooses
        # every batch by hand.
        self.auto_next = tk.BooleanVar(value=False)
        ttk.Checkbutton(controls, text="Pick the next batch automatically",
                        variable=self.auto_next,
                        command=self._toggle_auto).grid(row=1, column=0, columnspan=4,
                                                        sticky="e", pady=(8, 0))

        ttk.Separator(root).pack(fill="x", pady=12)

        # -- pipeline and camera, side by side -----------------------------
        middle = ttk.Frame(root)
        middle.pack(fill="both", expand=True)

        steps_frame = ttk.LabelFrame(middle, text="This card", padding=12)
        steps_frame.pack(side="left", fill="both", expand=True)

        self.current_label = ttk.Label(steps_frame, text="Idle", font=("Helvetica", 12, "bold"))
        self.current_label.pack(anchor="w", pady=(0, 10))

        self.pipeline = console.PipelineView(steps_frame)
        self.pipeline.pack(fill="x")

        # Only card printers get this: a receipt has no badge id to report.
        self.session_frame = ttk.LabelFrame(steps_frame, text="Badges this session",
                                            padding=10)
        self.session_cards = console.SessionCards(self.session_frame)
        self.session_cards.pack(anchor="w")

        # Shown only when a fault has stopped the run.
        self.recovery = ttk.Frame(steps_frame)
        ttk.Label(self.recovery, text="Once the printer is fixed:").pack(side="left")
        ttk.Button(self.recovery, text="Reprint this card",
                   command=lambda: self._log("Reprint requested")).pack(side="left", padx=6)
        ttk.Button(self.recovery, text="Skip it",
                   command=lambda: self._log("Skip requested")).pack(side="left")

        camera_frame = ttk.LabelFrame(middle, text="Camera", padding=12)
        camera_frame.pack(side="right", fill="y", padx=(14, 0))

        self.camera_panel = console.CameraPanel(camera_frame)
        self.camera_panel.pack()

        # -- activity ------------------------------------------------------
        log_frame = ttk.LabelFrame(root, text="Activity", padding=8)
        log_frame.pack(fill="both", expand=True, pady=(12, 0))
        self.log_text = tk.Text(log_frame, height=7, wrap="word", state="disabled",
                                font=("Menlo", 10), bg="#f7f7f7", relief="flat")
        self.log_text.pack(fill="both", expand=True)

    # -- page switching ----------------------------------------------------

    def _show_console(self) -> None:
        self.picker_view.pack_forget()
        self.console_view.pack(fill="both", expand=True)

    def _show_picker(self) -> None:
        self.console_view.pack_forget()
        self.picker_view.pack(fill="both", expand=True)
        self._refresh_batches()

    def _select_batch(self, batch: dict) -> None:
        self.active_batch = batch
        self._show_console()
        self._render_batch()

        # Tell a worker that is already running. Without this the choice only
        # ever reached a worker built afterwards, so picking a batch while one
        # was alive left it claiming against no batch at all and reporting the
        # run finished a few seconds later.
        if self.worker is not None and self.worker.is_alive():
            self.worker.select_batch(batch.get("id"))

        self._log("Selected batch: %s" % batch.get("name", "?"))

    def _render_batch(self) -> None:
        batch = self.active_batch

        if not batch:
            self.batch_title.config(text="No batch selected")
            self.batch_counts.config(text="Nothing queued")
            self.batch_progress.config(value=0)
            self.pause_button.config(state="disabled")
            self.cancel_button.config(state="disabled")
            return

        totals = batch.get("totals") or {}
        jobs = totals.get("jobs", 0) or 0
        printed = totals.get("printed", 0) or 0

        self.batch_title.config(text="%s  -  %s" % (batch.get("name", "?"),
                                                    batch.get("status", "?")))
        self.batch_progress.config(value=(100.0 * printed / jobs) if jobs else 0)
        self.batch_counts.config(text="%d of %d printed, %d verified, %d failed" % (
            printed, jobs, totals.get("verified", 0) or 0, totals.get("failed", 0) or 0))

        self.pause_button.config(state="normal")
        self.cancel_button.config(state="normal")

    def _toggle_auto(self) -> None:
        if self.worker is not None:
            self.worker.set_unattended(self.auto_next.get())

        if self.auto_next.get():
            self._log("Unattended mode on: the next batch will be picked automatically")
        else:
            self._log("Unattended mode off: batches must be chosen by hand")

    def _on_worker_batch_change(self, batch_id) -> None:
        """The worker moved on by itself, which only happens unattended."""
        if batch_id is None:
            self.active_batch = None
            self._render_batch()
            self._log("Batch finished; nothing further queued")
            return

        self._log("Moved on to batch %s" % batch_id)
        self._refresh_batches()

    def _cancel_batch(self) -> None:
        if not self.active_batch:
            return

        confirmed = messagebox.askyesno(
            "Cancel this batch?",
            "Cards already printed stay printed. Everything still queued in "
            "'%s' will be cancelled and will not print.\n\nCancel the batch?"
            % self.active_batch.get("name", "?"))

        if not confirmed:
            return

        batch_id = self.active_batch.get("id")
        name = self.active_batch.get("name", "?")

        # Pause the worker first. Cancelling underneath a running worker leaves
        # it claiming from a batch the server has already closed, which comes
        # back as a refusal it has no useful way to explain.
        if self.worker is not None and self.worker.is_alive():
            self.worker.pause("Batch cancelled")

        try:
            client, _store, _notifier = self._services()
            client.cancel_batch(batch_id, "Cancelled at the print station")
        except Exception as error:  # noqa: BLE001 - the operator gets the message
            messagebox.showerror("Could not cancel",
                                 "The server refused to cancel %s:\n\n%s" % (name, error))
            return

        # Let go of it locally too. Previously this method only wrote a log
        # line: nothing reached the server and nothing was cleared here, so the
        # batch sat selected and "ready" as though the cancel had not happened.
        if self.worker is not None:
            self.worker.select_batch(None)

        self.active_batch = None
        self._render_batch()
        self._refresh_batches()
        self._log("Cancelled %s" % name)

    def _build_setup_tab(self) -> None:
        tab = ttk.Frame(self.tabs, padding=14)
        self.tabs.add(tab, text="  Setup  ")

        server = ttk.LabelFrame(tab, text="Server", padding=12)
        server.pack(fill="x")

        self.server_url = self._field(server, "Server URL", self.config_data.server_url, 0)
        self.api_token = self._field(server, "API token", self.config_data.api_token, 1, secret=True)
        ttk.Button(server, text="Test connection",
                   command=self._test_server).grid(row=2, column=1, sticky="w", pady=(8, 0))

        telegram_box = ttk.LabelFrame(tab, text="Telegram channel", padding=12)
        telegram_box.pack(fill="x", pady=(12, 0))

        self.telegram_enabled = tk.BooleanVar(value=self.config_data.telegram.enabled)
        ttk.Checkbutton(
            telegram_box,
            text="Post a photo of every card, with a remote pause button",
            variable=self.telegram_enabled,
        ).grid(row=0, column=0, columnspan=2, sticky="w", pady=(0, 6))

        self.telegram_token = self._field(
            telegram_box, "Bot token", self.config_data.telegram.bot_token, 1, secret=True)
        self.telegram_chat = self._field(
            telegram_box, "Chat ID", self.config_data.telegram.chat_id, 2)

        ttk.Label(
            telegram_box,
            text=("Create the bot with @BotFather, add it to the channel as an "
                  "administrator so it can post, then put the numeric chat ID here. "
                  "Pause and Resume buttons appear under every photo."),
            wraplength=560, style="Sub.TLabel", justify="left",
        ).grid(row=3, column=0, columnspan=2, sticky="w", pady=(8, 0))

        ttk.Button(telegram_box, text="Send a test message",
                   command=self._test_telegram).grid(row=4, column=1, sticky="w",
                                                     pady=(8, 0))

        # A station can drive several printers: two card printers for throughput
        # plus a thermal receipt printer. Each is configured separately.
        printers = ttk.LabelFrame(tab, text="Printers on this station", padding=12)
        printers.pack(fill="both", expand=True, pady=(12, 0))

        listing = ttk.Frame(printers)
        listing.pack(side="left", fill="y")

        self.printer_list = tk.Listbox(listing, height=7, width=34, exportselection=False)
        self.printer_list.pack()
        self.printer_list.bind("<<ListboxSelect>>", lambda _: self._on_printer_selected())

        list_buttons = ttk.Frame(listing)
        list_buttons.pack(fill="x", pady=(6, 0))
        ttk.Button(list_buttons, text="Add", width=8,
                   command=self._add_printer).pack(side="left")
        ttk.Button(list_buttons, text="Remove", width=9,
                   command=self._remove_printer).pack(side="left", padx=4)

        detail = ttk.Frame(printers, padding=(16, 0, 0, 0))
        detail.pack(side="left", fill="both", expand=True)

        installed = printing.list_printers()
        note = "" if installed else "  (no Windows spooler here)"

        ttk.Label(detail, text="Windows printer").grid(row=0, column=0, sticky="w", pady=4)
        self.binding_name = ttk.Combobox(detail, width=36, values=installed)
        self.binding_name.grid(row=0, column=1, sticky="w")
        ttk.Label(detail, text=note, style="Sub.TLabel").grid(row=0, column=2, sticky="w")

        self.binding_label = self._field(detail, "Name staff use", "", 1, width=36)

        ttk.Label(detail, text="Type").grid(row=2, column=0, sticky="w", pady=4)
        self.binding_role = ttk.Combobox(detail, width=20, state="readonly",
                                         values=["card", "receipt"])
        self.binding_role.grid(row=2, column=1, sticky="w")
        self.binding_role.bind("<<ComboboxSelected>>", lambda _: self._on_role_changed())

        self.binding_snmp = self._field(detail, "Printer IP (SNMP)", "", 3, width=36)
        self.binding_community = self._field(detail, "SNMP community", "public", 4, width=36)

        self.snmp_hint = ttk.Label(
            detail, text="Card printers report jams and ribbon level over SNMP.",
            style="Sub.TLabel")
        self.snmp_hint.grid(row=5, column=1, sticky="w")

        actions = ttk.Frame(detail)
        actions.grid(row=6, column=1, sticky="w", pady=(10, 0))
        ttk.Button(actions, text="Apply to printer",
                   command=self._apply_binding).pack(side="left")
        ttk.Button(actions, text="Test SNMP",
                   command=self._test_snmp).pack(side="left", padx=6)

        ttk.Button(tab, text="Save settings", style="Big.TButton",
                   command=self._save_config).pack(anchor="w", pady=(14, 0))

        ttk.Label(tab, text="Settings are stored in " + str(config_dir()),
                  style="Sub.TLabel").pack(anchor="w", pady=(8, 0))

        # Populating the list touches the camera tab's widgets, so it happens
        # once every tab exists rather than here. See _build_tabs.

    # -- printer bindings --------------------------------------------------

    def _reload_printer_list(self, select: int = 0) -> None:
        self.printer_list.delete(0, "end")

        for binding in self.config_data.printers:
            suffix = "" if binding.enabled else "  (off)"
            self.printer_list.insert("end", "%s  [%s]%s" % (
                binding.display_name(), binding.role, suffix))

        if self.config_data.printers:
            index = max(0, min(select, len(self.config_data.printers) - 1))
            self.printer_list.selection_set(index)
            self._on_printer_selected()

        self._refresh_printer_selector()

    def _selected_binding_index(self) -> Optional[int]:
        selection = self.printer_list.curselection()
        return selection[0] if selection else None

    def _on_printer_selected(self) -> None:
        index = self._selected_binding_index()
        if index is None:
            return

        binding = self.config_data.printers[index]

        self.binding_name.set(binding.name)
        self.binding_label.delete(0, "end")
        self.binding_label.insert(0, binding.label)
        self.binding_role.set(binding.role)
        self.binding_snmp.delete(0, "end")
        self.binding_snmp.insert(0, binding.snmp_host)
        self.binding_community.delete(0, "end")
        self.binding_community.insert(0, binding.snmp_community)

        self._on_role_changed()
        self._load_camera_fields(binding)

    def _on_role_changed(self) -> None:
        is_card = self.binding_role.get() == config_module.ROLE_CARD
        state = "normal" if is_card else "disabled"

        self.binding_snmp.config(state=state)
        self.binding_community.config(state=state)
        self.snmp_hint.config(
            text="Card printers report jams and ribbon level over SNMP."
            if is_card else "Thermal receipt printers have nothing useful to report.")

    def _add_printer(self) -> None:
        self.config_data.printers.append(
            config_module.PrinterBinding(name="", role=config_module.ROLE_CARD,
                                         label="New printer"))
        self._reload_printer_list(select=len(self.config_data.printers) - 1)
        self._log("Added a printer slot; fill in the details and Apply")

    def _remove_printer(self) -> None:
        index = self._selected_binding_index()
        if index is None:
            return

        removed = self.config_data.printers.pop(index)
        self._reload_printer_list()
        self._log("Removed %s" % removed.display_name())

    def _apply_binding(self) -> None:
        index = self._selected_binding_index()
        if index is None:
            messagebox.showinfo("No printer", "Add or select a printer first.")
            return

        binding = self.config_data.printers[index]
        binding.name = self.binding_name.get().strip()
        binding.label = self.binding_label.get().strip()
        binding.role = self.binding_role.get().strip() or config_module.ROLE_CARD
        binding.snmp_host = self.binding_snmp.get().strip()
        binding.snmp_community = self.binding_community.get().strip() or "public"

        self._reload_printer_list(select=index)
        self._log("Updated %s" % binding.display_name())

    def _refresh_printer_selector(self) -> None:
        names = [b.display_name() for b in self.config_data.printers]
        self.printer_selector.config(values=names or ["(no printers)"])

        if names and not self.printer_selector.get():
            self.printer_selector.set(names[0])

    def _build_camera_tab(self) -> None:
        tab = ttk.Frame(self.tabs, padding=14)
        self.tabs.add(tab, text="  Camera  ")

        ttk.Label(
            tab,
            text=("Optional. The camera confirms a card physically came out and that it is the "
                  "right one. Printing never depends on it, and no images leave this machine: "
                  "only the verdict is sent to the server."),
            wraplength=840, style="Sub.TLabel", justify="left",
        ).pack(anchor="w", pady=(0, 12))

        self.camera_scope = ttk.Label(tab, text="No printer selected",
                                      font=("Helvetica", 12, "bold"))
        self.camera_scope.pack(anchor="w", pady=(0, 8))

        self.camera_enabled = tk.BooleanVar(value=False)
        ttk.Checkbutton(tab, text="Enable camera verification for this printer",
                        variable=self.camera_enabled,
                        command=self._toggle_camera).pack(anchor="w")

        settings = ttk.Frame(tab)
        settings.pack(fill="x")
        ttk.Label(settings, text="Camera").grid(row=0, column=0, sticky="w", pady=4)
        self.camera_index = ttk.Combobox(settings, width=34, state="readonly", values=[])
        self.camera_index.grid(row=0, column=1, sticky="w")
        self.camera_index.bind("<<ComboboxSelected>>", lambda _: self._on_camera_chosen())
        ttk.Button(settings, text="Detect cameras",
                   command=self._detect_cameras).grid(row=0, column=2, padx=6)

        # Index to label, so the dropdown can show something human while the
        # config keeps storing the device number OpenCV needs.
        self._camera_choices: dict = {}

        calibration = ttk.LabelFrame(tab, text="Calibration", padding=12)
        calibration.pack(fill="both", expand=True, pady=(12, 0))

        ttk.Label(
            calibration,
            text=("Draw one zone over the output bin, covering where cards land. That is how "
                  "the agent sees that a card arrived at all.\n\n"
                  "Then drop 'card printed on' points on spots the artwork always covers, well "
                  "inside the card rather than near its edges: cards rise towards the lens as "
                  "the stack grows, and a point near an edge can drift off. These catch a card "
                  "that came out blank because the ribbon or transfer film ran out, which is "
                  "the fault that loses badges. Use two or three, and a majority decides.\n\n"
                  "'Output tray full' points are different: put one on the side of the chute, "
                  "on coloured tape if it helps. They ignore brightness, so the room lights can "
                  "go on and off without stopping the queue."),
            wraplength=820, style="Sub.TLabel", justify="left",
        ).pack(anchor="w", pady=(0, 10))

        self.calibration_list = tk.Listbox(calibration, height=6)
        self.calibration_list.pack(fill="both", expand=True)

        buttons = ttk.Frame(calibration)
        buttons.pack(anchor="w", pady=(8, 0))
        ttk.Button(buttons, text="Open live preview and calibrate",
                   command=self._open_calibration).pack(side="left")

    def _build_history_tab(self) -> None:
        """What has happened, per card and per printer.

        Kept locally so the question "what happened to badge 24-0031" can be
        answered at the station, weeks later, with no network and without
        anybody logging into the admin panel.
        """
        tab = ttk.Frame(self.tabs, padding=14)
        self.tabs.add(tab, text="  History  ")

        top = ttk.Frame(tab)
        top.pack(fill="x")

        ttk.Label(top, text="Search").pack(side="left")
        self.history_search = ttk.Entry(top, width=28)
        self.history_search.pack(side="left", padx=6)
        self.history_search.bind("<Return>", lambda _: self._refresh_history())

        ttk.Button(top, text="Find", command=self._refresh_history).pack(side="left")
        ttk.Button(top, text="Show all",
                   command=self._clear_history_search).pack(side="left", padx=6)
        ttk.Button(top, text="Refresh", command=self._refresh_history).pack(side="left")

        ttk.Label(top, text="badge number, fursuit name, or any word in the detail",
                  style="Sub.TLabel").pack(side="left", padx=(10, 0))

        columns = ("at", "kind", "card", "fursuit", "printer", "outcome", "detail")
        self.history_tree = ttk.Treeview(tab, columns=columns, show="headings", height=18)

        for column, heading, width in (
            ("at", "When", 140),
            ("kind", "What", 70),
            ("card", "Badge", 90),
            ("fursuit", "Fursuit", 150),
            ("printer", "Printer", 150),
            ("outcome", "Outcome", 110),
            ("detail", "Detail", 380),
        ):
            self.history_tree.heading(column, text=heading)
            self.history_tree.column(column, width=width, anchor="w")

        # Failures should be findable by eye when scrolling a long day.
        self.history_tree.tag_configure("bad", foreground="#a81f1f")
        self.history_tree.tag_configure("warn", foreground="#a86a00")

        scroll = ttk.Scrollbar(tab, orient="vertical", command=self.history_tree.yview)
        self.history_tree.configure(yscrollcommand=scroll.set)

        self.history_tree.pack(side="left", fill="both", expand=True, pady=(12, 0))
        scroll.pack(side="right", fill="y", pady=(12, 0))

        self.history_status = ttk.Label(tab, text="", style="Sub.TLabel")

    def _clear_history_search(self) -> None:
        self.history_search.delete(0, "end")
        self._refresh_history()

    def _refresh_history(self) -> None:
        self.history_tree.delete(*self.history_tree.get_children())

        try:
            _, store, _ = self._services()
            rows = store.history(limit=500, search=self.history_search.get().strip())
        except Exception as error:
            self._log("Could not read history: %s" % error)
            return

        for row in rows:
            outcome = (row.get("outcome") or "").lower()

            tags = ()
            if outcome in ("failed", "error", "cancelled") or "jam" in outcome:
                tags = ("bad",)
            elif outcome in ("unverified", "unreadable", "retrying") or "low" in outcome:
                tags = ("warn",)

            self.history_tree.insert("", "end", tags=tags, values=(
                row.get("at", ""),
                row.get("kind", ""),
                row.get("card_number") or "",
                row.get("fursuit_name") or "",
                row.get("printer_name") or "",
                row.get("outcome") or "",
                row.get("detail") or "",
            ))

    def _build_diagnostics_tab(self) -> None:
        tab = ttk.Frame(self.tabs, padding=14)
        self.tabs.add(tab, text="  Diagnostics  ")

        ttk.Label(
            tab,
            text=("Zebra never published a fault-code list for this printer, so the agent writes "
                  "down anything it does not recognise. If the printer misbehaves, send this "
                  "journal over and the agent gets taught what it means."),
            wraplength=840, style="Sub.TLabel", justify="left",
        ).pack(anchor="w", pady=(0, 12))

        self.journal_label = ttk.Label(tab, text="Journal: 0 entries", font=("Helvetica", 12))
        self.journal_label.pack(anchor="w")

        buttons = ttk.Frame(tab)
        buttons.pack(anchor="w", pady=(10, 0))
        ttk.Button(buttons, text="Open folder", command=self._open_folder).pack(side="left")
        ttk.Button(buttons, text="Refresh", command=self._refresh_journal).pack(side="left", padx=6)

        reading_frame = ttk.LabelFrame(tab, text="Last printer reading", padding=8)
        reading_frame.pack(fill="both", expand=True, pady=(12, 0))
        self.reading_text = tk.Text(reading_frame, wrap="word", state="disabled",
                                    font=("Menlo", 10), bg="#f7f7f7", relief="flat")
        self.reading_text.pack(fill="both", expand=True)

    def _build_statusbar(self) -> None:
        bar = ttk.Frame(self, padding=(14, 6))
        bar.pack(fill="x", side="bottom")
        self.status_label = ttk.Label(bar, text="Starting", style="Sub.TLabel")
        self.status_label.pack(side="left")
        self.clock_label = ttk.Label(bar, text="", style="Sub.TLabel")
        self.clock_label.pack(side="right")

    def _field(self, parent, label, value, row, secret=False, width=44):
        ttk.Label(parent, text=label).grid(row=row, column=0, sticky="w", pady=4)
        entry = ttk.Entry(parent, width=width, show="*" if secret else "")
        entry.insert(0, value or "")
        entry.grid(row=row, column=1, sticky="w")
        return entry

    # -- monitor -----------------------------------------------------------

    def _selected_card_binding(self):
        """The printer the header is currently reporting on."""
        chosen = self.printer_selector.get()

        for binding in self.config_data.printers:
            if binding.display_name() == chosen:
                return binding

        cards = self.config_data.card_printers()
        return cards[0] if cards else None

    def _load_camera_fields(self, binding) -> None:
        self.camera_scope.config(text="Camera for %s" % binding.display_name())
        self.camera_enabled.set(binding.camera.enabled)
        self._show_camera_choice(binding.camera.device_index)

        self.calibration_list.delete(0, "end")

        for zone in binding.camera.zones:
            self.calibration_list.insert(
                "end", "zone   %-16s %s" % (zone.purpose, zone.name or "(unnamed)"))

        for point in binding.camera.checkpoints:
            state = "calibrated" if point.calibrated else "NOT CALIBRATED"
            self.calibration_list.insert(
                "end", "point  %-16s %s  [%s]" % (point.purpose, point.name or "(unnamed)", state))

        if not binding.camera.zones and not binding.camera.checkpoints:
            self.calibration_list.insert("end", "Nothing calibrated yet for this printer.")

    def _on_selector_changed(self) -> None:
        binding = self._selected_card_binding()
        if binding:
            self._load_camera_fields(binding)
        self._log("Now showing %s" % (binding.display_name() if binding else "nothing"))
        self._sync_camera_preview()
        self._sync_session_panel()

    def _sync_session_panel(self) -> None:
        """Show the badge readout only for a printer that produces badges."""
        if self._selected_card_binding() is not None:
            self.session_frame.pack(fill="x", pady=(14, 0))
        else:
            self.session_frame.pack_forget()

    # -- camera selection --------------------------------------------------

    def _on_tab_changed(self, _event=None) -> None:
        """Probe cameras the first time the Camera tab is opened."""
        try:
            current = self.tabs.tab(self.tabs.select(), "text").strip()
        except Exception:
            return

        if current == "Camera" and not self._cameras_probed:
            self._cameras_probed = True
            self._detect_cameras(quiet=True)

        # Load on open rather than polling: history only changes when a card
        # finishes, and nobody is watching this tab while that happens.
        if current == "History":
            self._refresh_history()

    def _detect_cameras(self, quiet: bool = False) -> None:
        """Probe attached cameras so the operator picks from a list.

        Asking someone at a convention desk to guess a device number is a good
        way to have the agent watching the wrong lens.
        """
        from .. import camera as camera_module

        found = camera_module.list_cameras()

        if not found:
            self.camera_index.config(values=[])
            self._log("No cameras responded")

            # Silent when this ran by itself on opening the tab: somebody who
            # never intended to use a camera should not be met with a warning.
            if not quiet:
                messagebox.showwarning(
                    "No cameras",
                    "No cameras responded.\n\nCheck the camera is plugged in and that "
                    "nothing else has it open. If OpenCV is not installed on this "
                    "machine, camera verification cannot run at all.")
            return

        # Keep whatever is on screen. Re-probing used to reset the dropdown to
        # the saved binding, so picking camera 1 and then pressing Detect threw
        # the choice away and silently went back to camera 0 - which is also
        # the camera the console then previewed.
        previous = self._selected_camera_index() if self.camera_index.get() else None

        self._camera_choices = {index: label for index, label in found}
        self.camera_index.config(values=[label for _, label in found])

        if previous is None:
            binding = self._selected_card_binding()
            previous = binding.camera.device_index if binding else 0

        self._show_camera_choice(previous)

        self._log("Found %d camera(s)" % len(found))

    def _on_camera_chosen(self) -> None:
        """Apply the chosen camera to the live binding straight away.

        The console preview and the calibration page both read the binding, not
        this widget, so leaving it until Save meant the Camera tab said camera 1
        while the Run page was still watching camera 0. Save is what makes it
        survive a restart; this is what makes it take effect now.
        """
        binding = self._selected_card_binding()

        if binding is None:
            return

        chosen = self._selected_camera_index()

        if chosen == binding.camera.device_index:
            return

        binding.camera.device_index = chosen
        self._log("Camera for %s set to %d" % (binding.display_name(), chosen))

        # Repoint the preview, which also hands the old device back.
        self._sync_camera_preview()

    def _show_camera_choice(self, device_index: int) -> None:
        """Show the saved camera, even before a probe has run."""
        label = self._camera_choices.get(device_index)

        if label is None:
            label = "Camera %d  (not detected yet)" % device_index
            values = list(self.camera_index.cget("values") or [])
            if label not in values:
                self.camera_index.config(values=values + [label])

        self.camera_index.set(label)

    def _selected_camera_index(self) -> int:
        """Map the chosen label back to the device number OpenCV wants."""
        chosen = self.camera_index.get()

        for index, label in self._camera_choices.items():
            if label == chosen:
                return index

        # Covers the "not detected yet" placeholder, which still carries the
        # number the operator previously saved.
        digits = "".join(c for c in chosen.split("(")[0] if c.isdigit())

        return int(digits) if digits else 0

    # -- live preview ------------------------------------------------------

    def _sync_camera_preview(self) -> None:
        """Point the console preview at the printer the header is showing.

        Called whenever the choice could have changed. Stopping first and
        starting after means the device is never held twice, which is the
        failure that hangs a capture on Windows.
        """
        # On the way out the calibration page closing would otherwise ask for
        # the preview back, and the agent would open the webcam as it quits.
        if self.headless or self.stop_flag.is_set():
            return

        self._stop_camera_preview()

        # The calibration page has the camera while it is open; the console
        # preview waits its turn rather than fighting over the device.
        if self.calibration_page is not None:
            self.camera_panel.show_placeholder("Camera is in use by the calibration window")
            return

        binding = self._selected_card_binding()

        if binding is None:
            self.camera_panel.show_placeholder("No printer selected")
            return

        if not binding.camera.enabled:
            self.camera_panel.show_placeholder("Camera verification is off for this printer")
            return

        self.frame_source = calibration.FrameSource(binding.camera.device_index).start()
        self.camera_panel.attach(self.frame_source, binding.camera.zones,
                                 binding.camera.checkpoints)

    def _stop_camera_preview(self) -> None:
        self.camera_panel.detach()

        if self.frame_source is not None:
            self.frame_source.stop()
            self.frame_source = None

    def _open_calibration(self):
        """Open the live preview where zones and points are drawn.

        Returns the page so the headless smoke check can inspect it. In a real
        session it lives in its own window, because calibrating means looking
        at a big picture while the console keeps reporting the printer.
        """
        binding = self._selected_card_binding()

        if binding is None:
            if not self.headless:
                messagebox.showinfo("No printer", "Add or select a printer first.")
            return None

        if self.calibration_page is not None:
            if self.calibration_window is not None:
                self.calibration_window.lift()
            return self.calibration_page

        # Hand the camera over: the console preview lets go before the page
        # opens it, and gets it back when the page closes.
        self._stop_camera_preview()

        device_index = binding.camera.device_index
        try:
            device_index = self._selected_camera_index()
        except ValueError:
            pass

        source = calibration.FrameSource(device_index)

        if self.headless:
            host = self
        else:
            host = tk.Toplevel(self)
            host.title("Calibrate the camera for %s" % binding.display_name())
            host.minsize(980, 700)
            host.geometry("1120x820")
            self.calibration_window = host
            source.start()

        self.calibration_page = calibration.CalibrationPage(
            host,
            binding=binding,
            config_data=self.config_data,
            on_close=self._close_calibration,
            on_saved=self._calibration_saved,
            source=source,
            headless=self.headless,
        )

        if not self.headless:
            self.calibration_page.pack(fill="both", expand=True)
            host.protocol("WM_DELETE_WINDOW", self.calibration_page.close)

        self._log("Calibrating the camera for %s" % binding.display_name())

        return self.calibration_page

    def _calibration_saved(self, zones: int, points: int, uncalibrated: int) -> None:
        self._log("Calibration saved: %d zone(s), %d point(s)" % (zones, points))

        if uncalibrated:
            self._log("%d point(s) still have no reference colour and will be ignored"
                      % uncalibrated)

        binding = self._selected_card_binding()

        if binding is not None:
            self._load_camera_fields(binding)

    def _close_calibration(self) -> None:
        self.calibration_page = None

        window = self.calibration_window
        self.calibration_window = None

        if window is not None:
            window.destroy()

        self._sync_camera_preview()

    def _start_monitor(self) -> None:
        binding = self._selected_card_binding()

        poller = DemoPoller() if self.demo else zebra.ZebraPoller(
            binding.snmp_host if binding else "",
            binding.snmp_community if binding else "public")

        self.monitor = monitor_module.PrinterMonitor(
            poller, vocabulary.ConditionJournal(),
            ribbon_warn_threshold=self.config_data.ribbon_warn_threshold,
        )
        self.monitor.on_change.append(
            lambda condition, _: self.events.put(("condition", condition)))
        self.monitor.on_unknown.append(
            lambda _: self.events.put(("unknown", None)))

        thread = threading.Thread(target=self._monitor_loop, daemon=True)
        thread.start()

    def _monitor_loop(self) -> None:
        while not self.stop_flag.is_set():
            try:
                self.monitor.poll()
                self.events.put(("reading", None))
            except Exception as error:
                self.events.put(("log", "Monitor error: %s" % error))

            self.stop_flag.wait(POLL_SECONDS)

    def _sync_controls(self) -> None:
        """Start follows the worker's real state, not the last button pressed.

        The worker pauses itself whenever the printer or the server says no,
        and nobody clicked anything on the way. Setting the button state only
        inside the click handlers left Start greyed out on a run that had
        already stopped, with no way back other than restarting the agent.
        """
        running = self.worker is not None and self.worker.is_alive()
        printing = running and not self.worker.is_paused()

        self.start_button.config(state="disabled" if printing else "normal")

    def _drain_events(self) -> None:
        try:
            while True:
                kind, payload = self.events.get_nowait()

                if kind == "condition":
                    self._apply_condition(payload)
                    self._log("Printer: %s" % LABELS.get(payload, payload))
                elif kind == "reading":
                    self._apply_reading()
                elif kind == "unknown":
                    self._log("Unrecognised printer state written to the journal")
                    self._refresh_journal()
                elif kind == "progress":
                    step, state, detail = payload
                    self.pipeline.set(step, state, detail)
                    if step == "claim" and state == console.ACTIVE:
                        self.pipeline.reset()
                        self.pipeline.set(step, state, detail)
                    self.camera_panel.set_checking(
                        detail if step == "camera" and state == console.ACTIVE else "")
                elif kind == "card":
                    card_number, outcome = payload
                    # Only cards that actually printed. A blank or a failure is
                    # not a badge anybody can be handed, and counting it would
                    # make the readout lie about what is in the bin.
                    if outcome == "printed":
                        self.session_cards.record(card_number)

                    # Whatever happened, the batch has moved on. Without this
                    # the header kept whatever totals the picker handed over,
                    # so a finished card was never ticked off and the batch sat
                    # at "ready" until the next claim announced it complete.
                    self._sync_active_batch()
                elif kind == "telegram":
                    self._on_telegram_command(payload)
                elif kind == "decision":
                    self._ask_reprint(payload)
                elif kind == "batch_change":
                    self._on_worker_batch_change(payload)
                elif kind == "log":
                    self._log(payload)
        except queue.Empty:
            pass

        self._sync_controls()
        self.clock_label.config(text=datetime.now().strftime("%H:%M:%S"))
        self.after(400, self._drain_events)

    def _apply_condition(self, condition: str) -> None:
        background, foreground = COLOURS.get(
            condition, STOP_COLOUR if zebra.is_stop(condition) else IDLE_COLOUR)

        self.condition_pill.config(text=LABELS.get(condition, condition.upper()),
                                   bg=background, fg=foreground)

        reason = self.monitor.blocking_reason() if self.monitor else None

        if reason and zebra.is_stop(condition):
            self.banner_label.config(text="PRINTING STOPPED  -  " + reason)
            self.banner.pack(fill="x", before=self.tabs)
            self.recovery.pack(anchor="w", pady=(12, 0))
        else:
            self.banner.pack_forget()
            self.recovery.pack_forget()

    def _apply_reading(self) -> None:
        reading = self.monitor.reading if self.monitor else None

        if reading is None:
            return

        if reading.supply_level is not None and reading.supply_max:
            percent = 100.0 * reading.supply_level / reading.supply_max
            self.ribbon_bar.config(value=percent)
            self.ribbon_label.config(
                text="Ribbon: %d of %d cards" % (reading.supply_level, reading.supply_max))
        else:
            self.ribbon_bar.config(value=0)
            self.ribbon_label.config(text="Ribbon: unknown")

        warning = self.monitor.ribbon_warning()
        self.status_label.config(text=warning or ("Printer reachable" if reading.reachable
                                                  else "Printer not answering"))

        lines = [
            "reachable     : %s" % reading.reachable,
            "printer state : %s" % (reading.printer_state or "-"),
            "condition     : %s" % self.monitor.condition,
            "may print     : %s" % self.monitor.may_print(),
            "error bits    : %s" % (", ".join(reading.error_bits) or "none"),
            "alarms        : %s" % (", ".join(a for a in reading.alarms if a) or "none"),
            "sensor fault  : %s" % (reading.sensor_fault or "none"),
            "ribbon        : %s / %s %s" % (reading.supply_level, reading.supply_max,
                                            reading.supply_description),
            "",
            "job table (newest last):",
        ]
        for row in reading.jobs:
            lines.append("  #%s  %-14s %-16s %s" % (row.job_id, row.state or "-",
                                                    row.location or "-", row.uuid or ""))

        self.reading_text.config(state="normal")
        self.reading_text.delete("1.0", "end")
        self.reading_text.insert("1.0", "\n".join(lines))
        self.reading_text.config(state="disabled")

    # -- actions -----------------------------------------------------------

    def _save_config(self) -> None:
        self.config_data.server_url = self.server_url.get().strip()
        self.config_data.api_token = self.api_token.get().strip()

        self.config_data.telegram.enabled = self.telegram_enabled.get()
        self.config_data.telegram.bot_token = self.telegram_token.get().strip()
        self.config_data.telegram.chat_id = self.telegram_chat.get().strip()

        # Fold in whatever the printer detail pane is currently showing, so a
        # forgotten Apply does not silently discard an edit.
        if self._selected_binding_index() is not None:
            self._apply_binding()

        binding = self._selected_card_binding()
        if binding:
            binding.camera.enabled = self.camera_enabled.get()
            try:
                binding.camera.device_index = self._selected_camera_index()
            except ValueError:
                binding.camera.device_index = 0

        self.config_data.save()
        self._log("Settings saved to %s" % self.config_data.path)

        # A new device number or a flipped switch changes what the preview
        # should be showing, so it is restarted against the saved settings.
        self._sync_camera_preview()

        messagebox.showinfo("Saved", "Settings saved.\n\nRestart the agent to reconnect.")

    def _test_snmp(self) -> None:
        host = self.binding_snmp.get().strip()

        if not host:
            messagebox.showwarning("No address", "Enter the printer's IP address first.")
            return

        reading = zebra.ZebraPoller(host, self.binding_community.get().strip() or "public").read()

        if not reading.reachable:
            messagebox.showerror(
                "No answer",
                "No SNMP response from %s.\n\nCheck the printer is powered on and on the "
                "network, and that SNMP is enabled." % host)
            self._log("SNMP test failed for %s" % host)
            return

        condition = zebra.classify(reading)
        messagebox.showinfo(
            "Printer found",
            "State: %s\nCondition: %s\nRibbon: %s of %s cards" % (
                reading.printer_state, condition, reading.supply_level, reading.supply_max))
        self._log("SNMP test OK: %s" % condition)

    def _test_telegram(self) -> None:
        """Post a test message using whatever is typed in the fields now.

        Built from the entry boxes rather than the saved config, for the same
        reason Test connection is: somebody wants to know a freshly pasted
        token works before committing it.
        """
        from .. import telegram as telegram_module
        from ..config import TelegramConfig

        token = self.telegram_token.get().strip()
        chat = self.telegram_chat.get().strip()

        if not token or not chat:
            messagebox.showwarning(
                "Missing details", "Enter both the bot token and the chat ID first.")
            return

        probe = TelegramConfig(enabled=True, bot_token=token, chat_id=chat)
        channel = telegram_module.TelegramChannel(probe)

        if channel.send_message("Badge Print Agent connected. This is a test."):
            messagebox.showinfo(
                "Sent",
                "A test message is in the channel. If the buttons under it do "
                "nothing yet, that is expected until a batch is running.")
            self._log("Telegram test message sent")
            return

        # The channel swallows its own errors by design, so there is no
        # exception to show. These two are almost always the cause.
        messagebox.showerror(
            "Telegram refused the message",
            "Nothing was posted.\n\nUsual causes: the chat ID is wrong, or the "
            "bot has not been added to the channel as an administrator.")
        self._log("Telegram test message failed")

    def _test_server(self) -> None:
        """Prove the token works, using whatever is typed in the fields now.

        Deliberately built from the entry boxes rather than the saved config, so
        an operator can check a URL or a freshly pasted token before committing
        it. The same reason Test SNMP reads the printer field directly.
        """
        url = self.server_url.get().strip()
        token = self.api_token.get().strip()

        if not url or not token:
            messagebox.showwarning(
                "Missing details", "Enter both the server URL and the API token first.")
            return

        from .. import api as api_module
        from ..config import AgentConfig

        probe = AgentConfig(server_url=url, api_token=token)

        try:
            result = api_module.PrintAgentClient(probe).config()
        except api_module.ApiError as error:
            # A rejected token is by far the most common cause, so say so rather
            # than making somebody interpret an HTTP status.
            hint = ("The token was rejected. Issue a new one on the server with "
                    "`php artisan print-agent:token`.") if error.status in (401, 403) \
                else "The server answered HTTP %s." % error.status
            messagebox.showerror("Server refused the connection", hint)
            self._log("Server test failed: HTTP %s" % error.status)
            return
        except api_module.NetworkError as error:
            messagebox.showerror(
                "Cannot reach the server",
                "No answer from %s.\n\nCheck the address and that this machine "
                "has internet access.\n\n%s" % (url, error))
            self._log("Server test failed: unreachable")
            return
        except Exception as error:
            messagebox.showerror("Server test failed", str(error))
            self._log("Server test failed: %s" % error)
            return

        machine = result.get("machine") or {}
        printers = result.get("printers") or []

        messagebox.showinfo(
            "Connected",
            "Signed in as: %s (machine #%s)\nPrinters registered: %d\nServer time: %s"
            % (machine.get("name", "?"), machine.get("id", "?"), len(printers),
               result.get("server_time", "?")))

        self.machine_label.config(text="%s  -  %s" % (machine.get("name", "?"), url))
        self._log("Server OK: %s (machine #%s)" % (machine.get("name", "?"), machine.get("id", "?")))

    def _sync_active_batch(self) -> None:
        """Re-read the selected batch from the server and redraw the header.

        A batch the server has finished drops out of the selectable list, so
        its absence is how we learn it is done. That is also the moment to let
        go of it: leaving it selected leaves an operator looking at a batch
        that cannot yield another card.
        """
        if self.demo or not self.active_batch:
            return

        batch_id = self.active_batch.get("id")

        try:
            client, _store, _notifier = self._services()
            batches = client.batches()
        except Exception:  # noqa: BLE001 - a stale header beats a crashed UI
            return

        for batch in batches or []:
            if batch.get("id") == batch_id:
                self.active_batch = batch
                self._render_batch()
                return

        # Gone from the list: finished, cancelled, or no longer ours.
        self.active_batch = None
        self._render_batch()
        self._log("Batch %s is finished." % batch_id)

    def _refresh_batches(self) -> None:
        """Fill the chooser with what the server is offering.

        Demo mode fabricates a couple so the console can be judged without a
        server.
        """
        if self.demo:
            self.picker_view.show([
                {"id": 1, "name": "Friday 14:00 pickup", "status": "ready",
                 "printer_name": "Card printer left",
                 "totals": {"jobs": 120, "printed": 0, "verified": 0, "failed": 0}},
                {"id": 2, "name": "Friday 16:30 pickup", "status": "paused",
                 "printer_name": "Card printer left",
                 "totals": {"jobs": 84, "printed": 31, "verified": 30, "failed": 1}},
            ])
            return

        if not self.config_data.is_configured():
            self.picker_view.show([])
            self._log("Set the server URL and token in Setup first")
            return

        from .. import api as api_module

        try:
            client, _, _ = self._services()
            # Already unwrapped by the client: this is the list, not the envelope.
            batches = client.batches()
        except api_module.ApiError as error:
            self.picker_view.show([])
            self._log("Could not load batches: HTTP %s" % error.status)
            messagebox.showerror(
                "Could not load batches", "The server answered HTTP %s." % error.status)
            return
        except Exception as error:
            self.picker_view.show([])
            self._log("Could not load batches: %s" % error)
            messagebox.showerror("Could not load batches", str(error))
            return

        self.picker_view.show(batches)
        self._log("%d batch(es) available" % len(batches))

    def _start_printing(self) -> None:
        if not self.active_batch:
            messagebox.showinfo("No batch", "Choose a print batch first.")
            self._show_picker()
            return

        if self.monitor and not self.monitor.may_print():
            messagebox.showerror("Printer not ready", self.monitor.blocking_reason())
            return

        if self.worker is not None and self.worker.is_alive():
            if self.worker.is_paused():
                self.worker.resume()
                self._log("Resumed")
                self.pause_button.config(state="normal")
                self.start_button.config(state="disabled")
            return

        # Telegram first: the worker's notifier wraps the channel, so a channel
        # brought up afterwards would leave faults going to Pushover only.
        self._start_telegram()

        worker = self._build_worker()

        if worker is None:
            return

        self.worker = worker
        self.pipeline.reset()
        worker.start()

        self._log("Printing %s" % self.active_batch.get("name", "?"))
        self.pause_button.config(state="normal")
        self.start_button.config(state="disabled")

    def _build_worker(self):
        """Assemble a worker for the selected printer and batch.

        Every callback hands off through the event queue rather than touching
        widgets: they arrive on the worker's own thread, and Tk is not safe to
        drive from anywhere but the main one.
        """
        from .. import worker as worker_module

        binding = self._selected_card_binding()

        if binding is None:
            messagebox.showerror("No printer", "Configure a card printer in Setup first.")
            return None

        if not self.config_data.is_configured():
            messagebox.showerror(
                "Not configured",
                "Set the server URL and API token in Setup before printing.")
            return None

        try:
            client, store, notifier = self._services()
        except Exception as error:
            messagebox.showerror("Cannot start", "Could not reach local services:\n\n%s" % error)
            return None

        worker = worker_module.PrintWorker(
            binding=binding,
            api=client,
            store=store,
            monitor=self.monitor,
            sender=self._send_to_printer,
            notifier=self._build_notifier(notifier),
            verifier=self._build_verifier(binding),
            batch_id=self.active_batch.get("id") if self.active_batch else None,
            unattended=self.auto_next.get(),
            on_progress=lambda step, state, detail: self.events.put(
                ("progress", (step, state, detail))),
            on_decision=lambda decision: self.events.put(("decision", decision)),
            on_log=lambda message: self.events.put(("log", message)),
            on_batch_change=lambda batch_id: self.events.put(("batch_change", batch_id)),
        )

        # Set here rather than passed in: only the card console shows the
        # session readout, so it does not need threading through every worker
        # constructor.
        worker.on_card = self._on_card_finished

        return worker

    def _build_notifier(self, notifier):
        """Fault alerts, to Pushover and to the chat.

        The Telegram channel only carries cards and faults, which makes it no
        use as a warning system unless the faults reach it. They used to go to
        Pushover alone.
        """
        from .. import telegram as telegram_module

        if self.telegram_channel is None or not self.telegram_channel.is_configured():
            return notifier

        return telegram_module.AlertRelay(self.telegram_channel, notifier)

    def _build_verifier(self, binding):
        """The camera check for this printer, reading the console's frames.

        It deliberately does not open a capture of its own. The device is held
        in exactly one place at a time -- the console preview or the
        calibration window -- because two cv2 captures on one device hangs on
        Windows. The source is read through a lambda rather than captured by
        value so that swapping printers, or the calibration window taking the
        camera mid-run, is picked up on the next frame instead of leaving the
        verifier holding a dead handle.

        No frames means unverified, which is a state the queue already handles.
        """
        from .. import verifier as verifier_module

        return verifier_module.CameraVerifier(
            binding,
            frames=lambda: self.frame_source.latest() if self.frame_source else None,
        )

    def _on_card_finished(self, card_number, outcome, job, detail="") -> None:
        """A card reached a terminal outcome. Runs on the worker's thread.

        Nothing here may block: the worker is holding the printer while this
        returns. The queue put is instant, and the photo is handed to a sender
        thread rather than posted inline.
        """
        self.events.put(("card", (card_number, outcome)))

        if self.telegram_sender is None:
            return

        verdict = detail or outcome
        frame = getattr(self.worker.verifier, "last_frame", None) \
            if getattr(self.worker, "verifier", None) is not None else None

        self.telegram_sender.submit(
            job,
            frame,
            verdict=verdict,
            printer=self.worker.printer_name if self.worker else "",
            position=(self.active_batch or {}).get("name", ""),
            paused=self._is_paused(),
        )

    def _services(self):
        """Lazily built so the UI still opens on a machine missing a dependency."""
        from .. import api as api_module
        from .. import notify as notify_module
        from .. import store as store_module

        if self._client is None:
            self._client = api_module.PrintAgentClient(self.config_data)
        if self._store is None:
            self._store = store_module.LocalStore()
        if self._notifier is None:
            self._notifier = notify_module.Notifier(self.config_data.pushover)

        return self._client, self._store, self._notifier

    def _send_to_printer(self, job, path, binding):
        """Hand a rendered PDF to the Windows spooler."""
        from .. import printing as printing_module
        from .. import render as render_module

        pages = render_module.render_pdf(path)

        return printing_module.print_pages(
            binding.name, pages, job_name="Badge %s" % (job.get("expected") or {}).get(
                "custom_id", job.get("id")))

    def _pause_printing(self) -> None:
        if self.worker is not None and self.worker.is_alive():
            self.worker.pause("Paused by operator")

        self._log("Paused by operator")
        self.pause_button.config(state="disabled")
        # Start doubles as Resume, so it has to come back when paused.
        self.start_button.config(state="normal")

    # -- telegram ----------------------------------------------------------

    def _start_telegram(self) -> None:
        """Bring the channel up alongside a run, if one is configured."""
        from .. import telegram as telegram_module

        # Deliberately not is_configured(): with only a token the poller can
        # still notice the bot being added to a chat and reply with the id,
        # which is how the id gets into the config at all.
        if not (self.config_data.telegram.enabled and self.config_data.telegram.bot_token):
            return

        if self.telegram_channel is None:
            self.telegram_channel = telegram_module.TelegramChannel(
                self.config_data.telegram)

        if self.telegram_sender is None:
            self.telegram_sender = telegram_module.PhotoSender(self.telegram_channel)

        if self.telegram_poller is None:
            self.telegram_poller = telegram_module.CommandPoller(
                self.telegram_channel,
                # Straight onto the event queue: this arrives on the poller's
                # thread and Tk may only be driven from the main one.
                on_command=lambda command: self.events.put(("telegram", command)),
            )

        self.telegram_sender.start()
        self.telegram_poller.start()

    def _stop_telegram(self) -> None:
        for service in (self.telegram_poller, self.telegram_sender):
            if service is not None:
                service.stop()

    def _on_telegram_command(self, command) -> None:
        """Somebody pressed a button, or typed one of the commands."""
        from .. import telegram as telegram_module

        action = command.get("command")
        who = command.get("from") or "someone"

        if action == telegram_module.COMMAND_CHATID:
            # Answered in whichever chat asked, not the configured one: the
            # whole point is that the configured one may not exist yet.
            chat_id = command.get("chat_id")

            if chat_id is not None and self.telegram_channel is not None:
                self.telegram_channel.announce_chat_id(chat_id)

            reply = "Told %s the chat ID is %s" % (who, chat_id)
        elif action == telegram_module.COMMAND_PAUSE:
            self._pause_printing()
            reply = "Paused by %s from Telegram" % who
        elif action == telegram_module.COMMAND_RESUME:
            # Deliberately only unpauses an existing run, rather than calling
            # the Start button's handler. That one puts a dialog on screen when
            # no batch is chosen, and a modal raised by somebody in another
            # building is one nobody present knows to dismiss.
            if self.worker is not None and self.worker.is_alive() and self.worker.is_paused():
                self.worker.resume()
                self.pause_button.config(state="normal")
                self.start_button.config(state="disabled")
                reply = "Resumed by %s from Telegram" % who
            else:
                reply = "Nothing to resume: %s" % self._telegram_status()
        else:
            reply = self._telegram_status()

        self._log(reply)

        callback_id = command.get("callback_id")

        if callback_id and self.telegram_channel is not None:
            self.telegram_channel.answer_callback(callback_id, reply[:200])

        # Deliberately no chat message confirming a pause or resume. The
        # callback answer above already tells whoever pressed the button, and
        # the next card photo carries a fresh keyboard. The channel is for
        # cards and faults; anything else buries them.

    def _is_paused(self) -> bool:
        return bool(self.worker is not None and self.worker.is_alive()
                    and self.worker.is_paused())

    def _telegram_status(self) -> str:
        if self.worker is None or not self.worker.is_alive():
            return "Not printing."

        batch = (self.active_batch or {}).get("name", "?")
        tally = self.session_cards.tally

        return "%s %s. %d badges this session%s." % (
            "Paused during" if self._is_paused() else "Printing",
            batch,
            tally.count,
            ", last %s" % tally.last if tally.last else "",
        )

    def _ask_reprint(self, decision) -> None:
        """No camera, so a human decides, with the card number in front of them.

        The worker deliberately refuses to guess here. Reprinting a card that
        actually came out wastes a blank and a ribbon panel; not reprinting one
        that did not means an attendee turns up to no badge.

        Three answers, and no way to leave without giving one. The window has
        no close button, Escape does nothing and there is no default: closing
        the question used to leave the card neither printed nor reprinted nor
        recorded, which is the one state nobody can act on later.
        """
        from .. import worker as worker_module

        window = tk.Toplevel(self)
        window.title("Card %s did not print" % decision.card_number)
        window.transient(self)
        window.resizable(False, False)

        # No dismissing it. Every route out of this window is a decision.
        window.protocol("WM_DELETE_WINDOW", lambda: None)

        frame = ttk.Frame(window, padding=18)
        frame.pack(fill="both", expand=True)

        ttk.Label(frame, text="Card %s - %s" % (decision.card_number,
                                                decision.fursuit_name or "unknown"),
                  style="Head.TLabel").pack(anchor="w")
        ttk.Label(frame, text=decision.question(), wraplength=430,
                  justify="left").pack(anchor="w", pady=(8, 16))

        chosen = {}

        def choose(choice):
            chosen["choice"] = choice
            window.destroy()

        buttons = ttk.Frame(frame)
        buttons.pack(fill="x")

        for label, choice, hint in (
            ("Reprint it", worker_module.CHOICE_REPRINT, "print this card again"),
            ("It printed", worker_module.CHOICE_PRINTED, "the card is in the stack"),
            ("Skip it", worker_module.CHOICE_SKIP, "leave it unprinted and carry on"),
        ):
            ttk.Button(buttons, text=label, width=14,
                       command=lambda c=choice: choose(c)).pack(side="left", padx=(0, 8))

        window.grab_set()
        self.wait_window(window)

        # wait_window returns when the window goes, and the only way it goes is
        # through one of the buttons. Reprint is the safe reading of anything
        # unexpected: a duplicate card costs a blank, a missing one costs an
        # attendee their badge.
        choice = chosen.get("choice", worker_module.CHOICE_REPRINT)

        decision.answer(choice)
        self._log("Card %s: operator chose %s" % (decision.card_number, choice))

    def _toggle_camera(self) -> None:
        state = "enabled" if self.camera_enabled.get() else "disabled"
        self._log("Camera verification %s" % state)

        binding = self._selected_card_binding()

        if binding is not None:
            # Applied to the live binding straight away so the preview follows
            # the switch; Save is what makes it survive a restart.
            binding.camera.enabled = self.camera_enabled.get()

        self._sync_camera_preview()

    def _refresh_journal(self) -> None:
        summary = vocabulary.ConditionJournal().summary()
        self.journal_label.config(
            text="Journal: %d entries, %d unexplained, %d stops" % (
                summary["entries"], summary["unknown"], summary["stops"]))

    def _open_folder(self) -> None:
        import subprocess
        import sys as _sys

        path = str(config_dir())
        try:
            if _sys.platform.startswith("win"):
                import os
                os.startfile(path)  # type: ignore[attr-defined]
            elif _sys.platform == "darwin":
                subprocess.Popen(["open", path])
            else:
                subprocess.Popen(["xdg-open", path])
        except Exception as error:
            messagebox.showinfo("Folder", "%s\n\n(%s)" % (path, error))

    def _log(self, message: str) -> None:
        stamp = datetime.now().strftime("%H:%M:%S")
        self.log_text.config(state="normal")
        self.log_text.insert("end", "%s  %s\n" % (stamp, message))
        self.log_text.see("end")
        self.log_text.config(state="disabled")

    def _on_close(self) -> None:
        self.stop_flag.set()

        # Stop between cards rather than mid-spool, so nothing is left half sent
        # to the printer. Any confirmation we could not deliver is already in the
        # outbox and will go up when the agent next starts.
        if self.worker is not None and self.worker.is_alive():
            self.worker.stop("Agent closing")

        # Release the webcam before the interpreter goes away: a capture left
        # open keeps the device busy for whatever runs next.
        if self.calibration_page is not None:
            self.calibration_page.close()

        self._stop_telegram()
        self._stop_camera_preview()
        self.destroy()


def main(demo: bool = False) -> None:
    app = AgentApp(demo=demo)
    app._refresh_journal()
    app.mainloop()
