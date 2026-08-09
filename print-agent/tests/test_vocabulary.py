"""The fault-vocabulary journal.

Zebra publishes no MIB for the card printer line, so the agent learns the
firmware's fault strings by writing down the ones it cannot explain.
"""

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import vocabulary, zebra  # noqa: E402


def reading(**kwargs) -> zebra.Reading:
    defaults = dict(reachable=True, printer_state="idle", supply_level=313, supply_max=627)
    defaults.update(kwargs)
    return zebra.Reading(**defaults)


class UnknownStringsTest(unittest.TestCase):
    def test_healthy_reading_has_nothing_to_learn(self):
        result = vocabulary.unknown_strings(reading(alarms=["none", "none", "none"]))
        self.assertEqual(result, [])

    def test_flags_an_alarm_we_cannot_explain(self):
        result = vocabulary.unknown_strings(reading(alarms=["flipper_stall_17"]))
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["value"], "flipper_stall_17")
        self.assertEqual(result[0]["field"], "alarm_slot_1")

    def test_does_not_flag_an_alarm_we_already_map(self):
        self.assertEqual(vocabulary.unknown_strings(reading(alarms=["card_jam_at_flipper"])), [])

    def test_flags_an_unknown_printer_state(self):
        result = vocabulary.unknown_strings(reading(printer_state="calibrating"))
        self.assertEqual(result[0]["field"], "printer_state")

    def test_does_not_flag_a_fault_word_in_the_state_field(self):
        # The ZXP9 puts COVER OPEN and SERVICE REQUIRED in the state field as
        # well as in an alarm slot. Both are mapped, both stop the printer, and
        # both were being announced to the operator as states nobody understood.
        for state in ("COVER OPEN", "SERVICE REQUIRED", "RIBBON EMPTY"):
            self.assertEqual(vocabulary.unknown_strings(reading(printer_state=state)), [],
                             state)

    def test_flags_an_unknown_job_state(self):
        rows = [zebra.JobRow(index=1, state="rejected_bad_card")]
        result = vocabulary.unknown_strings(reading(jobs=rows))
        self.assertEqual(result[0]["value"], "rejected_bad_card")

    def test_ignores_known_job_states(self):
        rows = [zebra.JobRow(index=1, state="done_ok"), zebra.JobRow(index=2, state="cleaning_up")]
        self.assertEqual(vocabulary.unknown_strings(reading(jobs=rows)), [])


class ConditionJournalTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = Path(self.dir.name) / "journal.jsonl"
        self.journal = vocabulary.ConditionJournal(self.path)

    def tearDown(self):
        self.dir.cleanup()

    def entries(self):
        if not self.path.exists():
            return []
        with open(self.path) as handle:
            return [json.loads(line) for line in handle]

    def test_healthy_readings_are_not_logged(self):
        self.journal.record(reading(alarms=["none"]), zebra.OK)
        self.assertEqual(self.entries(), [])

    def test_logs_an_unknown_condition(self):
        self.assertTrue(self.journal.record(reading(alarms=["flipper_stall_17"]), zebra.UNKNOWN))

        entries = self.entries()
        self.assertEqual(len(entries), 1)
        self.assertEqual(entries[0]["condition"], zebra.UNKNOWN)
        self.assertTrue(entries[0]["is_stop"])
        self.assertEqual(entries[0]["unknown_strings"][0]["value"], "flipper_stall_17")

    def test_logs_a_recognised_stop_too(self):
        # A jam we already understand is still worth capturing in full, so the
        # complete shape of a real fault is on record.
        self.assertTrue(self.journal.record(reading(error_bits=["jammed"]), zebra.CARD_JAM))
        self.assertEqual(len(self.entries()), 1)

    def test_the_same_fault_is_only_written_once(self):
        state = reading(alarms=["flipper_stall_17"])

        self.assertTrue(self.journal.record(state, zebra.UNKNOWN))
        for _ in range(50):
            self.journal.record(state, zebra.UNKNOWN)

        # A jam lasting ten minutes must not produce hundreds of identical rows.
        self.assertEqual(len(self.entries()), 1)

    def test_a_different_fault_is_written_separately(self):
        self.journal.record(reading(alarms=["flipper_stall_17"]), zebra.UNKNOWN)
        self.journal.record(reading(alarms=["ribbon_tension_error"]), zebra.UNKNOWN)
        self.assertEqual(len(self.entries()), 2)

    def test_deduplication_survives_a_restart(self):
        state = reading(alarms=["flipper_stall_17"])
        self.journal.record(state, zebra.UNKNOWN)

        # Agent restarts, re-reads the journal, sees the same jam still present.
        reopened = vocabulary.ConditionJournal(self.path)
        self.assertFalse(reopened.record(state, zebra.UNKNOWN))
        self.assertEqual(len(self.entries()), 1)

    def test_keeps_the_raw_walk_for_later(self):
        state = reading(alarms=["flipper_stall_17"], raw={"1.2.3": "something"})
        self.journal.record(state, zebra.UNKNOWN)
        self.assertEqual(self.entries()[0]["raw"], {"1.2.3": "something"})

    def test_summary_counts_what_is_worth_sending(self):
        self.journal.record(reading(alarms=["flipper_stall_17"]), zebra.UNKNOWN)
        self.journal.record(reading(error_bits=["jammed"]), zebra.CARD_JAM)

        summary = self.journal.summary()
        self.assertEqual(summary["entries"], 2)
        self.assertEqual(summary["unknown"], 1)
        self.assertEqual(summary["stops"], 2)

    def test_an_unwritable_journal_does_not_raise(self):
        # Diagnostics must never be able to stop the printer.
        journal = vocabulary.ConditionJournal(Path("/nonexistent-root/x/journal.jsonl"))
        try:
            journal.record(reading(alarms=["boom"]), zebra.UNKNOWN)
        except Exception as error:  # pragma: no cover
            self.fail("journal raised: %s" % error)


class WarmupVocabularyTest(unittest.TestCase):
    """The warm-up words are known, so they stop filling the journal."""

    def test_initializing_is_not_reported_as_unknown(self):
        found = vocabulary.unknown_strings(zebra.Reading(printer_state="initializing"))

        self.assertEqual([f["value"] for f in found], [])

    def test_printing_heating_is_not_reported_as_unknown(self):
        found = vocabulary.unknown_strings(zebra.Reading(printer_state="printing_heating"))

        self.assertEqual([f["value"] for f in found], [])

    def test_a_genuinely_new_word_is_still_reported(self):
        found = vocabulary.unknown_strings(zebra.Reading(printer_state="flibberting"))

        self.assertEqual([f["value"] for f in found], ["flibberting"])


if __name__ == "__main__":
    unittest.main()
