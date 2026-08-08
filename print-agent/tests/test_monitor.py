"""The gate that decides whether the next card may be printed."""

import os
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import monitor, vocabulary, zebra  # noqa: E402


class FakePoller:
    """Stands in for the SNMP poller so the gate can be tested without hardware."""

    def __init__(self, *readings):
        self.readings = list(readings)
        self.last = None
        self.reads = 0

    def read(self):
        self.reads += 1

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


class ReadingCacheTest(unittest.TestCase):
    """Two callers, one reading.

    Both the UI loop and the print worker poll this monitor, and every reading
    is three SNMP subtree walks including the whole job table. Taking one each
    made the confirm step crawl.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.poller = FakePoller(reading(), reading())
        self.subject = monitor.PrinterMonitor(
            self.poller, vocabulary.ConditionJournal(Path(self.dir.name) / "j.jsonl"))

    def tearDown(self):
        self.dir.cleanup()

    def test_a_recent_reading_is_reused(self):
        self.subject.poll()
        self.subject.poll(max_age=60.0)

        self.assertEqual(self.poller.reads, 1)

    def test_a_stale_reading_is_replaced(self):
        self.subject.poll()
        self.subject.poll(max_age=0.0)

        self.assertEqual(self.poller.reads, 2)

    def test_the_first_poll_always_reads(self):
        # No reading yet, so max_age has nothing to reuse.
        self.subject.poll(max_age=60.0)

        self.assertEqual(self.poller.reads, 1)


class OfflineDebounceTest(unittest.TestCase):
    """One lost SNMP packet is not an offline printer.

    SNMP is UDP with a single retry, and a read is most likely to be dropped
    exactly when the printer is busy printing. Believing the first failure
    raised "printer offline" and stopped the queue on a healthy machine.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.dir.cleanup()

    def monitor_for(self, *readings, confirmations=3):
        return monitor.PrinterMonitor(
            FakePoller(*readings),
            vocabulary.ConditionJournal(Path(self.dir.name) / "j.jsonl"),
            offline_confirmations=confirmations,
        )

    def test_a_single_missed_read_is_ignored(self):
        subject = self.monitor_for(reading(), zebra.Reading(reachable=False), reading())

        self.assertEqual(subject.poll(), zebra.OK)
        self.assertEqual(subject.poll(), zebra.OK, "one blip must not read as offline")
        self.assertEqual(subject.poll(), zebra.OK)

    def test_a_printer_that_stays_silent_is_offline(self):
        # The debounce delays the verdict, it does not suppress it.
        down = zebra.Reading(reachable=False)
        subject = self.monitor_for(reading(), down, down, down)

        subject.poll()
        subject.poll()
        subject.poll()

        self.assertEqual(subject.poll(), zebra.OFFLINE)

    def test_the_streak_resets_when_the_printer_answers(self):
        down = zebra.Reading(reachable=False)
        subject = self.monitor_for(reading(), down, down, reading(), down, down)

        for _ in range(6):
            subject.poll()

        self.assertEqual(subject.condition, zebra.OK)

    def test_a_blip_raises_no_change_callback(self):
        # A blip that resolves should leave no trace: no alert, no POS update.
        changes = []
        subject = self.monitor_for(reading(), zebra.Reading(reachable=False), reading())
        subject.on_change.append(lambda condition, _r: changes.append(condition))

        subject.poll()
        subject.poll()
        subject.poll()

        self.assertEqual(changes, [zebra.OK])


class ConcurrentPollTest(unittest.TestCase):
    """Two threads poll this monitor, and they must not collide.

    The regression: a shared pysnmp SnmpEngine was introduced to speed up the
    confirm step, but the engine is not thread-safe and both the UI loop and
    the print worker drive it. Concurrent walks threw, the exception became
    `reachable=False`, and the printer "went offline" in the middle of a card
    it was printing perfectly well.
    """

    class SlowPoller:
        """Fails if two reads overlap, the way a real SNMP engine would."""

        def __init__(self):
            self.reads = 0
            self.overlaps = 0
            self._inside = False

        def read(self):
            if self._inside:
                self.overlaps += 1
            self._inside = True
            time.sleep(0.005)
            self.reads += 1
            self._inside = False

            return reading()

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.dir.cleanup()

    def test_reads_do_not_overlap(self):
        poller = self.SlowPoller()
        subject = monitor.PrinterMonitor(
            poller, vocabulary.ConditionJournal(Path(self.dir.name) / "j.jsonl"))

        threads = [threading.Thread(target=subject.poll) for _ in range(8)]

        for t in threads:
            t.start()
        for t in threads:
            t.join()

        self.assertEqual(poller.overlaps, 0, "two threads read the printer at once")
        self.assertEqual(subject.condition, zebra.OK)

    def test_the_condition_survives_concurrent_polling(self):
        poller = self.SlowPoller()
        subject = monitor.PrinterMonitor(
            poller, vocabulary.ConditionJournal(Path(self.dir.name) / "j.jsonl"))
        seen = []

        def poll_many():
            for _ in range(5):
                seen.append(subject.poll())

        threads = [threading.Thread(target=poll_many) for _ in range(4)]

        for t in threads:
            t.start()
        for t in threads:
            t.join()

        self.assertEqual(set(seen), {zebra.OK}, seen)


if __name__ == "__main__":
    unittest.main()
