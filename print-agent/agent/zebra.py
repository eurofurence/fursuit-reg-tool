"""Read the Zebra ZXP card printer directly over SNMP.

The ZXP Windows driver reports "online" unconditionally and none of its status
codes track reality, which is how the previous system managed to record cards as
printed while the printer sat jammed. The printer firmware is far more honest and
answers SNMP on the network, so that is what we trust.

See print-agent/docs/snmp/README.md for the OID map and how it was established.

The classification below is a pure function over a reading, so the mapping can be
tested against recorded fault states without a printer attached.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Dict, List, Optional

# --- Conditions -------------------------------------------------------------
# These strings are the contract with the server: they must stay in step with
# App\Enum\PrinterConditionEnum.

OK = "ok"
PRINTING = "printing"
RIBBON_LOW = "ribbon_low"
RIBBON_OUT = "ribbon_out"
FILM_LOW = "film_low"
FILM_OUT = "film_out"
CARDS_LOW = "cards_low"
CARDS_OUT = "cards_out"
CARD_JAM = "card_jam"
COVER_OPEN = "cover_open"
REJECT_BIN_FULL = "reject_bin_full"
SERVICE_REQUIRED = "service_required"
OFFLINE = "offline"
INITIALIZING = "initializing"
UNKNOWN = "unknown"

NON_STOP = {OK, PRINTING, RIBBON_LOW, FILM_LOW, CARDS_LOW}

# Conditions that clear by themselves given a moment.
#
# A ZXP9 walks standby -> initializing -> printing_heating on its way into a
# job, so `initializing` is the healthy sound of a printer waking up, not a
# fault. It still must not be printed onto -- the card would be sent before the
# machine is ready -- but the queue waits it out rather than pausing and
# fetching somebody, which is what every other non-printable condition does.
TRANSIENT = {INITIALIZING}


def is_transient(condition: str) -> bool:
    """Whether waiting is the right response, rather than calling an operator."""
    return condition in TRANSIENT


def is_stop(condition: str) -> bool:
    """Anything we do not positively recognise as healthy halts the queue.

    Deliberately fail-closed. Treating an unrecognised state as fine is exactly
    the assumption that lost badges before.
    """
    return condition not in NON_STOP


# --- OIDs -------------------------------------------------------------------

OID_HR_PRINTER_STATUS = "1.3.6.1.2.1.25.3.5.1.1.1"
OID_HR_ERROR_STATE = "1.3.6.1.2.1.25.3.5.1.2.1"

OID_SUPPLY_LEVEL = "1.3.6.1.2.1.43.11.1.1.9.1.1"
OID_SUPPLY_MAX = "1.3.6.1.2.1.43.11.1.1.8.1.1"
OID_SUPPLY_DESCRIPTION = "1.3.6.1.2.1.43.11.1.1.6.1.1"

OID_ZEBRA_STATE = "1.3.6.1.4.1.10642.8.4.1.1.1"
OID_ZEBRA_ALARMS = (
    "1.3.6.1.4.1.10642.8.2.1.3.1",
    "1.3.6.1.4.1.10642.8.2.1.4.1",
    "1.3.6.1.4.1.10642.8.2.1.5.1",
)
OID_ZEBRA_SENSOR_FAULT = "1.3.6.1.4.1.10642.8.8.1.19.1"

# Rolling window of the last 7 jobs, newest last. Columns: printer job id, uuid,
# state, card location.
OID_JOB_ID_PREFIX = "1.3.6.1.4.1.10642.8.5.1.2.1"
OID_JOB_UUID_PREFIX = "1.3.6.1.4.1.10642.8.5.1.3.1"
OID_JOB_STATE_PREFIX = "1.3.6.1.4.1.10642.8.5.1.4.1"
OID_JOB_LOCATION_PREFIX = "1.3.6.1.4.1.10642.8.5.1.5.1"

# RFC 3805 hrPrinterDetectedErrorState, MSB first.
ERROR_BITS = [
    "lowPaper", "noPaper", "lowToner", "noToner", "doorOpen", "jammed",
    "offline", "serviceRequested", "inputTrayMissing", "outputTrayMissing",
    "markerSupplyMissing", "outputNearFull", "outputFull", "inputTrayEmpty",
    "overduePreventMaint",
]

# Error bits, most severe first, so a jam is reported ahead of a low ribbon.
BIT_CONDITIONS = [
    ("jammed", CARD_JAM),
    ("doorOpen", COVER_OPEN),
    ("outputFull", REJECT_BIN_FULL),
    ("serviceRequested", SERVICE_REQUIRED),
    ("offline", OFFLINE),
    ("noPaper", CARDS_OUT),
    ("inputTrayEmpty", CARDS_OUT),
    ("noToner", RIBBON_OUT),
    ("markerSupplyMissing", RIBBON_OUT),
    ("lowToner", RIBBON_LOW),
    ("lowPaper", CARDS_LOW),
]

# Substrings seen in the Zebra alarm slots and sensor fault field. The healthy
# value is the literal string "none"; the fault vocabulary is filled in from
# real faults captured with tools/snmp_probe.py.
ALARM_CONDITIONS = [
    ("jam", CARD_JAM),
    ("cover", COVER_OPEN),
    ("door", COVER_OPEN),
    ("ribbon_out", RIBBON_OUT),
    ("ribbon out", RIBBON_OUT),
    ("out_of_ribbon", RIBBON_OUT),
    ("ribbon", RIBBON_LOW),
    ("film_out", FILM_OUT),
    ("out_of_film", FILM_OUT),
    ("film", FILM_LOW),
    ("empty_feeder", CARDS_OUT),
    ("out_of_cards", CARDS_OUT),
    ("no_cards", CARDS_OUT),
    ("hopper", CARDS_OUT),
    ("reject", REJECT_BIN_FULL),
    ("service", SERVICE_REQUIRED),
]

# Job states from 10642.8.5.1.4. done_ok is the only one that means a finished
# card; everything else is either in flight or a problem.
JOB_STATE_DONE = "done_ok"
JOB_STATES_IN_FLIGHT = {
    "cleaning_up", "printing", "transferring", "queued", "in_progress",
    "started", "spooling", "waiting",
}


@dataclass
class JobRow:
    """One entry from the printer's own job table."""

    index: int
    job_id: Optional[str] = None
    uuid: Optional[str] = None
    state: Optional[str] = None
    location: Optional[str] = None

    def is_done(self) -> bool:
        return (self.state or "").lower() == JOB_STATE_DONE

    def is_in_flight(self) -> bool:
        return (self.state or "").lower() in JOB_STATES_IN_FLIGHT

    def failed(self) -> bool:
        """A terminal state that is not done_ok is a failure."""
        state = (self.state or "").lower()
        return bool(state) and not self.is_done() and not self.is_in_flight()


@dataclass
class Reading:
    """A single snapshot of the printer."""

    reachable: bool = True
    printer_state: str = ""
    error_bits: List[str] = field(default_factory=list)
    alarms: List[str] = field(default_factory=list)
    sensor_fault: str = ""
    supply_level: Optional[int] = None
    supply_max: Optional[int] = None
    supply_description: str = ""
    jobs: List[JobRow] = field(default_factory=list)
    raw: Dict[str, str] = field(default_factory=dict)

    def newest_job(self) -> Optional[JobRow]:
        return self.jobs[-1] if self.jobs else None

    def job_by_uuid(self, uuid: str) -> Optional[JobRow]:
        for row in self.jobs:
            if row.uuid == uuid:
                return row
        return None


def clean_value(value: str) -> str:
    """Normalise an SNMP string value.

    net-snmp renders string values wrapped in double quotes, and some Zebra
    fields carry trailing padding spaces (the firmware revision reads
    `"FZ9HG.01.11.09       "`). Left alone, the quotes make every comparison in
    classify() miss and the printer reads as permanently `unknown`.
    """
    value = value.strip()

    if len(value) >= 2 and value[0] == '"' and value[-1] == '"':
        value = value[1:-1]

    return value.strip()


def decode_error_bits(hex_string: str) -> List[str]:
    """Turn the hrPrinterDetectedErrorState hex bitfield into condition names."""
    cleaned = hex_string.replace("0x", "").replace(" ", "").strip()

    if not cleaned:
        return []

    try:
        raw = bytes.fromhex(cleaned)
    except ValueError:
        return []

    return [
        name
        for index, name in enumerate(ERROR_BITS)
        if index // 8 < len(raw) and raw[index // 8] & (0x80 >> (index % 8))
    ]


# The supply counter the printer reports is not in cards. Measured on the real
# ZXP9 printing the dual-sided badges this station prints, it falls by two per
# card (121 -> 119 -> 117 across two cards in the condition journal).
#
# Taken from the hardware rather than from how a YMCK ribbon is specified,
# because the number that matters is how many more cards this machine will
# actually produce before somebody has to change the ribbon. Re-measure if the
# badge ever stops being dual-sided.
SUPPLY_UNITS_PER_CARD = 2


def cards_from_supply(level: Optional[int]) -> Optional[int]:
    """Cards left, from the panel count the printer reports."""
    if level is None:
        return None

    return int(level) // SUPPLY_UNITS_PER_CARD


def classify(reading: Reading, ribbon_warn_threshold: int = 50) -> str:
    """Reduce a reading to one condition the server and POS understand.

    Order matters: a hard stop always wins over a warning, and a warning always
    wins over "everything is fine".
    """
    if not reading.reachable:
        return OFFLINE

    # 1. The standard error bitfield, most severe first.
    for bit, condition in BIT_CONDITIONS:
        if bit in reading.error_bits:
            return condition

    # 2. Zebra's own alarm slots and sensor fault field.
    texts = [a.lower() for a in reading.alarms if a and a.lower() != "none"]
    if reading.sensor_fault and reading.sensor_fault.lower() != "none":
        texts.append(reading.sensor_fault.lower())

    for text in texts:
        for needle, condition in ALARM_CONDITIONS:
            if needle in text:
                return condition

    # An alarm we have never seen before must not be waved through.
    if texts:
        return UNKNOWN

    # 3. Consumables. Exhausted is a stop, low is only a warning.
    if reading.supply_level is not None:
        # Thresholds are quoted in cards, because that is what an operator
        # counts and what the queue length is measured in.
        cards_left = cards_from_supply(reading.supply_level)

        if reading.supply_level <= 0:
            return RIBBON_OUT
        if cards_left is not None and cards_left <= ribbon_warn_threshold:
            return RIBBON_LOW

    # 4. Otherwise fall back to the printer's own state word.
    state = (reading.printer_state or "").lower()
    # The firmware qualifies the printing state as it works: printing_heating
    # while the transfer roller comes up to temperature, and other
    # printing_<phase> words besides. They all mean a card is in progress.
    if state in ("printing", "busy") or state.startswith("printing"):
        return PRINTING
    if state in ("initializing", "initialising", "warming_up", "warmup"):
        return INITIALIZING
    # `standby` is what a ZXP Series 9 actually reports when it is powered,
    # healthy and waiting for work. Observed on the real unit, which until it
    # was added here classified a perfectly good printer as `unknown` and
    # stopped the queue.
    if state in ("idle", "ready", "ok", "standby"):
        return OK
    if state:
        return UNKNOWN

    return UNKNOWN


class ZebraPoller:
    """Reads the printer over SNMP.

    pysnmp is imported lazily so the rest of the agent, and its tests, work on a
    machine that has no SNMP stack installed.
    """

    def __init__(self, host: str, community: str = "public", timeout: int = 3):
        self.host = host
        self.community = community

        # Built once and kept. A fresh SnmpEngine per walk means three of them
        # per reading, and constructing one is far from free -- it dominated
        # the time spent waiting for the printer to confirm a card.
        self._engine = None
        self.timeout = timeout

    def read(self) -> Reading:
        if not self.host:
            return Reading(reachable=False)

        try:
            values = self._get_all()
        except Exception:
            return Reading(reachable=False)

        if not values:
            return Reading(reachable=False)

        return self._build(values)

    def _build(self, values: Dict[str, str]) -> Reading:
        # Clean on the way in as well as on the way out of _format, so a reading
        # assembled from raw snmpwalk output behaves the same as one from pysnmp.
        values = {oid: clean_value(value) for oid, value in values.items()}

        reading = Reading(reachable=True, raw=values)

        reading.printer_state = values.get(OID_ZEBRA_STATE, "")
        reading.error_bits = decode_error_bits(values.get(OID_HR_ERROR_STATE, ""))
        reading.alarms = [values.get(oid, "") for oid in OID_ZEBRA_ALARMS]
        reading.sensor_fault = values.get(OID_ZEBRA_SENSOR_FAULT, "")
        reading.supply_description = values.get(OID_SUPPLY_DESCRIPTION, "")

        for attribute, oid in (("supply_level", OID_SUPPLY_LEVEL), ("supply_max", OID_SUPPLY_MAX)):
            try:
                setattr(reading, attribute, int(values[oid]))
            except (KeyError, TypeError, ValueError):
                setattr(reading, attribute, None)

        reading.jobs = self._build_jobs(values)

        return reading

    @staticmethod
    def _build_jobs(values: Dict[str, str]) -> List[JobRow]:
        rows: Dict[int, JobRow] = {}

        columns = (
            (OID_JOB_ID_PREFIX, "job_id"),
            (OID_JOB_UUID_PREFIX, "uuid"),
            (OID_JOB_STATE_PREFIX, "state"),
            (OID_JOB_LOCATION_PREFIX, "location"),
        )

        for oid, value in values.items():
            for prefix, attribute in columns:
                if not oid.startswith(prefix + "."):
                    continue
                try:
                    index = int(oid[len(prefix) + 1:])
                except ValueError:
                    continue
                setattr(rows.setdefault(index, JobRow(index=index)), attribute, clean_value(value))

        return [rows[index] for index in sorted(rows)]

    # Subtrees worth reading. Everything else the printer exposes is static
    # configuration that never changes mid-run.
    ROOTS = ("1.3.6.1.2.1.25.3.5", "1.3.6.1.2.1.43.11.1.1", "1.3.6.1.4.1.10642.8")

    def _get_all(self) -> Dict[str, str]:
        """Read the printer, preferring pysnmp and falling back to net-snmp.

        The packaged Windows agent ships pysnmp. The CLI fallback exists so the
        agent also runs on a developer machine with net-snmp installed and no
        Python SNMP stack, which is how the OID map was worked out in the first
        place.
        """
        try:
            return self._get_all_pysnmp()
        except ImportError:
            return self._get_all_cli()

    def _get_all_cli(self) -> Dict[str, str]:
        import subprocess

        values: Dict[str, str] = {}

        for root in self.ROOTS:
            result = subprocess.run(
                ["snmpwalk", "-v2c", "-c", self.community, "-t", str(self.timeout),
                 "-r", "1", "-On", "-Oe", self.host, root],
                capture_output=True,
                text=True,
            )

            if result.returncode != 0:
                continue

            for line in result.stdout.splitlines():
                if " = " not in line:
                    continue
                oid, value = line.split(" = ", 1)
                # net-snmp prefixes the type, e.g. `STRING: "idle"`.
                if ":" in value:
                    value = value.split(":", 1)[1]
                values[oid.strip().lstrip(".")] = clean_value(value)

        return values

    def _get_all_pysnmp(self) -> Dict[str, str]:
        from pysnmp.hlapi import (  # type: ignore
            CommunityData, ContextData, ObjectIdentity, ObjectType, SnmpEngine,
            UdpTransportTarget, nextCmd,
        )

        values: Dict[str, str] = {}

        if self._engine is None:
            self._engine = SnmpEngine()

        # Walking the subtrees is cheaper than issuing one GET per OID and keeps
        # the job table complete regardless of how many rows it holds.
        for root in self.ROOTS:
            iterator = nextCmd(
                self._engine,
                CommunityData(self.community, mpModel=1),
                UdpTransportTarget((self.host, 161), timeout=self.timeout, retries=1),
                ContextData(),
                ObjectType(ObjectIdentity(root)),
                lexicographicMode=False,
            )

            for error_indication, error_status, _, var_binds in iterator:
                if error_indication or error_status:
                    break
                for name, value in var_binds:
                    values[str(name)] = self._format(name, value)

        return values

    @classmethod
    def _format(cls, name, value) -> str:
        """Hex-encode the error bitfield, stringify everything else."""
        if str(name) == OID_HR_ERROR_STATE:
            try:
                return value.asOctets().hex()
            except AttributeError:
                return clean_value(str(value))
        return clean_value(str(value))
