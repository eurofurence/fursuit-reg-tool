"""Learn the printer's fault vocabulary from the printer.

Zebra never published a MIB for the card printer line, so the strings the ZXP
firmware puts in its alarm slots are undocumented. The mapping in zebra.py
covers what we have observed plus reasonable guesses, and it will not be
complete.

Rather than guess harder, the agent writes down every string it does not
recognise. Hand the resulting file over and the vocabulary gets extended from
real faults instead of from speculation.

Nothing here changes what the agent *does* about a fault. An unrecognised
reading already stops printing, because assuming an unknown state is harmless is
exactly how the previous system lost badges. This only makes the unknown
knowable next time.
"""

from __future__ import annotations

import json
import os
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Set

from . import zebra

JOURNAL_FILENAME = "condition-journal.jsonl"

# Values that mean "nothing to report" rather than a state we failed to parse.
EMPTY_VALUES = {"", "none", "unknown", "n/a", "-"}

# States we already understand well enough not to write down.
KNOWN_PRINTER_STATES = {
    "idle", "ready", "ok", "standby", "printing", "busy",
    # Observed on the real ZXP9 on the way into a job:
    # standby -> initializing -> printing_heating.
    "initializing", "initialising", "warming_up", "warmup",
    "printing_heating",
}


def known_alarm_needles() -> Set[str]:
    return {needle for needle, _ in zebra.ALARM_CONDITIONS}


def unknown_strings(reading: zebra.Reading) -> List[Dict[str, str]]:
    """Every string in this reading that our vocabulary cannot explain.

    These are precisely the values worth adding to zebra.ALARM_CONDITIONS.
    """
    findings: List[Dict[str, str]] = []
    needles = known_alarm_needles()

    def is_explained(value: str) -> bool:
        lowered = value.lower()
        return any(needle in lowered for needle in needles)

    for index, alarm in enumerate(reading.alarms):
        value = (alarm or "").strip()
        if value.lower() in EMPTY_VALUES:
            continue
        if not is_explained(value):
            findings.append({"field": "alarm_slot_%d" % (index + 1), "value": value})

    sensor = (reading.sensor_fault or "").strip()
    if sensor.lower() not in EMPTY_VALUES and not is_explained(sensor):
        findings.append({"field": "sensor_fault", "value": sensor})

    state = (reading.printer_state or "").strip()
    if state.lower() not in EMPTY_VALUES and state.lower() not in KNOWN_PRINTER_STATES:
        findings.append({"field": "printer_state", "value": state})

    for row in reading.jobs:
        job_state = (row.state or "").strip()
        if not job_state:
            continue
        lowered = job_state.lower()
        if lowered == zebra.JOB_STATE_DONE or lowered in zebra.JOB_STATES_IN_FLIGHT:
            continue
        findings.append({"field": "job_state", "value": job_state})

    return findings


def signature(reading: zebra.Reading, condition: str) -> str:
    """Collapse a reading to a stable key, so a jam that lasts ten minutes is
    written down once rather than three hundred times."""
    parts = [
        condition,
        reading.printer_state or "",
        "|".join(sorted(reading.error_bits)),
        "|".join(sorted(a for a in reading.alarms if a)),
        reading.sensor_fault or "",
        "|".join(sorted({(row.state or "") for row in reading.jobs if row.state})),
    ]
    return "~".join(parts)


class ConditionJournal:
    """Append-only log of interesting printer readings.

    Deliberately plain JSON lines: it has to be readable on a Windows 7 box with
    nothing installed, and small enough to paste into a chat.
    """

    def __init__(self, path: Optional[Path] = None, max_entries: int = 2000):
        if path is None:
            from .config import config_dir

            path = config_dir() / JOURNAL_FILENAME

        self.path = Path(path)
        self.max_entries = max_entries
        self._seen: Set[str] = set()
        self._load_seen()

    def _load_seen(self) -> None:
        """Re-read known signatures so a restart does not re-log the same jam."""
        if not self.path.exists():
            return

        try:
            with open(self.path) as handle:
                for line in handle:
                    try:
                        self._seen.add(json.loads(line)["signature"])
                    except (ValueError, KeyError):
                        continue
        except OSError:
            pass

    def record(self, reading: zebra.Reading, condition: str, note: str = "") -> bool:
        """Write this reading down if we have not seen its shape before.

        Returns True when something new was logged, which the UI uses to nudge
        the operator to send the file in.
        """
        key = signature(reading, condition)

        if key in self._seen:
            return False

        unknowns = unknown_strings(reading)

        # Worth recording if we could not classify it, if it contains strings we
        # do not know, or if it is any kind of stop: the full picture of a real
        # fault is more useful than the one field that triggered it.
        if condition != zebra.UNKNOWN and not unknowns and not zebra.is_stop(condition):
            return False

        entry = {
            "at": datetime.now().isoformat(timespec="seconds"),
            "signature": key,
            "condition": condition,
            "is_stop": zebra.is_stop(condition),
            "note": note,
            "unknown_strings": unknowns,
            "printer_state": reading.printer_state,
            "error_bits": reading.error_bits,
            "alarms": [a for a in reading.alarms if a],
            "sensor_fault": reading.sensor_fault,
            "supply_level": reading.supply_level,
            "supply_max": reading.supply_max,
            "jobs": [
                {"id": r.job_id, "uuid": r.uuid, "state": r.state, "location": r.location}
                for r in reading.jobs
            ],
            # The full walk, so a value we did not think to name is still
            # recoverable after the fact.
            "raw": reading.raw,
        }

        self._seen.add(key)
        self._append(entry)
        return True

    def _append(self, entry: Dict) -> None:
        try:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            with open(self.path, "a") as handle:
                handle.write(json.dumps(entry) + "\n")
        except OSError:
            # Never let diagnostics take the printer down.
            return

        self._trim()

    def _trim(self) -> None:
        """Keep the file small enough to hand over in one piece."""
        try:
            if not self.path.exists():
                return
            with open(self.path) as handle:
                lines = handle.readlines()
            if len(lines) <= self.max_entries:
                return
            with open(self.path, "w") as handle:
                handle.writelines(lines[-self.max_entries:])
        except OSError:
            return

    def summary(self) -> Dict[str, int]:
        """Counts for the UI, so the operator can see there is something to send."""
        counts = {"entries": 0, "unknown": 0, "stops": 0}

        if not self.path.exists():
            return counts

        try:
            with open(self.path) as handle:
                for line in handle:
                    try:
                        entry = json.loads(line)
                    except ValueError:
                        continue
                    counts["entries"] += 1
                    if entry.get("unknown_strings"):
                        counts["unknown"] += 1
                    if entry.get("is_stop"):
                        counts["stops"] += 1
        except OSError:
            pass

        return counts

    def export_path(self) -> str:
        return os.fspath(self.path)
