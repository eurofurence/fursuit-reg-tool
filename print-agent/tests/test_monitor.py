"""The gate that decides whether the next card may be printed."""

import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import monitor, vocabulary, zebra  # noqa: E402


class FakePoller:
    """Stands in for the SNMP poller so the gate can be tested without hardware."""

    def __init__(self, *readings):
        self.readings = list(readings)
        self.last = None

    def read(self):
        if self.readings:
            self.last = self.readings.pop(0)
        return self.last


def reading(**kwargs) -> zebra.Reading:
    defaults = dict(reachable=True, printer_state="idle", supply_level=313, supply_max=627)
    defaults.update(kwargs)
    return zebra.Reading(**defaults)


class MonitorTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.journal = vocabulary.ConditionJournal(Path(self.dir.name) / "j.jsonl")

    def tearDown(self):
        self.dir.cleanup()

    def build(self, *readings) -> monitor.PrinterMonitor:
        return monitor.PrinterMonitor(FakePoller(*readings), self.journal)

    def test_healthy_printer_may_print(self):
        gate = self.build(reading())
        self.assertEqual(gate.poll(), zebra.OK)
        self.assertTrue(gate.may_print())
        self.assertIsNone(gate.blocking_reason())

    def test_a_jam_stops_printing(self):
        gate = self.build(reading(error_bits=["jammed"]))
        gate.poll()

        self.assertFalse(gate.may_print())
        self.assertTrue(gate.is_stop())
        self.assertIn("jam", gate.blocking_reason().lower())

    def test_an_unrecognised_alarm_stops_printing(self):
        # The whole point: we do not know what this means, so we do not print.
        gate = self.build(reading(alarms=["flipper_stall_17"]))
        gate.poll()

        self.assertFalse(gate.may_print())
        self.assertIn("flipper_stall_17", gate.blocking_reason())

    def test_offline_printer_stops_printing(self):
        gate = self.build(zebra.Reading(reachable=False))
        gate.poll()
        self.assertFalse(gate.may_print())

    def test_low_ribbon_warns_but_keeps_printing(self):
        gate = self.build(reading(supply_level=20))
        gate.poll()

        self.assertTrue(gate.may_print())
        self.assertIn("10 cards", gate.ribbon_warning())

    def test_empty_ribbon_stops_printing(self):
        gate = self.build(reading(supply_level=0))
        gate.poll()
        self.assertFalse(gate.may_print())

    def test_healthy_ribbon_produces_no_warning(self):
        gate = self.build(reading(supply_level=313))
        gate.poll()
        self.assertIsNone(gate.ribbon_warning())

    def test_mid_print_is_not_a_green_light(self):
        # One card at a time. Busy is not the same as ready.
        gate = self.build(reading(printer_state="printing"))
        gate.poll()
        self.assertFalse(gate.may_print())
        self.assertFalse(gate.is_stop())

    def test_change_callbacks_fire_only_on_transitions(self):
        gate = self.build(reading(), reading(), reading(error_bits=["jammed"]))
        seen = []
        gate.on_change.append(lambda condition, _: seen.append(condition))

        gate.poll()
        gate.poll()
        gate.poll()

        self.assertEqual(seen, [zebra.OK, zebra.CARD_JAM])

    def test_unknown_callback_fires_for_unlearned_strings(self):
        gate = self.build(reading(alarms=["flipper_stall_17"]))
        seen = []
        gate.on_unknown.append(lambda _: seen.append(True))

        gate.poll()

        self.assertEqual(len(seen), 1)

    def test_a_broken_callback_does_not_stop_the_printer(self):
        gate = self.build(reading())

        def explode(*_):
            raise RuntimeError("boom")

        gate.on_change.append(explode)

        try:
            gate.poll()
        except Exception as error:  # pragma: no cover
            self.fail("monitor raised: %s" % error)

        self.assertTrue(gate.may_print())

    def test_a_fault_is_journalled_for_later(self):
        gate = self.build(reading(alarms=["flipper_stall_17"]))
        gate.poll()

        self.assertEqual(self.journal.summary()["unknown"], 1)


if __name__ == "__main__":
    unittest.main()
