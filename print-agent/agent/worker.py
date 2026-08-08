"""One printer, one job at a time.

This is the loop the whole rework exists for. The ZXP driver reports success
unconditionally, so every step here is written on the assumption that the thing
we just asked to happen may not have happened:

* the printer is asked whether it is healthy *before* a card is claimed, and an
  unrecognised answer counts as unhealthy;
* the claimed job and its artwork are written to the local store *before* the
  card is sent, so a network drop mid-run cannot lose a card that has already
  been committed to;
* completion comes from the printer's own job table where possible
  (``completion_source=firmware``) and falls back to ``spooler_only`` only when
  Windows accepted the job and nothing contradicted it. There is no timer that
  declares a card printed. The system this replaces had exactly that, and lost
  badges;
* whether the card came out is a separate question from whether the job
  finished, answered by the camera and reported through a separate call.

Every collaborator is injected: the API client, the local store, the printer
monitor, the notifier, the camera verifier and the callable that actually pushes
bytes at the spooler. Nothing in here imports tkinter, opens a socket or touches
hardware, so the entire loop runs under unittest with fakes.

Two rules that are easy to get wrong and expensive to get wrong:

**A card already inside the printer is always finished.** Stop, pause and
tray-full all take effect between cards. A ZXP9 is a retransfer machine and
abandoning a card mid-transfer risks a jam, which costs far more than the extra
minute.

**A failure is never silently resolved.** With a camera, only cards the camera
never saw leave the chute are reprinted. Without one, the worker refuses to
guess: it raises a decision naming the card number so an operator can look at
the physical stack and answer.

Two workers come out of this, because a station prints two different things and
they fail differently:

* :class:`PrintWorker` drives a card printer through the batch an operator
  selected, with every check above.
* :class:`ReceiptWorker` drives the thermal printer. Receipts are never batched,
  there is no camera pointed at them and a thermal unit has no job table worth
  asking, so completion is ``spooler_only`` and that is the honest answer rather
  than a fallback. It claims by printer name and prints continuously, because
  the receipt exists only after a sale and somebody is standing at the counter
  waiting for the paper.

They share the lease discipline, the local store and the offline outbox, and
deliberately nothing else. A jammed card printer must not hold up receipts, and
a failed receipt must not pause a batch of two hundred cards.
"""

from __future__ import annotations

import os
import threading
import time
from typing import Any, Callable, Dict, List, NamedTuple, Optional

from .api import (
    COMPLETION_FIRMWARE,
    COMPLETION_OPERATOR,
    COMPLETION_SPOOLER_ONLY,
    VERIFY_CAMERA,
    VERIFY_OPERATOR,
    ApiError,
    NetworkError,
)
from . import autostart, zebra
from .config import ROLE_CARD, ROLE_RECEIPT
from .store import OUTBOX_FAILED, OUTBOX_PRINTED, OUTBOX_VERIFY

# --- Pipeline -----------------------------------------------------------------
# The names are the contract with ui.console.STEPS and ui.console.MARKS. An
# operator watching over a laggy remote session needs to see which check is
# running, not just its result, so every step is announced before it starts.

STEP_CLAIM = "claim"
STEP_FETCH = "fetch"

# Handing the job to the spooler and the printer working through it are one
# thing as far as anybody watching is concerned: the card is printing. They
# stay separate names in the code because they fail differently -- the spooler
# refusing is not the printer failing -- but they report to the same row.
STEP_PRINT = "print"
STEP_SPOOL = STEP_PRINT
STEP_FIRMWARE = STEP_PRINT

STEP_CAMERA = "camera"
STEP_REPORT = "report"

STEPS = (STEP_CLAIM, STEP_FETCH, STEP_PRINT, STEP_CAMERA, STEP_REPORT)

PENDING = "pending"
ACTIVE = "active"
DONE = "done"
FAILED = "failed"
SKIPPED = "skipped"

# --- Outcomes -----------------------------------------------------------------
# What one trip round the loop produced. The run loop decides whether to keep
# going from this and nothing else.

PRINTED = "printed"          # a card exists and the server has been told
EMPTY = "empty"              # the batch has no more work
BLOCKED = "blocked"          # the printer, or the server, says not now
TRAY_FULL = "tray_full"      # output tray needs emptying before anything else
JOB_FAILED = "failed"        # the card did not print and will not be retried
JOB_SKIPPED = "job_skipped"  # operator chose to leave this card and carry on
WAITING = "waiting"          # an operator has to answer a reprint question
STOPPED = "stopped"          # the worker was told to stop

# Batch states the server will actually hand cards out of. Anything else means
# an empty claim is a reason to stop, not evidence the batch is done.
CLAIMABLE_STATUSES = ("printing",)

# --- Worker state -------------------------------------------------------------

IDLE = "idle"
RUNNING = "running"
PAUSED = "paused"
HALTED = "halted"

# Local job statuses, mirrored into LocalStore so a restarted agent can see how
# far a card got.
JOB_CLAIMED = "claimed"
JOB_PRINTING = "printing"
JOB_REPORTED = "reported"     # printed, but the confirmation is still in the outbox
JOB_DONE = "done"


class SpoolResult(NamedTuple):
    """What the printer sender reported back.

    ``ok`` means Windows accepted the job, nothing more. It is never on its own
    evidence that a card exists.
    """

    ok: bool
    detail: str = ""
    spool_job_id: Optional[str] = None


class Completion(NamedTuple):
    """How, or whether, we know the job finished.

    ``source`` is None for a failure; otherwise it is one of the server's
    completion sources and travels with the printed call.
    """

    source: Optional[str]
    detail: str = ""
    firmware_job_id: Optional[str] = None
    firmware_job_uuid: Optional[str] = None


class Verification(NamedTuple):
    """The camera's verdict, which is independent of completion."""

    checked: bool
    confirmed: bool
    detail: str = ""

    # Positively identified as bare card stock, rather than merely unconfirmed.
    #
    # The distinction decides whether the queue keeps going. "Unconfirmed"
    # means the camera could not speak for this card, which is how the system
    # ran before it existed. "Blank" means a consumable has run out, and every
    # card printed after it would be blank too.
    blank: bool = False


class Outcome(NamedTuple):
    kind: str
    detail: str = ""
    job_id: Optional[int] = None


# What an operator may answer when a card fails. There is no fourth option and
# no default: dismissing the question used to leave the card in limbo, neither
# printed nor reprinted nor recorded, which is the one outcome nobody can act
# on afterwards.
CHOICE_REPRINT = "reprint"    # print it again
CHOICE_PRINTED = "printed"    # the card is in the stack; record it as printed
CHOICE_SKIP = "skip"          # leave it unprinted and move on, deliberately

CHOICES = (CHOICE_REPRINT, CHOICE_PRINTED, CHOICE_SKIP)


class ReprintDecision:
    """A question only a human standing near the printer can answer.

    Raised when a card failed and there is no camera to say whether it came out
    anyway. It carries the **card number** rather than our internal job id,
    because the operator answers it by looking through the stack of cards on the
    output tray.
    """

    __slots__ = ("id", "job_id", "card_number", "fursuit_name", "reason", "printer",
                 "choice", "_answered")

    def __init__(self, job_id, card_number, fursuit_name, reason, printer):
        self.id = job_id
        self.job_id = job_id
        self.card_number = card_number
        self.fursuit_name = fursuit_name
        self.reason = reason
        self.printer = printer
        self.choice: Optional[str] = None

        self._answered = threading.Event()

    def answer(self, choice) -> None:
        """Record the operator's choice.

        Booleans are still accepted because that is what the question used to
        be: True meant reprint, False meant the card is in the stack.
        """
        if isinstance(choice, bool):
            choice = CHOICE_REPRINT if choice else CHOICE_PRINTED

        if choice not in CHOICES:
            raise ValueError("unknown decision: %r" % (choice,))

        self.choice = choice
        self._answered.set()

    @property
    def reprint(self) -> Optional[bool]:
        """Back-compatible view of the answer."""
        if self.choice is None:
            return None

        return self.choice == CHOICE_REPRINT

    def is_answered(self) -> bool:
        return self._answered.is_set()

    def question(self) -> str:
        return (
            "Card %s did not print cleanly (%s). Check the output stack, then "
            "choose: reprint it, mark it as printed if the card is there, or "
            "skip it and leave it unprinted." % (self.card_number, self.reason)
        )

    def __repr__(self) -> str:
        return "ReprintDecision(job_id=%r, card_number=%r)" % (self.job_id, self.card_number)


class _BaseWorker:
    """What every printer on the station needs, whatever it prints.

    The thread and its stop/pause flags, the local cache of a claimed job, the
    outbox that survives the network going away, and the callbacks a UI watches.
    None of it knows about batches, cameras or SNMP: those belong to the card
    printer and live in :class:`PrintWorker`.

    Not instantiated directly. ``run`` is the difference between the two
    workers and each defines its own.
    """

    #: What this worker prints. Mirrors config.PrinterBinding.role.
    role = ROLE_CARD

    #: What one of them is called, for anything an operator reads.
    noun = "Card"

    def __init__(
        self,
        binding: Any,
        api: Any,
        store: Any,
        sender: Callable[[Dict[str, Any], str, Any], Any],
        notifier: Optional[Any] = None,
        cache_dir: Optional[Any] = None,
        on_progress: Optional[Callable[[str, str, str], None]] = None,
        on_log: Optional[Callable[[str], None]] = None,
        on_card: Optional[Callable[[str, str, Dict[str, Any], str], None]] = None,
        on_stock: Optional[Callable[[int], None]] = None,
        count_cards: bool = False,
        low_card_threshold: int = 10,
        heartbeat_seconds: float = 45.0,
        poll_seconds: float = 1.0,
        idle_seconds: float = 3.0,
        clock: Optional[Callable[[], float]] = None,
        sleep: Optional[Callable[[float], None]] = None,
    ):
        self.binding = binding
        self.api = api
        self.store = store
        self.sender = sender
        self.notifier = notifier

        self.printer_name = getattr(binding, "name", "") or ""

        # Set by the card worker; always None for anything unbatched. Kept on the
        # base because the local store records it with every claimed job.
        self.batch_id: Optional[int] = None

        # The batch the server has been told to start. A batch sits in "ready"
        # until somebody starts it, and a ready batch is not claimable, so
        # claiming without this returns nothing and looks exactly like a batch
        # that is finished.
        self._started_batch: Optional[int] = None

        self._cache_dir = cache_dir

        # Callbacks. All optional, all wrapped: a UI that throws must not be able
        # to stop the printer.
        self.on_progress = on_progress
        self.on_log = on_log

        # Fired once per card that reached a terminal outcome, with its badge id
        # and what happened to it. Drives the session readout on the console.
        # Set directly by the UI rather than threaded through every subclass
        # constructor, because only the card console displays it.
        self.on_card = on_card
        self.on_stock = on_stock
        self.count_cards = count_cards
        self.low_card_threshold = low_card_threshold

        self.heartbeat_seconds = heartbeat_seconds
        self.poll_seconds = poll_seconds
        self.idle_seconds = idle_seconds

        self._clock = clock or time.time
        self._sleep = sleep or time.sleep

        self._stop = threading.Event()
        self._paused = threading.Event()

        # True only when paused because there is nothing to print, as opposed
        # to paused because something needs a human. See pause().
        self.waiting_for_work = False

        # What would have to become true for this pause to clear itself. Set by
        # whichever step knew why we stopped; see pause(resume_when=...).
        self._resume_when: Optional[Callable[[], bool]] = None
        self._thread: Optional[threading.Thread] = None

        self.state = IDLE
        self.status_detail = ""
        self.current_job: Optional[Dict[str, Any]] = None
        self.last_outcome: Optional[Outcome] = None

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def start(self) -> "_BaseWorker":
        """Run the loop on its own thread. One thread per printer."""
        if self._thread is not None and self._thread.is_alive():
            return self

        self._stop.clear()
        self._paused.clear()

        self._thread = threading.Thread(
            target=self.run, name="print-worker-%s" % (self.printer_name or "?"), daemon=True
        )
        self._thread.start()

        return self

    def run(self) -> None:
        raise NotImplementedError

    def stop(self, reason: str = "", timeout: Optional[float] = None) -> None:
        """Ask the loop to finish.

        Takes effect between jobs. A card already inside the printer is always
        allowed to land: abandoning a retransfer mid-cycle risks a jam.
        """
        self._stop.set()
        self.status_detail = reason or self.status_detail

        self._unblock()

        if timeout is not None and self._thread is not None:
            self._thread.join(timeout)

    def _unblock(self) -> None:
        """Release anything the loop might be sitting on when stop() arrives."""

    def pause(self, reason: str = "", waiting_for_work: bool = False,
              resume_when: Optional[Callable[[], bool]] = None) -> None:
        """Stop between cards.

        ``waiting_for_work`` distinguishes "there is nothing to print" from
        "something is wrong". Only the first may be cleared automatically:
        switching on unattended mode should pick a parked worker back up, but
        it must never shrug off a jam nobody has looked at.

        ``resume_when`` is how a fault clears itself. Some faults are fixed by
        doing the obvious physical thing -- emptying the tray, clearing a jam,
        closing the cover -- and the machine can see that it happened. Those
        pass a check here and the loop carries on by itself once it passes,
        rather than leaving a station idle because nobody walked back to it and
        pressed Resume. Faults nothing can observe (a blank card, an unanswered
        reprint question) deliberately pass nothing and still wait for a human.
        """
        self._paused.set()
        self.state = PAUSED
        self.status_detail = reason
        self.waiting_for_work = bool(waiting_for_work)
        self._resume_when = resume_when

        if reason:
            self._log(reason)

    def resume(self) -> None:
        self._paused.clear()
        self.state = RUNNING if self.is_alive() else IDLE
        self.status_detail = ""
        self.waiting_for_work = False
        self._resume_when = None

    def _resume_if_cleared(self) -> bool:
        """Take a paused worker off pause once its fault has gone away.

        Never guesses: a check that raises, or that has not been given, leaves
        the worker paused. Being stuck is recoverable by a person; printing
        onto a printer that is still broken is not.
        """
        check = self._resume_when

        if check is None:
            return False

        try:
            cleared = bool(check())
        except Exception:  # noqa: BLE001 - an unreadable printer is not a fixed one
            return False

        if not cleared:
            return False

        self._log("The printer is ready again. Carrying on.")
        self.resume()

        return True

    def is_alive(self) -> bool:
        return self._thread is not None and self._thread.is_alive()

    def is_paused(self) -> bool:
        return self._paused.is_set()

    def is_stopping(self) -> bool:
        return self._stop.is_set()

    # ------------------------------------------------------------------
    # Steps every printer takes
    # ------------------------------------------------------------------

    def _fetch(self, job: Dict[str, Any]) -> Optional[str]:
        """The artwork, from the local cache if we already have it."""
        job_id = int(job["id"])

        self._progress(STEP_FETCH, ACTIVE, "downloading the print file")

        cached = self._cached_file(job_id)
        if cached:
            self._progress(STEP_FETCH, DONE, "already cached")
            return cached

        url = job.get("file_url")
        if not url:
            self._progress(STEP_FETCH, FAILED, "the server sent no print file")
            return None

        destination = self._cache_path(job_id)

        try:
            path = self.api.download(url, destination)
        except (NetworkError, ApiError, OSError) as error:
            self._progress(STEP_FETCH, FAILED, "download failed")
            self._log("Could not fetch the print file for job %d: %s" % (job_id, error))
            return None

        self._call(self.store.set_job_file, job_id, path)
        self._progress(STEP_FETCH, DONE, "print file ready")

        return path

    def _spool(self, job: Dict[str, Any], path: str) -> Any:
        try:
            return self.sender(job, path, self.binding)
        except Exception as error:  # noqa: BLE001 - a broken sender is a failed job, not a crash
            return SpoolResult(False, str(error))

    def _spool_holding_lease(self, job: Dict[str, Any], path: str, job_id: int) -> Any:
        """Hand the file to the spooler while keeping the lease alive.

        The sender blocks until Windows has taken the job, and a ZXP9 that is
        warming up leaves it blocked for minutes. Nothing renewed the lease in
        that window: the card was physically printing, the server heard nothing,
        and the reaper handed the job back to the queue to be printed again.

        Real seconds, not the injected clock. This waits on the spooler, not on
        the print timeouts the clock exists to drive.
        """
        done = threading.Event()
        interval = self.heartbeat_seconds if self.heartbeat_seconds > 0 else 1.0

        def keep_lease() -> None:
            while not done.wait(interval):
                self._call(self.api.heartbeat, job_id)

        keeper = threading.Thread(
            target=keep_lease, name="lease-%s" % job_id, daemon=True
        )
        keeper.start()

        try:
            return self._spool(job, path)
        finally:
            done.set()

    def _report(
        self,
        job: Dict[str, Any],
        completion: Completion,
        verification: Verification,
        verification_source: str = VERIFY_CAMERA,
    ) -> None:
        """Tell the server the job printed, and separately that it was verified.

        A confirmation that cannot be delivered goes to the outbox rather than
        being dropped. A lost "printed" costs a duplicate card on the next pass,
        which is the failure this whole rework is about.
        """
        job_id = int(job["id"])

        self._progress(STEP_REPORT, ACTIVE, "telling the server")

        printed_payload = {
            "job_id": job_id,
            "completion_source": completion.source,
            "firmware_job_id": completion.firmware_job_id,
            "firmware_job_uuid": completion.firmware_job_uuid,
        }

        delivered = self._deliver(
            OUTBOX_PRINTED,
            printed_payload,
            lambda: self.api.mark_printed(
                job_id,
                completion.source,
                completion.firmware_job_id,
                completion.firmware_job_uuid,
            ),
        )

        if verification.confirmed:
            self._deliver(
                OUTBOX_VERIFY,
                {"job_id": job_id, "source": verification_source},
                lambda: self.api.verify(job_id, verification_source),
            )

        self._record_history(
            job,
            outcome="printed" if verification.confirmed else "unverified",
            detail="%s%s" % (
                completion.source,
                ", %s" % verification.detail if verification.detail else "",
            ),
        )

        # A blank left the hopper whether or not the camera liked the result.
        self._take_card()

        if delivered:
            self.store_status(job_id, JOB_DONE)
            self._call(self.store.forget_job, job_id)
            self._progress(STEP_REPORT, DONE, "recorded as %s" % completion.source)
        else:
            # Kept locally until the outbox drains: the row is what stops the
            # job being printed a second time after a restart.
            self.store_status(job_id, JOB_REPORTED)
            self._progress(STEP_REPORT, SKIPPED, "server unreachable, queued locally")

    def _take_card(self) -> None:
        """Count one blank off the hopper and warn before it runs out.

        The printer only reports empty once it already is, which strands a run
        mid-batch while somebody goes to find the box. Counting down from a
        figure the operator entered gives enough notice to refill in time.

        Never allowed to stop a print: an uncountable card is a worse readout,
        not a reason to stop the machine.
        """
        if not self.count_cards:
            return

        remaining = self._call(self.store.take_card)

        if remaining is None:
            return

        if self.on_stock is not None:
            _safely(self.on_stock, remaining)

        if remaining == 0:
            self._alert(
                "cards-out",
                "Card printer is out of blanks",
                "%s has printed its last counted card. Refill before the next run."
                % (self.printer_name or "The card printer"),
            )
        elif remaining <= self.low_card_threshold:
            # Keyed per level, so a warning arrives for each of the last few
            # cards rather than one alert at ten and silence down to zero.
            self._alert(
                "cards-low-%d" % remaining,
                "Card printer is nearly empty",
                "%d blank%s left in %s. Refill soon."
                % (remaining, "" if remaining == 1 else "s",
                   self.printer_name or "the card printer"),
            )

    def _record_history(self, job: Dict[str, Any], outcome: str, detail: str = "") -> None:
        """Write a line of local history.

        Never allowed to matter: a store that will not write is a nuisance when
        somebody wants to know what happened later, not a reason to stop
        printing now.
        """
        self._call(lambda: self.store.record(
            "job", outcome, detail, job=job, printer_name=self.printer_name))

        if self.on_card is not None:
            _safely(self.on_card, self._card_number(job), outcome, job, detail)

    def _fail(self, job: Dict[str, Any], reason: str) -> None:
        """Report a job we are not going to reprint.

        For a card this also pauses the batch server side. A receipt has no
        batch, so it pauses nothing and the next customer is unaffected.
        """
        job_id = int(job["id"])
        self._record_history(job, "failed", reason)

        self._deliver(
            OUTBOX_FAILED,
            {"job_id": job_id, "reason": reason},
            lambda: self.api.mark_failed(job_id, reason),
        )

        self.store_status(job_id, FAILED)
        self._progress(STEP_REPORT, FAILED, reason)
        self._log("%s %s failed: %s" % (self.noun, self._card_number(job), reason))

    # ------------------------------------------------------------------
    # Outbox
    # ------------------------------------------------------------------

    def flush_outbox(self, limit: int = 50) -> int:
        """Deliver what the server has not heard yet. Returns how many landed.

        Stops at the first network failure: if the server is still unreachable
        there is nothing to gain from working through the rest of the queue, and
        the print loop is waiting on this.
        """
        delivered = 0

        try:
            pending = self.store.pending_outbox(limit)
        except Exception:  # noqa: BLE001 - the store must never stop the printer
            return 0

        for entry in pending:
            try:
                if not self._send_outbox(entry):
                    continue
            except NetworkError as error:
                self._call(self.store.bump_outbox_attempt, entry.id, str(error))
                break
            except ApiError as error:
                # The server understood and refused. Repeating it verbatim will
                # be refused again, so record the reason and move on.
                self._call(self.store.bump_outbox_attempt, entry.id, error.message)
                self._call(self.store.mark_outbox_sent, entry.id)
                continue

            self._call(self.store.mark_outbox_sent, entry.id)

            job_id = (entry.payload or {}).get("job_id")
            if job_id is not None:
                self._call(self.store.forget_job, job_id)

            delivered += 1

        return delivered

    def _send_outbox(self, entry: Any) -> bool:
        payload = entry.payload or {}
        job_id = payload.get("job_id")

        if job_id is None:
            return True

        if entry.kind == OUTBOX_PRINTED:
            # Same rule as the live call: a reply that says the job was not
            # recorded is not a delivery, and marking the row sent would drop
            # the only evidence that this card exists.
            return _recorded(self.api.mark_printed(
                job_id,
                payload.get("completion_source"),
                payload.get("firmware_job_id"),
                payload.get("firmware_job_uuid"),
            ))

        if entry.kind == OUTBOX_VERIFY:
            self.api.verify(job_id, payload.get("source", VERIFY_CAMERA))
            return True

        if entry.kind == OUTBOX_FAILED:
            self.api.mark_failed(job_id, payload.get("reason", "Print failed"))
            return True

        return False

    def _deliver(self, kind: str, payload: Dict[str, Any], call: Callable[[], Any]) -> bool:
        """Make one confirmation call, falling back to the outbox."""
        try:
            if _recorded(call()):
                return True

            # A 200 that says "not recorded". The call went through and the
            # server declined to move the job, which used to count as delivered:
            # the agent forgot the card, the job stayed queued server side, and
            # the next claim printed it again. Keep it and say so.
            self._call(self.store.enqueue_outbox, kind, payload)
            self._log("Server did not record %s for job %s; kept locally."
                      % (kind, payload.get("job_id")))
            self._alert(
                "report:%s" % payload.get("job_id"),
                "A print result was not recorded",
                "%s for job %s was refused by the server. The card exists; the "
                "job may still be queued." % (kind, payload.get("job_id")),
            )

            return False
        except NetworkError as error:
            self._call(self.store.enqueue_outbox, kind, payload)
            self._log("Server unreachable; %s for job %s queued locally (%s)."
                      % (kind, payload.get("job_id"), error))
            return False
        except ApiError as error:
            if error.status >= 500 or error.status == 429:
                # The server is broken rather than disagreeing with us; keep it.
                self._call(self.store.enqueue_outbox, kind, payload)
                self._log("Server error on %s for job %s; queued locally (%s)."
                          % (kind, payload.get("job_id"), error.message))
                return False

            self._log("Server refused %s for job %s: %s" % (kind, payload.get("job_id"), error.message))

            if error.status == 409:
                # The job moved on without us: another machine holds it, or it
                # is already recorded some other way. A card exists here that
                # the server is not crediting to this job, and that is the shape
                # a duplicate print comes in. Keep the record and say so loudly.
                self._call(self.store.enqueue_outbox, kind, payload)
                self._alert(
                    "report:%s" % payload.get("job_id"),
                    "A printed card is not recorded against its job",
                    "%s for job %s was refused: %s. Check the card before it is "
                    "printed again." % (kind, payload.get("job_id"), error.message),
                )

                return False

            # The card printed; only the bookkeeping failed, and the outbox
            # keeps retrying. Nobody needs waking for it.
            self._alert(
                "report:%s" % payload.get("job_id"),
                "Server refused a print result",
                "%s for job %s: %s" % (kind, payload.get("job_id"), error.message),
                stops_printing=False,
            )
            return True

    # ------------------------------------------------------------------
    # Odds and ends
    # ------------------------------------------------------------------

    def _remember(self, job: Dict[str, Any], status: str) -> None:
        self._call(self.store.save_job, job, self.printer_name, self.batch_id, status)

    def store_status(self, job_id: int, status: str) -> None:
        self._call(self.store.set_job_status, job_id, status)

    def _cached_file(self, job_id: int) -> Optional[str]:
        try:
            record = self.store.job(job_id)
        except Exception:  # noqa: BLE001
            return None

        path = (record or {}).get("file_path")

        if path and os.path.exists(path):
            return path

        return None

    def _cache_path(self, job_id: int) -> str:
        base = self._cache_dir

        if base is None:
            from .config import cache_dir

            base = cache_dir()

        return os.path.join(str(base), "job-%d.pdf" % int(job_id))

    def _outbox_depth(self) -> int:
        try:
            return int(self.store.outbox_depth())
        except Exception:  # noqa: BLE001
            return 0

    @staticmethod
    def _card_number(job: Dict[str, Any]) -> str:
        """What an operator would call this job, for anything they have to read.

        The badge's custom_id, because that is what is printed on the card they
        are being asked to find in the stack. Falls back to the job id, which is
        at least unique, when the server sent no badge details. A receipt always
        takes the fallback: there is no badge behind it.
        """
        expected = (job or {}).get("expected") or {}
        custom_id = expected.get("custom_id")

        if custom_id:
            return str(custom_id)

        return "#%s" % (job or {}).get("id", "?")

    @staticmethod
    def _fursuit_name(job: Dict[str, Any]) -> str:
        expected = (job or {}).get("expected") or {}

        return str(expected.get("fursuit_name") or "")

    def _reset_pipeline(self) -> None:
        for step in STEPS:
            self._progress(step, PENDING, "")

    def _progress(self, step: str, state: str, detail: str = "") -> None:
        if self.on_progress is None:
            return

        _safely(self.on_progress, step, state, detail)

    def _log(self, message: str) -> None:
        self.status_detail = message

        if self.on_log is not None:
            _safely(self.on_log, message)

    def _alert(self, key: str, title: str, message: str,
               stops_printing: bool = True) -> None:
        """Raise an alert.

        `stops_printing` marks the ones worth waking somebody for: the run has
        stopped, or it is about to. Those reach Pushover. The rest go to the
        chat only -- a phone that buzzes for a single blank card in a run of
        four hundred is a phone that gets silenced before the jam arrives.
        """
        if self.notifier is None:
            return

        _safely(self.notifier.alert, key, title, message,
                stops_printing=stops_printing)

    def _call(self, function: Callable, *args) -> Any:
        """Best-effort call for anything that must not stop the printer.

        Used for the lease heartbeat and the local store. A card in the machine
        is worth more than a tidy database row, and a heartbeat that fails is
        recovered by the lease reaper.

        None is accepted so callers can pass ``getattr(client, "thing", None)``
        for anything optional, rather than each one guarding separately.
        """
        if function is None:
            return None

        try:
            return function(*args)
        except Exception as error:  # noqa: BLE001
            self._log("%s failed: %s" % (getattr(function, "__name__", "call"), error))
            return None


class PrintWorker(_BaseWorker):
    """Drives one card printer through one batch, one card at a time.

    Everything the loop touches is passed in. ``sender`` is the only mandatory
    collaborator with no sensible default: it is the callable that hands a file
    to the Windows spooler, signature ``sender(job, file_path, binding)``,
    returning a :class:`SpoolResult` or a bool.
    """

    role = ROLE_CARD

    def __init__(
        self,
        binding: Any,
        api: Any,
        store: Any,
        monitor: Any,
        sender: Callable[[Dict[str, Any], str, Any], Any],
        notifier: Optional[Any] = None,
        verifier: Optional[Any] = None,
        batch_id: Optional[int] = None,
        unattended: bool = False,
        cache_dir: Optional[Any] = None,
        on_progress: Optional[Callable[[str, str, str], None]] = None,
        on_decision: Optional[Callable[[ReprintDecision], None]] = None,
        on_log: Optional[Callable[[str], None]] = None,
        on_batch_change: Optional[Callable[[Optional[int]], None]] = None,
        on_stock: Optional[Callable[[int], None]] = None,
        count_cards: bool = False,
        low_card_threshold: int = 10,
        heartbeat_seconds: float = 45.0,
        firmware_timeout: float = 180.0,
        max_print_seconds: float = 1200.0,
        poll_seconds: float = 1.0,
        idle_seconds: float = 3.0,
        stop_confirmations: int = 2,
        max_attempts: int = 2,
        decision_timeout: Optional[float] = None,
        clock: Optional[Callable[[], float]] = None,
        sleep: Optional[Callable[[float], None]] = None,
    ):
        super().__init__(
            binding=binding,
            api=api,
            store=store,
            sender=sender,
            notifier=notifier,
            cache_dir=cache_dir,
            on_progress=on_progress,
            on_log=on_log,
            on_stock=on_stock,
            count_cards=count_cards,
            low_card_threshold=low_card_threshold,
            heartbeat_seconds=heartbeat_seconds,
            poll_seconds=poll_seconds,
            idle_seconds=idle_seconds,
            clock=clock,
            sleep=sleep,
        )

        self.monitor = monitor
        self.verifier = verifier

        self.batch_id = batch_id
        self.unattended = bool(unattended)

        self.on_decision = on_decision
        self.on_batch_change = on_batch_change

        # How long the printer may say nothing at all about a card before we
        # settle for the spooler's word. Silence, not print time: the deadline
        # is pushed forward while the printer is visibly working.
        self.firmware_timeout = firmware_timeout

        # The ceiling on one card, however busy the printer claims to be. Twenty
        # minutes covers a cold start with a heating cycle several times over,
        # and still ends a run rather than leaving it hanging on a machine stuck
        # in "printing".
        self.max_print_seconds = max_print_seconds

        # How many consecutive polls must agree that the printer has stopped
        # before we fail the card in flight. One flaky SNMP timeout is not a jam,
        # and failing on it would pause a batch for nothing.
        self.stop_confirmations = max(1, int(stop_confirmations))

        # Total spool attempts for one card, including the first.
        self.max_attempts = max(1, int(max_attempts))

        # None means wait for the operator indefinitely, which is the right
        # default: the alternative is guessing, and guessing is what lost badges.
        self.decision_timeout = decision_timeout

        self.pending_decision: Optional[ReprintDecision] = None

        # Latched by the tray-full checkpoint. Cleared by resume(), or by the
        # camera seeing an empty tray again -- never by anything that has not
        # actually looked, because that means printing into a full tray.
        self.tray_full = False

        # What the step that stopped us would accept as "fixed", handed to
        # pause() by the run loop. None means only a person can clear it.
        self._recheck: Optional[Callable[[], bool]] = None

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def _unblock(self) -> None:
        decision = self.pending_decision

        if decision is not None and not decision.is_answered():
            # Unblock the wait; an unanswered question means no reprint decision
            # was made, and the card stays failed rather than guessed at.
            decision._answered.set()

    def pause(self, reason: str = "", waiting_for_work: bool = False,
              resume_when: Optional[Callable[[], bool]] = None) -> None:
        """Stop between cards, and tell the server the batch is paused.

        Reporting matters as much as stopping. The server used to be told
        nothing, so a station sat paused on a jam while the batch still read
        `printing` everywhere else -- and the lease reaper, seeing a batch that
        was supposedly running, handed the card out again.

        Best-effort: a network problem must never stop the worker pausing.
        """
        super().pause(reason, waiting_for_work=waiting_for_work, resume_when=resume_when)

        if self.batch_id is not None:
            self._call(getattr(self.api, "pause_batch", None), self.batch_id,
                       reason or "Paused at the print station")

    def resume(self) -> None:
        """Carry on. Clears the tray-full latch: the operator emptied the tray."""
        self.tray_full = False

        super().resume()

        if self.batch_id is not None:
            # resume_batch already puts the batch back to printing, so there
            # is nothing left to re-assert. Clearing the started marker here
            # made the next claim start a batch the server had just resumed,
            # which came back 409 and stopped the queue.
            self._call(getattr(self.api, "resume_batch", None), self.batch_id)

    def set_unattended(self, unattended: bool) -> None:
        """With this on, finishing a batch pulls the next one and the station can
        be left alone. With it off, an operator picks every batch by hand."""
        self.unattended = bool(unattended)

    def select_batch(self, batch_id: Optional[int]) -> None:
        self.batch_id = int(batch_id) if batch_id is not None else None

        # A different batch has to be started in its own right.
        if self.batch_id != self._started_batch:
            self._started_batch = None

        self._notify_batch(self.batch_id)

    def snapshot(self) -> Dict[str, Any]:
        """Everything the UI needs to render this worker, in one read."""
        job = self.current_job or {}

        return {
            "printer": self.printer_name,
            "role": self.role,
            "state": self.state,
            "detail": self.status_detail,
            "batch_id": self.batch_id,
            "unattended": self.unattended,
            "tray_full": self.tray_full,
            "card_number": self._card_number(job) if job else None,
            "job_id": job.get("id") if job else None,
            "outcome": self.last_outcome.kind if self.last_outcome else None,
            "pending_decision": self.pending_decision,
            "outbox": self._outbox_depth(),
        }

    # ------------------------------------------------------------------
    # The loop
    # ------------------------------------------------------------------

    def run(self) -> None:
        """Print cards until told to stop or until something needs a human."""
        self.state = RUNNING

        while not self._stop.is_set():
            if self._paused.is_set():
                # A fault that can be seen to be fixed clears itself: the tray
                # gets emptied, the jam gets cleared, and the run carries on
                # without somebody walking back to press Resume.
                if not self._resume_if_cleared():
                    self._sleep(self.idle_seconds)

                continue

            outcome = self.print_next()
            self.last_outcome = outcome

            if outcome.kind == PRINTED:
                self.flush_outbox()
                continue

            if outcome.kind == STOPPED:
                break

            if outcome.kind == EMPTY:
                if self.advance_batch() is None:
                    if self.unattended:
                        # Nothing to print *yet*. Wait and look again rather
                        # than parking: unattended means nobody is watching, so
                        # pausing here left the station idle through every
                        # batch built after it ran dry until somebody noticed
                        # and pressed Start.
                        self._sleep(self.idle_seconds)
                    else:
                        self.pause("Batch finished. Choose the next one.",
                                   waiting_for_work=True)
                continue

            if outcome.kind == JOB_SKIPPED:
                # The operator has already looked at this card and decided.
                # Pausing to ask a second time would be pointless.
                continue

            # Everything else stopped the queue and needs somebody to look at
            # it. The step that stopped says whether "looked at" is something
            # the machine can notice for itself; see pause(resume_when=...).
            self.pause(outcome.detail, resume_when=self._recheck)

        self.state = HALTED if self._stop.is_set() else self.state

    def print_next(self) -> Outcome:
        """Claim, print, prove and report exactly one card."""
        if self._stop.is_set():
            return Outcome(STOPPED, "Worker is stopping")

        self._reset_pipeline()
        self.current_job = None

        # Nothing has stopped us yet, so no pause of ours is recoverable yet.
        self._recheck = None

        if self.tray_full or self._tray_is_full():
            self.tray_full = True
            detail = "Output tray is full. Empty it before printing more cards."
            self._progress(STEP_CLAIM, FAILED, "tray full")
            self._alert("tray:%s" % self.printer_name, "Output tray full", detail)
            self._recheck = self._tray_cleared

            return Outcome(TRAY_FULL, detail)

        blocked = self._gate()

        if blocked is not None and self._is_transient():
            # standby -> initializing -> printing_heating is the printer waking
            # up, not a fault. Pausing here would stop the batch and ask for an
            # operator every time the machine warms itself up.
            self._progress(STEP_CLAIM, ACTIVE, "printer is warming up")

            if not self._wait_until_healthy():
                return Outcome(STOPPED, "Stopped while the printer was warming up")

            blocked = self._gate()
        if blocked is not None:
            self._progress(STEP_CLAIM, FAILED, self._condition())
            self._alert(
                "printer:%s:%s" % (self.printer_name, self._condition()),
                "%s stopped" % (self.printer_name or "Printer"),
                blocked,
            )

            # A jam, an open cover, an empty hopper: the printer itself tells us
            # when it has been dealt with, so the run picks itself back up.
            self._recheck = self._printer_ready

            return Outcome(BLOCKED, blocked)

        if self.batch_id is None:
            return Outcome(EMPTY, "No batch selected")

        if self.batch_id != self._started_batch:
            # Attended runs used to skip this entirely: the operator pressed
            # Start, the worker span up, and nobody ever told the server. The
            # batch stayed ready, every claim came back empty, and the agent
            # reported the batch finished without printing a card.
            if not self._start_batch(self.batch_id):
                detail = "Could not start batch %s on the server" % self.batch_id
                self._progress(STEP_CLAIM, FAILED, "could not start the batch")
                return Outcome(BLOCKED, detail)

        self._progress(STEP_CLAIM, ACTIVE, "asking the server for the next card")

        try:
            job, batch_status = self.api.claim(self.batch_id, self.printer_name)
        except NetworkError as error:
            detail = "Server unreachable, cannot claim a card: %s" % error
            self._progress(STEP_CLAIM, FAILED, "server unreachable")
            self._alert("claim:%s" % self.printer_name, "Cannot reach the server", detail)
            return Outcome(BLOCKED, detail)
        except ApiError as error:
            detail = "Server refused the claim: %s" % error.message
            self._progress(STEP_CLAIM, FAILED, error.message)
            self._alert("claim:%s" % self.printer_name, "Cannot claim a card", detail)
            return Outcome(BLOCKED, detail)

        if not job:
            # A paused or not-yet-started batch still holds all its cards.
            # Treating that as finished would move the operator on and quietly
            # skip the whole run.
            if batch_status and batch_status not in CLAIMABLE_STATUSES:
                detail = "Batch %s is %s, not printing" % (self.batch_id, batch_status)
                self._progress(STEP_CLAIM, FAILED, "batch is %s" % batch_status)

                # Re-start it next time round rather than sitting here stuck.
                self._started_batch = None

                return Outcome(BLOCKED, detail)

            self._progress(STEP_CLAIM, SKIPPED, "no cards left in this batch")
            return Outcome(EMPTY, "Batch has no more cards")

        return self._print_job(job)

    def advance_batch(self) -> Optional[int]:
        """The current batch is drained. Work out what happens next.

        Unattended: take the next batch this printer may have and start it.
        Attended: hand back None so the loop parks and waits for an operator,
        because picking the wrong batch means printing the wrong hundred cards.
        """
        finished = self.batch_id
        self.current_job = None

        if not self.unattended:
            self.batch_id = None
            self._notify_batch(None)
            self._log("Batch %s finished. Waiting for an operator to choose the next one." % finished)
            return None

        for batch in self._selectable_batches():
            batch_id = batch.get("id")

            if batch_id is None or batch_id == finished:
                continue

            # Same rule as the cold start: never resurrect a batch that was
            # paused by a failure, and never take one with nothing left in it.
            if not autostart.is_auto_startable(batch):
                continue

            if not self._start_batch(batch_id):
                continue

            self.batch_id = int(batch_id)
            self._notify_batch(self.batch_id)
            self._log("Unattended: moved on to batch %s." % batch.get("name", batch_id))

            return self.batch_id

        was_loaded = self.batch_id is not None

        self.batch_id = None

        # Only on the way from having work to having none. This is now called
        # every few seconds while waiting for the next batch, and saying so
        # each time would bury everything else in the log.
        if was_loaded:
            self._notify_batch(None)
            self._log("Unattended: nothing else to print. Watching for a new batch.")

        return None

    # ------------------------------------------------------------------
    # One card
    # ------------------------------------------------------------------

    def _print_job(self, job: Dict[str, Any], attempt: int = 1) -> Outcome:
        job_id = int(job["id"])
        self.current_job = job

        if attempt > 1:
            self._reset_pipeline()

        if attempt == 1:
            already = self._already_handled(job)

            if already is not None:
                return already

        self._progress(STEP_CLAIM, DONE, "card %s" % self._card_number(job))

        # Cached before anything is printed. From here on the card is committed
        # to: if the network dies we still know what we hold and what to send.
        self._remember(job, JOB_CLAIMED)

        path = self._fetch(job)
        if path is None:
            reason = "Artwork for card %s could not be fetched" % self._card_number(job)
            self._fail(job, reason)
            return Outcome(JOB_FAILED, reason, job_id)

        self._call(self.api.mark_printing, job_id)
        self.store_status(job_id, JOB_PRINTING)

        self._progress(STEP_SPOOL, ACTIVE, "sending to %s" % (self.printer_name or "the printer"))

        # Snapshot the printer's job table first: our card is the row that was
        # not there before.
        baseline = self._firmware_keys()

        # And snapshot the output bin, for the same reason. The "did anything
        # land?" check compares against how the bin looked before this card;
        # a picture taken afterwards would already contain it.
        self._arm_camera()

        spooled = _as_spool_result(self._spool_holding_lease(job, path, job_id))

        if not spooled.ok:
            self._progress(STEP_SPOOL, FAILED, spooled.detail or "the spooler refused the job")
            return self._handle_failure(
                job, attempt, "The printer did not accept the job: %s" % (spooled.detail or "no detail")
            )

        self._progress(STEP_SPOOL, DONE, spooled.detail or "accepted by the spooler")

        completion = self._await_completion(job_id, baseline)

        if completion.source is None:
            self._progress(STEP_FIRMWARE, FAILED, completion.detail)
            return self._handle_failure(job, attempt, completion.detail)

        if completion.source == COMPLETION_FIRMWARE:
            self._progress(STEP_FIRMWARE, DONE, completion.detail)
        else:
            # Not a failure, but not proof either. Spooling and printing share
            # one row now, and marking that row skipped would say the card did
            # not print when it did -- so the row stays done and the weaker
            # evidence is spelled out in the detail beside it.
            self._progress(STEP_FIRMWARE, DONE,
                           "%s (no firmware confirmation)" % completion.detail)

        verification = self._verify(job)

        if verification.blank and self._tray_is_full():
            # A full tray moves the card. The ink points are calibrated for a
            # card lying where cards normally land, and once the stack has risen
            # they read the bin, the rim or a card standing half out of the
            # chute -- bright and colourless, which is exactly what bare card
            # stock looks like. Failing the job on that reading loses a card
            # that is sitting in the tray: the badge goes back in the queue and
            # a second one gets printed for it.
            #
            # So the card is reported, honestly unverified, and the run stops
            # for the tray rather than for a consumable that has not run out.
            self.tray_full = True
            detail = ("Card %s printed, but the tray is full and the blank check "
                      "cannot be trusted with the stack this high."
                      % self._card_number(job))
            self._log(detail)
            self._alert("tray:%s" % self.printer_name, "Output tray full", detail)
            self._report(job, completion,
                         Verification(True, False, "tray full; blank check not trusted"))
            self._recheck = self._tray_cleared

            return Outcome(TRAY_FULL, detail, job_id)

        if verification.blank:
            # A card came out, but with nothing on it: the ribbon or the
            # transfer film has run out. Deliberately not reported as printed.
            # Every card after this one would be blank too, so the batch stops
            # here and the job stays unprinted so it comes back after the
            # consumable is changed.
            return self._handle_blank(job)

        self._report(job, completion, verification)

        return Outcome(PRINTED, completion.detail, job_id)

    def _already_handled(self, job: Dict[str, Any]) -> Optional[Outcome]:
        """Refuse to print a card this agent has already put through the printer.

        The server can hand the same job out twice. A lease that lapsed during a
        network outage is returned to the queue by the reaper, and the next claim
        is allowed to pick it up -- so without this, a printer that went quiet for
        a few minutes comes back and prints the same badge again, and keeps doing
        it for as long as the outage lasts.

        The local store is the record that survives all of it: a job row is written
        before the file is sent and is not deleted until the server has confirmed
        the result. What that row says decides what happens here.

        Returns None when there is nothing on file and the card should be printed.
        """
        try:
            record = self.store.job(int(job["id"]))
        except Exception:  # noqa: BLE001 - an unreadable store is no information
            return None

        status = (record or {}).get("status")

        if status in (None, JOB_CLAIMED):
            # Claimed and no further: nothing was ever sent to the printer.
            return None

        job_id = int(job["id"])
        card = self._card_number(job)

        if status in (JOB_REPORTED, JOB_DONE):
            # The card exists and we already know how it went; only the
            # bookkeeping is outstanding. Deliver that instead of a second card.
            detail = "Card %s was already printed here; sending the result again." % card
            self._log(detail)
            self._progress(STEP_CLAIM, SKIPPED, "already printed")
            self.flush_outbox()

            return Outcome(PRINTED, detail, job_id)

        # JOB_PRINTING: the file went to the spooler and we never recorded what
        # came of it. The card may well be in the stack. Nobody here can tell,
        # and guessing either way is how a badge goes missing or gets printed
        # twice, so it goes to the person who can look at the stack.
        reason = ("Card %s was already sent to the printer before the connection "
                  "dropped. Check the stack before it is printed again." % card)
        self._log(reason)

        answer = self._ask_operator(job, reason)

        if answer is None:
            self.status_detail = reason
            return Outcome(WAITING, reason, job_id)

        if answer == CHOICE_PRINTED:
            self._report(
                job,
                Completion(COMPLETION_OPERATOR, "operator found the card in the stack"),
                Verification(True, True, "operator"),
                verification_source=VERIFY_OPERATOR,
            )

            return Outcome(PRINTED, "operator confirmed the card is in the stack", job_id)

        if answer == CHOICE_SKIP:
            detail = "Operator skipped card %s (%s)" % (card, reason)
            self._log(detail)
            self._fail(job, detail)

            return Outcome(JOB_SKIPPED, detail, job_id)

        # CHOICE_REPRINT: the operator looked and there is no card. Print it.
        return None

    def _handle_blank(self, job: Dict[str, Any]) -> Outcome:
        """A card came out of the printer unprinted.

        This is the failure the camera exists to catch, and the one that used
        to lose badges: the driver reports success, a blank card lands in the
        stack, and nobody notices until an attendee is handed it.
        """
        job_id = int(job["id"])
        card = self._card_number(job)
        reason = ("Card %s came out blank. The ribbon or transfer film has "
                  "probably run out." % card)

        # One bad card in a run that carries on. Worth a line in the chat, not
        # worth a phone call.
        self._alert("blank:%s:%s" % (self.printer_name, card),
                    "Card %s came out blank" % card, reason,
                    stops_printing=False)
        self._log(reason)
        self._record_history(job, outcome="blank", detail=reason)

        # Pauses the batch server side, and leaves this job unprinted so it is
        # reprinted once somebody has changed the consumable.
        self._fail(job, reason)

        return Outcome(JOB_FAILED, reason, job_id)

    def _handle_failure(self, job: Dict[str, Any], attempt: int, reason: str) -> Outcome:
        """A card did not print cleanly. Decide whether to print another one.

        The decision is never taken on a guess. With a camera we know whether a
        card left the chute and can reprint only the ones that did not. Without
        one, an operator looks at the stack and tells us.
        """
        job_id = int(job["id"])
        card = self._card_number(job)

        self._alert(
            "job:%s:%s" % (self.printer_name, card),
            "Card %s did not print" % card,
            reason,
        )

        if self._camera_on():
            verification = self._verify(job)

            if verification.confirmed:
                # The camera watched this card leave the chute. It exists, so
                # there is nothing to decide: printing a second one would put a
                # duplicate in the stack. This is the one path that resolves
                # itself, and it resolves a success rather than a failure.
                self._log("Card %s came out despite the fault; not reprinting." % card)
                self._report(
                    job,
                    Completion(COMPLETION_SPOOLER_ONLY, "camera saw the card despite %s" % reason),
                    verification,
                )
                return Outcome(PRINTED, "camera confirmed the card despite the fault", job_id)

        # Every real failure is put to a human, camera or not. The camera says
        # whether a card came out; it cannot say whether this one should be
        # printed again, marked as already in the stack, or left alone. Deciding
        # that silently is how a card goes missing with nobody the wiser.
        answer = self._ask_operator(job, reason)

        if answer is None:
            detail = "Waiting for an operator to say what to do with card %s" % card
            self.status_detail = detail
            return Outcome(WAITING, detail, job_id)

        if answer == CHOICE_PRINTED:
            # The operator has the card in their hand. That is the strongest
            # evidence there is, and it is exactly what `operator` means.
            self._report(
                job,
                Completion(COMPLETION_OPERATOR, "operator found the card in the stack"),
                Verification(True, True, "operator"),
                verification_source=VERIFY_OPERATOR,
            )
            return Outcome(PRINTED, "operator confirmed the card is in the stack", job_id)

        if answer == CHOICE_SKIP:
            # Deliberately unprinted. Recorded as failed so the card is visible
            # as outstanding rather than quietly forgotten, but the queue
            # carries on: the operator has already looked at it and decided, so
            # stopping to ask again would be pointless.
            detail = "Operator skipped card %s (%s)" % (card, reason)
            self._log(detail)
            self._fail(job, detail)

            return Outcome(JOB_SKIPPED, detail, job_id)

        reprint = True

        if not reprint or attempt >= self.max_attempts:
            self._fail(job, reason)
            return Outcome(JOB_FAILED, reason, job_id)

        if not self._wait_until_healthy():
            detail = "Card %s needs reprinting once the printer is fixed" % card
            self.status_detail = detail
            return Outcome(BLOCKED, detail, job_id)

        self._log("Reprinting card %s (attempt %d)." % (card, attempt + 1))

        return self._print_job(job, attempt + 1)

    # ------------------------------------------------------------------
    # Steps
    # ------------------------------------------------------------------

    def _await_completion(self, job_id: int, baseline: set) -> Completion:
        """Wait for the printer's own job table to account for this card.

        The rolling table at 10642.8.5 is the only evidence that does not pass
        through the driver, so it is preferred over everything else. When it
        never mentions the card we fall back to ``spooler_only``, which says
        plainly that Windows accepted the job and nothing contradicted it. That
        is weaker than firmware confirmation and is recorded as such.

        The lease is renewed throughout: a retransfer card takes well over a
        minute and an unrenewed lease is reaped out from under us mid-print.

        ``firmware_timeout`` bounds *silence*, not the card. A ZXP9 coming up to
        temperature takes several minutes before it prints anything, and a fixed
        deadline gave up on a card that was visibly still being worked on: the
        job was reported ``spooler_only`` while it was in the machine, the camera
        was then asked about a bin with nothing in it yet, and the run moved on
        to the next card. So the deadline is pushed forward for as long as the
        printer is demonstrably busy -- a job row in flight, or a printing or
        warming-up state -- and ``max_print_seconds`` is the ceiling that stops a
        printer stuck in "printing" from holding the queue forever.
        """
        self._progress(STEP_FIRMWARE, ACTIVE, "waiting for the printer to confirm")

        started = self._clock()
        deadline = started + self.firmware_timeout
        ceiling = started + max(self.firmware_timeout, self.max_print_seconds)
        last_beat = self._clock()
        matched = None
        stop_streak = 0
        said_slow = False

        while True:
            reading = self._read_printer()
            rows = list(getattr(reading, "jobs", None) or [])

            if matched is None:
                fresh = [row for row in rows if _row_key(row) not in baseline]
                if fresh:
                    matched = _row_key(fresh[-1])

            row = _row_for(rows, matched)

            if row is not None:
                if row.is_done():
                    return Completion(
                        COMPLETION_FIRMWARE,
                        "printer reported done_ok",
                        row.job_id,
                        row.uuid,
                    )

                if row.failed():
                    return Completion(
                        None,
                        "The printer's own job table reported '%s' for this card" % row.state,
                        row.job_id,
                        row.uuid,
                    )

            if self._is_stop():
                stop_streak += 1

                if stop_streak >= self.stop_confirmations:
                    return Completion(None, self._blocking_reason() or "The printer stopped mid-card")
            else:
                stop_streak = 0

            # Noted, not acted on: the card in the printer still finishes.
            if not self.tray_full and self._tray_is_full():
                self.tray_full = True
                self._log("Output tray is full. Finishing this card, then stopping.")

            now = self._clock()

            # Still working on it. The clock for "the printer never said
            # anything" restarts every time it shows that it is doing something.
            if self._card_in_progress(row):
                deadline = min(now + self.firmware_timeout, ceiling)

                if not said_slow and now - started >= self.firmware_timeout:
                    said_slow = True
                    self._progress(STEP_FIRMWARE, ACTIVE,
                                   "the printer is still working on this card")

            if now - last_beat >= self.heartbeat_seconds:
                self._call(self.api.heartbeat, job_id)
                last_beat = now

            if now >= deadline:
                job_id_seen = row.job_id if row is not None else None
                uuid_seen = row.uuid if row is not None else None

                return Completion(
                    COMPLETION_SPOOLER_ONLY,
                    "the printer never confirmed; Windows accepted the job",
                    job_id_seen,
                    uuid_seen,
                )

            self._sleep(self.poll_seconds)

    def _card_in_progress(self, row: Optional[Any]) -> bool:
        """Whether the printer is visibly still working on this card.

        Two independent signs, either of which is enough. The firmware's own job
        row is the better one: a row that is neither done nor failed is a card in
        the machine. The printer's state word covers the window before the row
        appears at all, which on a cold ZXP9 is the several minutes it spends
        heating the transfer roller.
        """
        if row is not None and row.is_in_flight():
            return True

        return self._condition() in (zebra.PRINTING, zebra.INITIALIZING)

    def _verify(self, job: Dict[str, Any]) -> Verification:
        """Ask the camera whether the right card came out.

        Never raises and never blocks completion. A camera that has died means
        unverified printing, which is the state the whole system ran in before.
        """
        if not self._camera_on():
            self._progress(STEP_CAMERA, SKIPPED, "no camera on this printer")
            return Verification(False, False, "camera off")

        self._progress(STEP_CAMERA, ACTIVE, "checking the card")

        try:
            result = _as_verification(self._call_verifier(job))
        except Exception as error:  # noqa: BLE001 - see docstring
            self._progress(STEP_CAMERA, FAILED, "camera error")
            self._log("Camera check failed for job %s: %s" % (job.get("id"), error))
            return Verification(True, False, "camera error: %s" % error)

        self._progress(
            STEP_CAMERA,
            DONE if result.confirmed else FAILED,
            result.detail or ("card seen" if result.confirmed else "no card seen"),
        )

        return result

    # ------------------------------------------------------------------
    # Operator decisions
    # ------------------------------------------------------------------

    def _ask_operator(self, job: Dict[str, Any], reason: str) -> Optional[str]:
        """Put the question to a human and wait for the answer.

        Returns one of CHOICES, or None if nobody answered. None deliberately
        does not mean any of them: an unanswered question parks the queue
        rather than deciding on the operator's behalf.
        """
        decision = ReprintDecision(
            job_id=int(job["id"]),
            card_number=self._card_number(job),
            fursuit_name=self._fursuit_name(job),
            reason=reason,
            printer=self.printer_name,
        )

        self.pending_decision = decision
        self._log(decision.question())
        self._alert(
            "decision:%s:%s" % (self.printer_name, decision.card_number),
            "Card %s needs a decision" % decision.card_number,
            decision.question(),
        )

        if self.on_decision is not None:
            _safely(self.on_decision, decision)

        self._wait_for_answer(decision, int(job["id"]))

        self.pending_decision = None

        return decision.choice

    def _wait_for_answer(self, decision: ReprintDecision, job_id: int) -> None:
        """Wait on a human, renewing the lease while they make up their mind.

        We still hold the claim on this job. Without the heartbeat the reaper
        would hand the card to another agent while the operator is still walking
        over to the printer, and the answer would then apply to a job somebody
        else is printing.

        Real time, not the injected clock: this waits on a person, not on the
        printer, and the print timeouts are what the clock is for.
        """
        deadline = None
        if self.decision_timeout is not None:
            deadline = time.monotonic() + self.decision_timeout

        while not decision.is_answered() and not self._stop.is_set():
            slice_seconds = max(0.5, self.heartbeat_seconds)

            if deadline is not None:
                remaining = deadline - time.monotonic()

                if remaining <= 0:
                    return

                slice_seconds = min(slice_seconds, remaining)

            if decision._answered.wait(slice_seconds):
                return

            self._call(self.api.heartbeat, job_id)

    def answer_decision(self, reprint: bool, decision_id: Optional[int] = None) -> bool:
        """Answer the outstanding reprint question. Returns False if there is none."""
        decision = self.pending_decision

        if decision is None:
            return False

        if decision_id is not None and int(decision_id) != int(decision.id):
            return False

        decision.answer(reprint)

        return True

    # ------------------------------------------------------------------
    # Printer health
    # ------------------------------------------------------------------

    def _gate(self) -> Optional[str]:
        """The may-I-print question. Returns the reason not to, or None."""
        try:
            self.monitor.poll()
        except Exception as error:  # noqa: BLE001 - an unreadable printer is a stopped printer
            return "Could not read the printer: %s" % error

        if self.monitor.may_print():
            return None

        return self._blocking_reason() or "The printer is not ready."

    def _printer_ready(self) -> bool:
        """Whether the thing that stopped the run has been dealt with.

        Asked while paused, so it must be the whole question and not just the
        printer's own opinion: a cleared jam with a full tray underneath it is
        still a station that must not print.
        """
        return self._gate() is None and not self._tray_is_full()

    def _tray_cleared(self) -> bool:
        """Whether the output tray has been emptied.

        Drops the latch as well as answering, because the latch is what
        print_next() reads on its way in and a stale one would stop the very
        card this check just allowed.
        """
        if self._tray_is_full():
            return False

        self.tray_full = False

        return self._gate() is None

    def _wait_until_healthy(self) -> bool:
        """Block until the printer is printable again, or we are told to stop."""
        while not self._stop.is_set():
            if self._gate() is None:
                return True

            self._sleep(self.idle_seconds)

        return False

    def _read_printer(self) -> Any:
        """The current printer reading, always fresh.

        Deliberately never served from the monitor's cache. This is what reads
        the firmware job table, and a stale one means missing the done_ok that
        confirms a card -- which then waits out the full firmware timeout
        before falling back to weaker evidence. The UI loop is the side that
        reuses readings; see PrinterMonitor.poll.
        """
        try:
            self.monitor.poll()
        except Exception:  # noqa: BLE001 - a failed read is no information, not a fault
            return None

        return getattr(self.monitor, "reading", None)

    def _firmware_keys(self) -> set:
        reading = getattr(self.monitor, "reading", None)
        rows = list(getattr(reading, "jobs", None) or [])

        return set(_row_key(row) for row in rows)

    def _is_transient(self) -> bool:
        try:
            return bool(self.monitor.is_transient())
        except Exception:  # noqa: BLE001 - an old monitor simply has no opinion
            return False

    def _is_stop(self) -> bool:
        try:
            return bool(self.monitor.is_stop())
        except Exception:  # noqa: BLE001
            return False

    def _blocking_reason(self) -> str:
        try:
            return self.monitor.blocking_reason() or ""
        except Exception:  # noqa: BLE001
            return ""

    def _condition(self) -> str:
        return str(getattr(self.monitor, "condition", "") or "unknown")

    # ------------------------------------------------------------------
    # Camera
    # ------------------------------------------------------------------

    def _camera_on(self) -> bool:
        """Whether this printer has a working camera to ask.

        Deliberately asks the verifier rather than the config: the operator can
        switch verification off mid-run, and the answer has to follow that
        immediately, because it decides who makes the reprint call.
        """
        if self.verifier is None:
            return False

        checker = getattr(self.verifier, "is_enabled", None)
        if callable(checker):
            try:
                return bool(checker())
            except Exception:  # noqa: BLE001
                return False

        return bool(getattr(self.verifier, "enabled", True))

    def _arm_camera(self) -> None:
        """Take the before picture of the output bin, if there is a camera.

        Optional on the verifier: a stand-in that only knows how to answer
        `verify` still works, it just loses the presence check.
        """
        if not self._camera_on():
            return

        arm = getattr(self.verifier, "arm", None)

        if callable(arm):
            _safely(arm)

    def _call_verifier(self, job: Dict[str, Any]) -> Any:
        for name in ("verify", "verify_card"):
            method = getattr(self.verifier, name, None)
            if callable(method):
                return method(job)

        if callable(self.verifier):
            return self.verifier(job)

        return None

    def _tray_is_full(self) -> bool:
        if self.verifier is None or not self._camera_on():
            return False

        checker = getattr(self.verifier, "tray_full", None)

        if not callable(checker):
            return False

        try:
            return bool(checker())
        except Exception:  # noqa: BLE001 - a camera fault is not a full tray
            return False

    # ------------------------------------------------------------------
    # Odds and ends
    # ------------------------------------------------------------------

    def _selectable_batches(self) -> List[Dict[str, Any]]:
        try:
            return list(self.api.batches() or [])
        except (NetworkError, ApiError) as error:
            self._log("Could not list batches: %s" % error)
            return []

    def _start_batch(self, batch_id: Any) -> bool:
        """Tell the server this printer is working the batch.

        Records success centrally so every route in -- the first claim, the
        unattended hand-off to the next batch, a resume -- agrees about what
        has already been started. Without that the paths disagreed and one
        would re-assert a start the server had already accepted.
        """
        try:
            self.api.start_batch(batch_id, self.printer_name)
        except (NetworkError, ApiError) as error:
            self._log("Could not start batch %s: %s" % (batch_id, error))
            return False

        self._started_batch = int(batch_id) if batch_id is not None else None

        return True

    def _notify_batch(self, batch_id: Optional[int]) -> None:
        if self.on_batch_change is None:
            return

        _safely(self.on_batch_change, batch_id)


class ReceiptWorker(_BaseWorker):
    """Drives the thermal printer. Nobody selects anything; it just prints.

    A receipt is created by a sale that has already happened, so by the time the
    job exists there is a person at the counter waiting for the paper. That
    single fact is what makes this different from the card loop:

    * **No batch.** Receipts are claimed by printer name, and the server hands
      back the oldest unbatched job queued for that printer. There is nothing to
      select, nothing to advance to and nothing to pause.
    * **No camera, no SNMP.** A thermal unit has no job table to ask and nothing
      is pointed at the paper. Completion is therefore ``spooler_only``, and
      here that is the honest answer rather than a fallback from something
      better: Windows accepting the job is genuinely all there is to know.
    * **No operator question.** A failed receipt is reported failed and the loop
      moves straight on to the next one. Parking the queue to ask about a strip
      of paper would leave the next customer waiting on the answer, and a
      receipt can be reprinted from the POS in seconds.

    Failures are reported and left alone. There is no reprint attempt: without a
    way to see the paper we cannot tell a receipt that never printed from one
    that printed and then upset the spooler, and handing somebody two receipts
    for one sale is its own problem.
    """

    role = ROLE_RECEIPT
    noun = "Receipt"

    def __init__(
        self,
        binding: Any,
        api: Any,
        store: Any,
        sender: Callable[[Dict[str, Any], str, Any], Any],
        notifier: Optional[Any] = None,
        cache_dir: Optional[Any] = None,
        on_progress: Optional[Callable[[str, str, str], None]] = None,
        on_log: Optional[Callable[[str], None]] = None,
        heartbeat_seconds: float = 45.0,
        idle_seconds: float = 3.0,
        clock: Optional[Callable[[], float]] = None,
        sleep: Optional[Callable[[float], None]] = None,
    ):
        super().__init__(
            binding=binding,
            api=api,
            store=store,
            sender=sender,
            notifier=notifier,
            cache_dir=cache_dir,
            on_progress=on_progress,
            on_log=on_log,
            heartbeat_seconds=heartbeat_seconds,
            idle_seconds=idle_seconds,
            clock=clock,
            sleep=sleep,
        )

    def snapshot(self) -> Dict[str, Any]:
        """The same shape the card worker reports, so one UI renders both.

        The keys that only mean something for a card are present and honest
        rather than absent: this worker has no batch, no tray to fill and no
        question outstanding, and never will.
        """
        job = self.current_job or {}

        return {
            "printer": self.printer_name,
            "role": self.role,
            "state": self.state,
            "detail": self.status_detail,
            "batch_id": None,
            "unattended": False,
            "tray_full": False,
            "card_number": self._card_number(job) if job else None,
            "job_id": job.get("id") if job else None,
            "outcome": self.last_outcome.kind if self.last_outcome else None,
            "pending_decision": None,
            "outbox": self._outbox_depth(),
        }

    # ------------------------------------------------------------------
    # The loop
    # ------------------------------------------------------------------

    def run(self) -> None:
        """Print receipts until told to stop. Nothing else ends this loop.

        Every outcome except ``stopped`` leads back round: an empty queue is the
        normal state between sales, and a failure affects exactly the receipt it
        happened to. The card loop pauses on both, because there a fault means a
        jammed machine and a hundred cards behind it.
        """
        self.state = RUNNING

        while not self._stop.is_set():
            if self._paused.is_set():
                self._sleep(self.idle_seconds)
                continue

            outcome = self.print_next()
            self.last_outcome = outcome

            if outcome.kind == STOPPED:
                break

            if outcome.kind == PRINTED:
                self.flush_outbox()
                continue

            if outcome.kind == EMPTY:
                # The server is answering and there is nothing to print, which
                # is the best moment there is to clear anything it missed.
                self.flush_outbox()

            self._sleep(self.idle_seconds)

        self.state = HALTED if self._stop.is_set() else self.state

    def print_next(self) -> Outcome:
        """Claim, print and report exactly one receipt."""
        if self._stop.is_set():
            return Outcome(STOPPED, "Worker is stopping")

        self._reset_pipeline()
        self.current_job = None

        self._progress(STEP_CLAIM, ACTIVE, "asking the server for the next receipt")

        try:
            job = self._claim()
        except NetworkError as error:
            detail = "Server unreachable, cannot claim a receipt: %s" % error
            self._progress(STEP_CLAIM, FAILED, "server unreachable")
            self._alert("receipt-claim:%s" % self.printer_name,
                        "Cannot reach the server", detail)
            return Outcome(BLOCKED, detail)
        except ApiError as error:
            detail = "Server refused the claim: %s" % error.message
            self._progress(STEP_CLAIM, FAILED, error.message)
            self._alert("receipt-claim:%s" % self.printer_name,
                        "Cannot claim a receipt", detail)
            return Outcome(BLOCKED, detail)

        if not job:
            self._progress(STEP_CLAIM, SKIPPED, "no receipts waiting")
            return Outcome(EMPTY, "No receipts waiting")

        return self._print_job(job)

    def _claim(self) -> Optional[Dict[str, Any]]:
        """Ask for the oldest unbatched job queued for this printer.

        ``POST /jobs/claim`` takes either a batch id or a printer name, and the
        printer-name form is the receipt lane. Prefers a typed client method
        when there is one and otherwise posts the call itself, so the transport
        does not have to grow a method before this can run.
        """
        claim = getattr(self.api, "claim_unbatched", None)

        if callable(claim):
            return claim(self.printer_name)

        response = self.api.post("/jobs/claim", {"printer_name": self.printer_name}) or {}

        return response.get("job")

    # ------------------------------------------------------------------
    # One receipt
    # ------------------------------------------------------------------

    def _print_job(self, job: Dict[str, Any]) -> Outcome:
        job_id = int(job["id"])
        self.current_job = job

        self._progress(STEP_CLAIM, DONE, "receipt %s" % self._card_number(job))

        # Same discipline as a card: committed to locally before it is printed,
        # so a network drop cannot lose a receipt we already put on paper.
        self._remember(job, JOB_CLAIMED)

        path = self._fetch(job)
        if path is None:
            reason = "Receipt %s could not be fetched" % self._card_number(job)
            self._fail_receipt(job, reason)
            return Outcome(JOB_FAILED, reason, job_id)

        self._call(self.api.mark_printing, job_id)
        self.store_status(job_id, JOB_PRINTING)

        self._progress(STEP_SPOOL, ACTIVE, "sending to %s" % (self.printer_name or "the printer"))

        started = self._clock()
        spooled = _as_spool_result(self._spool(job, path))

        if not spooled.ok:
            detail = spooled.detail or "the spooler refused the job"
            self._progress(STEP_SPOOL, FAILED, detail)
            self._fail_receipt(job, "The receipt printer did not accept the job: %s" % detail)

            return Outcome(JOB_FAILED, detail, job_id)

        self._progress(STEP_SPOOL, DONE, spooled.detail or "accepted by the spooler")

        # The spooler took it and a thermal printer has no job table to ask, so
        # this is as much as will ever be known. Said plainly in the detail
        # rather than by marking the row skipped, which now shares its row with
        # spooling and would read as "the receipt did not print".
        self._progress(STEP_FIRMWARE, DONE,
                       "sent; a thermal printer has no job table to confirm it")
        self._progress(STEP_CAMERA, SKIPPED, "receipts are not camera checked")

        # A receipt spools in about a second, but a printer that is off or out
        # of paper can leave the spooler blocking for minutes, and the lease
        # would be reaped out from under us while we waited.
        if self._clock() - started >= self.heartbeat_seconds:
            self._call(self.api.heartbeat, job_id)

        self._report(
            job,
            Completion(COMPLETION_SPOOLER_ONLY, "the receipt printer has no job table to ask"),
            Verification(False, False, "no camera on a receipt printer"),
        )

        return Outcome(PRINTED, "spooled to %s" % (self.printer_name or "the printer"), job_id)

    def _fail_receipt(self, job: Dict[str, Any], reason: str) -> None:
        """Report the failure and tell somebody. Nothing is paused by this.

        The server-side failure call pauses the job's batch, and a receipt has
        no batch, so a card run in progress on the same station is untouched.
        """
        # Receipts do not hold up the badge run.
        self._alert(
            "receipt:%s:%s" % (self.printer_name, self._card_number(job)),
            "A receipt did not print",
            reason,
            stops_printing=False,
        )

        self._fail(job, reason)


# --- Helpers ------------------------------------------------------------------


def _recorded(response: Any) -> bool:
    """Whether the server actually wrote down what we told it.

    A 200 is not the same answer as "recorded". ``printed`` replies with
    ``marked: false`` when it declined to move the job, and treating that as a
    delivery is how a card that exists goes back into the queue to be printed
    again. Anything that is not an explicit refusal counts as recorded, so a
    stub client or an endpoint that returns nothing behaves as it always did.
    """
    if isinstance(response, dict) and response.get("marked") is False:
        return False

    return True


def _row_key(row: Any) -> tuple:
    """Identity of a firmware job row.

    The table is a rolling window of the last seven jobs, so the index shifts as
    jobs age out and cannot be used. Id plus uuid is stable for the life of a
    row.
    """
    return (getattr(row, "job_id", None), getattr(row, "uuid", None))


def _row_for(rows: List[Any], key: Optional[tuple]) -> Optional[Any]:
    if key is None:
        return None

    for row in rows:
        if _row_key(row) == key:
            return row

    return None


def _as_spool_result(value: Any) -> SpoolResult:
    """Accept a SpoolResult, a bool or None from an injected sender."""
    if isinstance(value, SpoolResult):
        return value

    if value is None:
        return SpoolResult(False, "the sender reported nothing")

    if isinstance(value, bool):
        return SpoolResult(value, "" if value else "the sender reported a failure")

    if isinstance(value, int):
        # print_pages() hands back the spooler job id. A refusal raises
        # PrintError and never returns, so any id means the job was accepted --
        # including 0, which is what the ZXP9 driver reports for a document
        # that spooled perfectly well. Reading that 0 as falsy failed cards
        # that had already printed.
        return SpoolResult(
            True,
            "spool job %d" % value if value else "accepted by the spooler",
            str(value) if value else None,
        )

    ok = bool(getattr(value, "ok", value))

    return SpoolResult(ok, str(getattr(value, "detail", "")), getattr(value, "spool_job_id", None))


def _as_verification(value: Any) -> Verification:
    """Accept a Verification, a bool or an object with `confirmed`."""
    if isinstance(value, Verification):
        return value

    if isinstance(value, bool) or value is None:
        return Verification(True, bool(value), "")

    confirmed = getattr(value, "confirmed", None)

    if confirmed is None:
        confirmed = getattr(value, "matches", None)
    if confirmed is None:
        confirmed = getattr(value, "present", False)

    return Verification(
        True,
        bool(confirmed),
        str(getattr(value, "detail", "")),
        bool(getattr(value, "blank", False)),
    )


def _safely(callback: Callable, *args, **kwargs) -> None:
    """A broken callback must never take the printer down with it."""
    try:
        callback(*args, **kwargs)
    except Exception:  # noqa: BLE001
        return
