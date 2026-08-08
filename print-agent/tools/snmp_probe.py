#!/usr/bin/env python3
"""Watch a Zebra ZXP card printer over SNMP and log every value that changes.

The ZXP Windows driver does not report usable status, so the print agent reads
the printer directly over SNMP instead. This script exists to discover the
vocabulary the firmware uses: run it, then physically induce a fault (open the
cover, pull the ribbon, jam a card) and read off which OIDs move and what
strings they take. Those strings become the fault mapping in agent/zebra.py.

Usage:
    python3 snmp_probe.py 10.0.0.92
    python3 snmp_probe.py 10.0.0.92 --interval 0.5 --log jam-test.log

Requires the net-snmp CLI tools (`snmpwalk`), not pysnmp, so it runs anywhere
without installing anything. The agent itself uses pysnmp.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
import time
from datetime import datetime

# Subtrees worth watching. Everything else on the printer is static config.
SUBTREES = {
    "zebra": "1.3.6.1.4.1.10642.8",
    "supplies": "1.3.6.1.2.1.43.11.1.1",
    "hrprinter": "1.3.6.1.2.1.25.3.5",
}

# Annotations for the OIDs whose meaning we have already established, so the
# change log reads as English rather than as a wall of dotted decimals.
KNOWN = {
    "1.3.6.1.4.1.10642.8.2.1.3": "alarm slot 1",
    "1.3.6.1.4.1.10642.8.2.1.4": "alarm slot 2",
    "1.3.6.1.4.1.10642.8.2.1.5": "alarm slot 3",
    "1.3.6.1.4.1.10642.8.4.1.1": "printer state",
    "1.3.6.1.4.1.10642.8.4.1.2": "printer state (2)",
    "1.3.6.1.4.1.10642.8.5.1.2": "job: printer job id",
    "1.3.6.1.4.1.10642.8.5.1.3": "job: uuid",
    "1.3.6.1.4.1.10642.8.5.1.4": "job: state",
    "1.3.6.1.4.1.10642.8.5.1.5": "job: card location",
    "1.3.6.1.4.1.10642.8.5.1.9": "job: message slot 1",
    "1.3.6.1.4.1.10642.8.5.1.10": "job: message slot 2",
    "1.3.6.1.4.1.10642.8.5.1.11": "job: message slot 3",
    "1.3.6.1.4.1.10642.8.8.1.19": "sensor fault",
    "1.3.6.1.2.1.43.11.1.1.9": "supply level (cards remaining)",
    "1.3.6.1.2.1.25.3.5.1.1": "hrPrinterStatus",
    "1.3.6.1.2.1.25.3.5.1.2": "hrPrinterDetectedErrorState",
}

# RFC 3805 hrPrinterDetectedErrorState, one bit per condition, MSB first.
ERROR_BITS = [
    "lowPaper", "noPaper", "lowToner", "noToner", "doorOpen", "jammed",
    "offline", "serviceRequested", "inputTrayMissing", "outputTrayMissing",
    "markerSupplyMissing", "outputNearFull", "outputFull", "inputTrayEmpty",
    "overduePreventMaint",
]


def label(oid: str) -> str:
    """Return a human annotation for an OID, matching on its column prefix."""
    for prefix, name in KNOWN.items():
        if oid.startswith(prefix + ".") or oid == prefix:
            index = oid[len(prefix):].lstrip(".")
            return f"{name}[{index}]" if index else name
    return ""


def decode_error_state(value: str) -> str:
    """Turn the hrPrinterDetectedErrorState hex bitfield into condition names."""
    hex_part = value.split(":", 1)[-1].strip()
    try:
        raw = bytes.fromhex(hex_part.replace(" ", ""))
    except ValueError:
        return ""

    active = [
        name
        for index, name in enumerate(ERROR_BITS)
        if index // 8 < len(raw) and raw[index // 8] & (0x80 >> (index % 8))
    ]
    return ", ".join(active) if active else "no errors"


def walk(host: str, community: str, root: str, timeout: int) -> dict[str, str]:
    """Walk one subtree, returning {numeric oid: value}. Empty dict on failure."""
    result = subprocess.run(
        ["snmpwalk", "-v2c", "-c", community, "-t", str(timeout), "-r", "1",
         "-On", "-Oe", host, root],
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        return {}

    values = {}
    for line in result.stdout.splitlines():
        if " = " not in line:
            continue
        oid, value = line.split(" = ", 1)
        values[oid.strip().lstrip(".")] = value.strip()
    return values


def snapshot(host: str, community: str, timeout: int) -> dict[str, str]:
    combined = {}
    for root in SUBTREES.values():
        combined.update(walk(host, community, root, timeout))
    return combined


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("host", help="printer IP, e.g. 10.0.0.92")
    parser.add_argument("--community", default="public")
    parser.add_argument("--interval", type=float, default=1.0, help="seconds between polls")
    parser.add_argument("--timeout", type=int, default=3, help="snmp timeout in seconds")
    parser.add_argument("--log", help="also append output to this file")
    args = parser.parse_args()

    sink = open(args.log, "a", buffering=1) if args.log else None

    def emit(line: str) -> None:
        print(line, flush=True)
        if sink:
            sink.write(line + "\n")

    emit(f"# watching {args.host} every {args.interval}s - Ctrl-C to stop")
    previous = snapshot(args.host, args.community, args.timeout)
    if not previous:
        emit(f"! no SNMP response from {args.host}")
        return 1
    emit(f"# baseline captured: {len(previous)} OIDs")

    try:
        while True:
            time.sleep(args.interval)
            current = snapshot(args.host, args.community, args.timeout)
            if not current:
                emit(f"{datetime.now():%H:%M:%S} ! printer unreachable")
                continue

            for oid in sorted(set(previous) | set(current)):
                before = previous.get(oid, "<absent>")
                after = current.get(oid, "<absent>")
                if before == after:
                    continue

                annotation = label(oid)
                suffix = f"  ({annotation})" if annotation else ""
                emit(f"{datetime.now():%H:%M:%S} {oid}{suffix}\n    {before}  ->  {after}")

                if oid.startswith("1.3.6.1.2.1.25.3.5.1.2"):
                    emit(f"    decoded: {decode_error_state(after)}")

            previous = current
    except KeyboardInterrupt:
        emit("# stopped")

    if sink:
        sink.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
