"""When an idle station should go looking for a batch to print.

A module of its own, with no tkinter in it, so the policy can be tested. The UI
module needs a display to import at all, and this is the part with the actual
decision in it.
"""

from __future__ import annotations

# How often an idle unattended station asks the server for something to print.
# Often enough that nobody waits on it, rarely enough that an agent left on
# overnight is not hammering the API.
AUTOSTART_SECONDS = 5.0


def should_autostart(unattended: bool, demo: bool, worker_running: bool,
                     configured: bool, has_printer: bool, printer_ready: bool,
                     since_last: float, interval: float = AUTOSTART_SECONDS) -> bool:
    """Whether an idle station should go looking for a batch to print.

    Unattended used to be able to *continue* a run but never *begin* one: the
    worker is built by the Start button, so on a freshly opened agent ticking
    the box changed a flag that nothing was left to read. A station restarted
    overnight sat idle with the box ticked and batches waiting.
    """
    if demo or not unattended or worker_running:
        return False

    if not configured or not has_printer:
        return False

    # Never start onto a printer that is not ready. It would fail the first
    # card and pause, which is a worse place to be than simply waiting.
    if not printer_ready:
        return False

    return since_last >= interval
