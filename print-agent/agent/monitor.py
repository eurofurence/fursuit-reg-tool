"""Printer health gate.

Wraps the SNMP poller, the classifier and the vocabulary journal into the one
question the print loop actually needs answered: may I send the next card?

The rule is blunt on purpose. Any stop condition, including one we cannot name,
halts printing. The system this replaces kept feeding cards to a printer that had
jammed because nothing ever told it to stop, and the cards were recorded as
printed on a timer. Nothing gets printed here unless the printer is affirmatively
healthy.
"""

from __future__ import annotations

import time
from typing import Callable, List, Optional

from . import vocabulary, zebra


class PrinterMonitor:
    def __init__(
        self,
        poller: zebra.ZebraPoller,
        journal: Optional[vocabulary.ConditionJournal] = None,
        ribbon_warn_threshold: int = 50,
        offline_confirmations: int = 3,
    ):
        self.poller = poller
        self.journal = journal or vocabulary.ConditionJournal()
        self.ribbon_warn_threshold = ribbon_warn_threshold

        # How many unreachable reads in a row before believing the printer is
        # offline. SNMP is UDP with a single retry, so one lost packet -- most
        # likely while the printer is busy printing -- used to raise "printer
        # offline" and stop the queue on a printer that was working fine.
        self.offline_confirmations = max(1, int(offline_confirmations))
        self._offline_streak = 0

        self.condition: str = zebra.UNKNOWN
        self.reading: Optional[zebra.Reading] = None

        # When the last reading was taken, for the max_age cache in poll().
        self._read_at = 0.0

        # Called with (condition, reading) whenever the condition changes.
        # Used to push the state to the server and to alert staff.
        self.on_change: List[Callable[[str, zebra.Reading], None]] = []

        # Called when the journal records something we could not explain, so the
        # UI can prompt the operator to send the file in.
        self.on_unknown: List[Callable[[zebra.Reading], None]] = []

    def poll(self, max_age: float = 0.0) -> str:
        """Read the printer and update the current condition.

        ``max_age`` returns the last reading instead of taking a new one when
        it is younger than that many seconds. Two things poll this monitor --
        the UI's own loop and the print worker waiting for a card to be
        confirmed -- and each reading is three SNMP subtree walks including the
        whole job table. Without this they each took their own, doubling the
        traffic and making the confirm step crawl.
        """
        if max_age > 0.0 and self.reading is not None:
            if (time.monotonic() - self._read_at) < max_age:
                return self.condition

        reading = self.poller.read()
        self._read_at = time.monotonic()

        if getattr(reading, "reachable", True):
            self._offline_streak = 0
        else:
            self._offline_streak += 1

            if self._offline_streak < self.offline_confirmations:
                # Not believed yet. Keep the last known condition rather than
                # reporting a stop, and say nothing: a blip that resolves on
                # the next read should leave no trace at all.
                return self.condition
        condition = zebra.classify(reading, self.ribbon_warn_threshold)

        logged = self.journal.record(reading, condition)

        previous = self.condition
        self.condition = condition
        self.reading = reading

        if condition != previous:
            for callback in self.on_change:
                _safely(callback, condition, reading)

        if logged and vocabulary.unknown_strings(reading):
            for callback in self.on_unknown:
                _safely(callback, reading)

        return condition

    def is_stop(self) -> bool:
        return zebra.is_stop(self.condition)

    def is_transient(self) -> bool:
        """Whether the printer is busy becoming ready, rather than faulted."""
        return zebra.is_transient(self.condition)

    def may_print(self) -> bool:
        """Whether it is safe to send another card.

        Requires a positively healthy printer. `printing` is excluded because the
        agent prints strictly one card at a time and waits for it to land.
        """
        return self.condition in (zebra.OK, zebra.RIBBON_LOW, zebra.FILM_LOW, zebra.CARDS_LOW)

    def blocking_reason(self) -> Optional[str]:
        """Human-readable explanation for the UI and for pausing a batch."""
        if self.may_print():
            return None

        if self.condition == zebra.UNKNOWN:
            unknowns = vocabulary.unknown_strings(self.reading) if self.reading else []
            if unknowns:
                values = ", ".join(sorted({u["value"] for u in unknowns}))
                return (
                    "Printer reported something we do not recognise (%s). "
                    "Stopping to be safe; the reading has been written to the "
                    "condition journal." % values
                )
            return "Printer state could not be determined. Stopping to be safe."

        return REASONS.get(self.condition, "Printer is not ready (%s)." % self.condition)

    def cards_remaining(self) -> Optional[int]:
        """Cards, not ribbon panels. The printer counts in panels."""
        return zebra.cards_from_supply(self.reading.supply_level) if self.reading else None

    def ribbon_warning(self) -> Optional[str]:
        """Warn early so staff can fetch a ribbon before the queue stops."""
        remaining = self.cards_remaining()

        if remaining is None or remaining > self.ribbon_warn_threshold:
            return None

        return "Ribbon has about %d cards left. Fetch a replacement." % remaining


REASONS = {
    zebra.CARD_JAM: "Card jam. Open the printer and clear the jammed card.",
    zebra.COVER_OPEN: "Printer cover is open.",
    zebra.RIBBON_OUT: "Colour ribbon is empty. Replace it.",
    zebra.FILM_OUT: "Transfer film is empty. Replace it.",
    zebra.CARDS_OUT: "Card hopper is empty. Refill it.",
    zebra.REJECT_BIN_FULL: "Reject bin is full. Empty it.",
    zebra.SERVICE_REQUIRED: "Printer needs servicing. Check the front panel.",
    zebra.OFFLINE: "Printer is not answering. Check power and network.",
    zebra.PRINTING: "Printer is still working on the previous card.",
    zebra.INITIALIZING: "Printer is warming up.",
}


def _safely(callback, *args) -> None:
    """A broken callback must never take the printer down with it."""
    try:
        callback(*args)
    except Exception:
        return
