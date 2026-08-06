"""Condition classification, exercised without a printer attached.

The whole point of reading SNMP is to stop guessing, so the mapping from a raw
reading to a condition is the piece that most needs pinning down.
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import zebra  # noqa: E402


def reading(**kwargs) -> zebra.Reading:
    defaults = dict(reachable=True, printer_state="idle", supply_level=313, supply_max=627)
    defaults.update(kwargs)
    return zebra.Reading(**defaults)


class DecodeErrorBitsTest(unittest.TestCase):
    def test_healthy_bitfield_is_empty(self):
        self.assertEqual(zebra.decode_error_bits("00000000"), [])

    def test_spaced_hex_is_accepted(self):
        # net-snmp prints the field as "00 00 00 00".
        self.assertEqual(zebra.decode_error_bits("00 00 00 00"), [])

    def test_jam_bit(self):
        # bit 5, MSB first, is `jammed`.
        self.assertIn("jammed", zebra.decode_error_bits("04000000"))

    def test_door_open_bit(self):
        self.assertIn("doorOpen", zebra.decode_error_bits("08000000"))

    def test_garbage_does_not_raise(self):
        self.assertEqual(zebra.decode_error_bits("not hex"), [])


class ClassifyTest(unittest.TestCase):
    def test_idle_printer_is_ok(self):
        self.assertEqual(zebra.classify(reading()), zebra.OK)

    def test_printing_state(self):
        self.assertEqual(zebra.classify(reading(printer_state="printing")), zebra.PRINTING)

    def test_standby_is_a_healthy_idle_printer(self):
        # What a real ZXP Series 9 reports when powered and waiting for work.
        # Observed on hardware; before it was mapped, a healthy printer read as
        # `unknown` and the queue refused to start.
        result = zebra.classify(reading(printer_state="standby"))
        self.assertEqual(result, zebra.OK)
        self.assertFalse(zebra.is_stop(result))

    def test_unreachable_is_offline(self):
        self.assertEqual(zebra.classify(zebra.Reading(reachable=False)), zebra.OFFLINE)

    def test_jam_beats_everything(self):
        result = zebra.classify(reading(error_bits=["jammed", "lowToner"]))
        self.assertEqual(result, zebra.CARD_JAM)

    def test_cover_open(self):
        self.assertEqual(zebra.classify(reading(error_bits=["doorOpen"])), zebra.COVER_OPEN)

    def test_out_of_cards(self):
        self.assertEqual(zebra.classify(reading(error_bits=["inputTrayEmpty"])), zebra.CARDS_OUT)

    def test_exhausted_ribbon_is_a_stop(self):
        self.assertEqual(zebra.classify(reading(supply_level=0)), zebra.RIBBON_OUT)

    def test_low_ribbon_is_only_a_warning(self):
        result = zebra.classify(reading(supply_level=20), ribbon_warn_threshold=50)
        self.assertEqual(result, zebra.RIBBON_LOW)
        self.assertFalse(zebra.is_stop(result))

    def test_healthy_ribbon_level_is_ok(self):
        self.assertEqual(zebra.classify(reading(supply_level=313)), zebra.OK)

    def test_alarm_text_is_matched(self):
        self.assertEqual(zebra.classify(reading(alarms=["card_jam_at_flipper"])), zebra.CARD_JAM)

    def test_none_alarms_are_ignored(self):
        # "none" in all three slots is the healthy reading on real hardware.
        self.assertEqual(zebra.classify(reading(alarms=["none", "none", "none"])), zebra.OK)

    def test_unrecognised_alarm_is_a_stop(self):
        # Never wave through a fault string we have not seen. Assuming that
        # unknown means healthy is what let jammed cards count as printed.
        result = zebra.classify(reading(alarms=["some_new_firmware_fault"]))
        self.assertEqual(result, zebra.UNKNOWN)
        self.assertTrue(zebra.is_stop(result))

    def test_unrecognised_printer_state_is_a_stop(self):
        result = zebra.classify(reading(printer_state="reticulating_splines"))
        self.assertEqual(result, zebra.UNKNOWN)
        self.assertTrue(zebra.is_stop(result))

    def test_sensor_fault_is_read(self):
        self.assertEqual(zebra.classify(reading(sensor_fault="ribbon_out")), zebra.RIBBON_OUT)

    def test_stop_classification(self):
        for condition in (zebra.CARD_JAM, zebra.RIBBON_OUT, zebra.CARDS_OUT,
                          zebra.COVER_OPEN, zebra.OFFLINE, zebra.UNKNOWN):
            self.assertTrue(zebra.is_stop(condition), condition)

        for condition in (zebra.OK, zebra.PRINTING, zebra.RIBBON_LOW, zebra.CARDS_LOW):
            self.assertFalse(zebra.is_stop(condition), condition)


class JobTableTest(unittest.TestCase):
    """The firmware's own job table is what confirms a card actually finished."""

    def test_parses_the_rolling_job_window(self):
        # Shape taken from a live ZXP Series 9 mid-print.
        values = {
            "1.3.6.1.4.1.10642.8.5.1.2.1.1": "57",
            "1.3.6.1.4.1.10642.8.5.1.3.1.1": "473c9a8c-4c54-46b1-af86-ad3b76dfc6fd",
            "1.3.6.1.4.1.10642.8.5.1.4.1.1": "done_ok",
            "1.3.6.1.4.1.10642.8.5.1.5.1.1": "not_in_printer",
            "1.3.6.1.4.1.10642.8.5.1.2.1.2": "58",
            "1.3.6.1.4.1.10642.8.5.1.3.1.2": "cf60b800-5bbc-4766-936e-5358a82a3670",
            "1.3.6.1.4.1.10642.8.5.1.4.1.2": "cleaning_up",
            "1.3.6.1.4.1.10642.8.5.1.5.1.2": "transferring",
        }

        jobs = zebra.ZebraPoller._build_jobs(values)

        self.assertEqual(len(jobs), 2)
        self.assertEqual(jobs[-1].job_id, "58")
        self.assertTrue(jobs[0].is_done())
        self.assertTrue(jobs[-1].is_in_flight())
        self.assertFalse(jobs[-1].is_done())

    def test_finds_a_job_by_uuid(self):
        rows = [
            zebra.JobRow(index=1, uuid="aaa", state="done_ok"),
            zebra.JobRow(index=2, uuid="bbb", state="cleaning_up"),
        ]
        result = zebra.Reading(jobs=rows).job_by_uuid("bbb")
        self.assertIsNotNone(result)
        self.assertEqual(result.state, "cleaning_up")

    def test_terminal_state_that_is_not_done_counts_as_failed(self):
        self.assertTrue(zebra.JobRow(index=1, state="aborted").failed())
        self.assertFalse(zebra.JobRow(index=1, state="done_ok").failed())
        self.assertFalse(zebra.JobRow(index=1, state="cleaning_up").failed())
        # No state at all is not evidence of failure.
        self.assertFalse(zebra.JobRow(index=1, state=None).failed())


class BuildReadingTest(unittest.TestCase):
    def test_builds_a_reading_from_raw_oids(self):
        poller = zebra.ZebraPoller("10.0.0.92")

        result = poller._build({
            zebra.OID_ZEBRA_STATE: "idle",
            zebra.OID_HR_ERROR_STATE: "00000000",
            zebra.OID_SUPPLY_LEVEL: "313",
            zebra.OID_SUPPLY_MAX: "627",
            zebra.OID_SUPPLY_DESCRIPTION: "YMCK",
            zebra.OID_ZEBRA_ALARMS[0]: "none",
        })

        self.assertTrue(result.reachable)
        self.assertEqual(result.supply_level, 313)
        self.assertEqual(result.supply_max, 627)
        self.assertEqual(result.supply_description, "YMCK")
        self.assertEqual(zebra.classify(result), zebra.OK)

    def test_missing_supply_values_do_not_raise(self):
        result = zebra.ZebraPoller("10.0.0.92")._build({zebra.OID_ZEBRA_STATE: "idle"})
        self.assertIsNone(result.supply_level)

    def test_quoted_values_from_net_snmp_are_unwrapped(self):
        # Regression: net-snmp renders strings wrapped in double quotes. Left in
        # place they make every comparison in classify() miss, so a perfectly
        # healthy printer reads as `unknown` and the queue stops for good.
        result = zebra.ZebraPoller("10.0.0.92")._build({
            zebra.OID_ZEBRA_STATE: '"idle"',
            zebra.OID_ZEBRA_ALARMS[0]: '"none"',
            zebra.OID_SUPPLY_LEVEL: "313",
            "1.3.6.1.4.1.10642.8.5.1.4.1.1": '"done_ok"',
        })

        self.assertEqual(result.printer_state, "idle")
        self.assertEqual(zebra.classify(result), zebra.OK)
        self.assertTrue(result.jobs[0].is_done())

    def test_trailing_padding_is_stripped(self):
        # Zebra pads some fields, e.g. `"FZ9HG.01.11.09       "`.
        self.assertEqual(zebra.clean_value('"printing   "'), "printing")


if __name__ == "__main__":
    unittest.main()
