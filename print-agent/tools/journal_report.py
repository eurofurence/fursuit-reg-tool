#!/usr/bin/env python3
"""Summarise the condition journal into something worth pasting into a chat.

Zebra publishes no MIB for the card printer line, so the agent's fault
vocabulary is built from observation. When the printer does something we have
not taught it about, the agent writes the full reading to a journal. Run this
afterwards and hand over the output to get the vocabulary extended.

Usage:
    python3 journal_report.py                       # default journal location
    python3 journal_report.py path/to/journal.jsonl
    python3 journal_report.py --full                # include raw SNMP walks
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from collections import Counter, defaultdict
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def default_path() -> Path:
    base = os.environ.get("APPDATA") or os.path.expanduser("~")
    return Path(base) / "BadgePrintAgent" / "condition-journal.jsonl"


def load(path: Path) -> list:
    if not path.exists():
        print("No journal at %s" % path)
        print("Nothing has gone wrong yet, or the agent has not run on this machine.")
        return []

    entries = []
    with open(path) as handle:
        for line in handle:
            try:
                entries.append(json.loads(line))
            except ValueError:
                continue
    return entries


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("path", nargs="?", default=None)
    parser.add_argument("--full", action="store_true", help="include the raw SNMP walk per entry")
    args = parser.parse_args()

    path = Path(args.path) if args.path else default_path()
    entries = load(path)

    if not entries:
        return 0

    print("=" * 70)
    print("PRINTER CONDITION JOURNAL")
    print("source: %s" % path)
    print("entries: %d" % len(entries))
    print("=" * 70)

    conditions = Counter(entry.get("condition", "?") for entry in entries)
    print("\nConditions seen:")
    for condition, count in conditions.most_common():
        print("  %-20s %d" % (condition, count))

    # The payload: strings the agent could not explain, which is exactly what
    # needs adding to zebra.ALARM_CONDITIONS.
    unknown = defaultdict(set)
    for entry in entries:
        for finding in entry.get("unknown_strings", []):
            unknown[finding["field"]].add(finding["value"])

    if not unknown:
        print("\nNothing unexplained. The vocabulary already covers every fault seen.")
    else:
        print("\n" + "-" * 70)
        print("UNRECOGNISED STRINGS  <- this is the bit to hand over")
        print("-" * 70)
        for field in sorted(unknown):
            print("\n%s:" % field)
            for value in sorted(unknown[field]):
                print("  %r" % value)

    print("\n" + "-" * 70)
    print("CONTEXT PER FAULT")
    print("-" * 70)
    for entry in entries:
        if not entry.get("is_stop") and not entry.get("unknown_strings"):
            continue

        print("\n[%s] condition=%s stop=%s" % (
            entry.get("at", "?"), entry.get("condition"), entry.get("is_stop")))
        print("  printer_state : %s" % entry.get("printer_state"))
        print("  error_bits    : %s" % (entry.get("error_bits") or "none"))
        print("  alarms        : %s" % (entry.get("alarms") or "none"))
        print("  sensor_fault  : %s" % (entry.get("sensor_fault") or "none"))
        print("  ribbon        : %s / %s" % (entry.get("supply_level"), entry.get("supply_max")))

        jobs = entry.get("jobs") or []
        if jobs:
            newest = jobs[-1]
            print("  newest job    : id=%s state=%s location=%s" % (
                newest.get("id"), newest.get("state"), newest.get("location")))

        if args.full and entry.get("raw"):
            print("  raw walk:")
            for oid in sorted(entry["raw"]):
                print("    %s = %s" % (oid, entry["raw"][oid]))

    print("\n" + "=" * 70)
    if unknown:
        print("Paste the UNRECOGNISED STRINGS section, plus the context for those")
        print("faults, and say what was physically wrong with the printer at the")
        print("time. That is enough to map each string to a condition.")
    print("=" * 70)

    return 0


if __name__ == "__main__":
    sys.exit(main())
