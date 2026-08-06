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


# Batch states an *automatic* pick may take. Deliberately not the same as the
# list an operator may choose from.
#
# A paused batch is excluded on purpose: markFailed() pauses the batch when a
# card fails, and the agent restarting it turned that into a loop -- resume it,
# find nothing printable, drop it, pick it up again. Resuming after a failure
# is a decision for whoever fixed the printer.
AUTO_START_STATUSES = ("ready", "printing")


def pending_jobs(batch: dict):
    """Cards still to print, or None when the batch does not say.

    None is not zero. A batch listing that omits its totals tells us nothing,
    and refusing to print on the strength of a missing field would idle the
    station over a server change. Unknown means "try it".
    """
    totals = batch.get("totals")

    if not isinstance(totals, dict):
        return None

    # The server counts pending jobs for us, which is exactly what the agent
    # can claim. Only fall back to arithmetic if that field is absent.
    if totals.get("remaining") is not None:
        try:
            return max(0, int(totals["remaining"]))
        except (TypeError, ValueError):
            return None

    try:
        jobs = int(totals.get("jobs") or 0)
        done = int(totals.get("printed") or 0) + int(totals.get("failed") or 0)
    except (TypeError, ValueError):
        return None

    return max(0, jobs - done)


def is_auto_startable(batch: dict) -> bool:
    """Whether an unattended station may pick this batch up on its own.

    Needs work left in it as well as the right status. A batch whose only
    remaining job has failed sits at "printing" with nothing pending, stays
    selectable, and yields nothing -- which is exactly the spin this prevents.
    """
    if str(batch.get("status") or "") not in AUTO_START_STATUSES:
        return False

    pending = pending_jobs(batch)

    return pending is None or pending > 0


def first_auto_startable(batches, skip_id=None):
    """The next batch an unattended station should take, or None."""
    for batch in batches or []:
        if skip_id is not None and batch.get("id") == skip_id:
            continue

        if is_auto_startable(batch):
            return batch

    return None
