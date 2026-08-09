"""Condition classification, exercised without a printer attached.

The whole point of reading SNMP is to stop guessing, so the mapping from a raw
reading to a condition is the piece that most needs pinning down.
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import vocabulary, zebra  # noqa: E402


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

    def test_initializing_is_its_own_transient_condition(self):
        # Observed sequence on the real ZXP9 going into a job:
        # standby -> initializing -> printing_heating. Before this was mapped,
        # `initializing` read as unknown and stopped the queue.
        result = zebra.classify(reading(printer_state="initializing"))

        self.assertEqual(result, zebra.INITIALIZING)
        self.assertTrue(zebra.is_transient(result))

    def test_a_warming_printer_is_still_not_printed_onto(self):
        # Transient is not the same as ready. Sending a card mid-warmup is
        # exactly the sort of thing that produces a blank.
        self.assertTrue(zebra.is_stop(zebra.INITIALIZING))

    def test_printing_heating_is_a_card_in_progress(self):
        result = zebra.classify(reading(printer_state="printing_heating"))

        self.assertEqual(result, zebra.PRINTING)
        self.assertFalse(zebra.is_stop(result))

    def test_other_printing_phases_are_also_printing(self):
        # The firmware qualifies the word as it works; every printing_<phase>
        # means a card is on the way through.
        self.assertEqual(zebra.classify(reading(printer_state="printing_cleaning")),
                         zebra.PRINTING)

    def test_the_words_a_working_printer_uses_are_not_faults(self):
        # Observed sequence on the real unit going through a card:
        # standby -> initializing -> printing_heating -> feeding ->
        # transfer_wait. Reading any of them as unknown stopped the queue on a
        # printer that was working perfectly.
        for state in ("feeding", "transfer_wait", "transferring", "ejecting"):
            result = zebra.classify(reading(printer_state=state))

            self.assertEqual(result, zebra.PRINTING, state)
            self.assertFalse(zebra.is_stop(result), state)

    def test_a_busy_printer_is_still_not_sent_another_card(self):
        # PRINTING is not a stop, but it is not "ready" either: the agent
        # prints one card at a time and waits for it to land.
        self.assertNotIn(zebra.PRINTING, (zebra.OK,))

    def test_a_real_fault_is_not_transient(self):
        self.assertFalse(zebra.is_transient(zebra.CARD_JAM))
        self.assertFalse(zebra.is_transient(zebra.UNKNOWN))

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

    def test_an_empty_ribbon_is_a_stop_however_it_is_spelled(self):
        # Observed on the real ZXP9: "RIBBON EMPTY". It matched only the bare
        # "ribbon" catch-all and came out as a low ribbon, which is a warning
        # the queue prints straight through -- on a printer that cannot print.
        for text in ("RIBBON EMPTY", "ribbon_empty", "out of ribbon", "no ribbon"):
            result = zebra.classify(reading(alarms=[text]))

            self.assertEqual(result, zebra.RIBBON_OUT, text)
            self.assertTrue(zebra.is_stop(result), text)

    def test_an_empty_film_is_a_stop_however_it_is_spelled(self):
        for text in ("FILM EMPTY", "film_empty", "out of film", "no film"):
            result = zebra.classify(reading(alarms=[text]))

            self.assertEqual(result, zebra.FILM_OUT, text)
            self.assertTrue(zebra.is_stop(result), text)

    def test_a_low_ribbon_still_reads_as_low(self):
        # The catch-all underneath the exhausted spellings still has to work.
        self.assertEqual(zebra.classify(reading(alarms=["ribbon low"])), zebra.RIBBON_LOW)

    def test_more_spellings_of_a_stop(self):
        cases = [
            ("SERVICE REQUIRED", zebra.SERVICE_REQUIRED),
            ("mechanical error", zebra.SERVICE_REQUIRED),
            ("COVER OPEN", zebra.COVER_OPEN),
            ("head open", zebra.COVER_OPEN),
            ("lid_open", zebra.COVER_OPEN),
            ("feeder empty", zebra.CARDS_OUT),
            ("out of cards", zebra.CARDS_OUT),
            ("output full", zebra.REJECT_BIN_FULL),
        ]

        for text, expected in cases:
            self.assertEqual(zebra.classify(reading(alarms=[text])), expected, text)

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


class SupplyCounterTest(unittest.TestCase):
    """The printer counts ribbon, not cards.

    Measured on the real ZXP9 printing dual-sided badges: the counter falls by
    two per card. Reporting it raw told staff there were twice as many cards
    left as there really were.
    """

    def test_the_counter_is_converted_to_cards(self):
        self.assertEqual(zebra.cards_from_supply(240), 120)

    def test_a_partial_card_is_not_counted(self):
        # Round down. Promising a card the ribbon cannot finish is worse than
        # being one pessimistic.
        self.assertEqual(zebra.cards_from_supply(1), 0)

    def test_no_reading_is_not_zero_cards(self):
        # None means the printer did not say, which must not read as empty.
        self.assertIsNone(zebra.cards_from_supply(None))

    def test_an_empty_ribbon_is_zero(self):
        self.assertEqual(zebra.cards_from_supply(0), 0)


if __name__ == "__main__":
    unittest.main()


class FieldVocabularyTest(unittest.TestCase):
    """States the real ZXP9 produced that we had no word for.

    Every one of these was journalled as an unrecognised state, which stops the
    queue. Correct as a default, useless as an answer: two of them mean the
    printer is working normally.
    """

    def reading(self, state):
        return zebra.Reading(reachable=True, printer_state=state, alarms=[],
                             sensor_fault=None, error_bits=set(), supply_level=None)

    def test_heating_transfer_rollers_is_the_printer_warming_up(self):
        # WARMING on the front panel. A cold ZXP9 spends around ten minutes on
        # this and is not ready in that time, so it is not "printing".
        self.assertEqual(zebra.INITIALIZING,
                         zebra.classify(self.reading("xfer_rollers_heating")))

    def test_warming_is_waited_out_rather_than_calling_somebody(self):
        # The distinction that matters: classified as printing it paused the
        # batch and paged an operator to a printer that needed only patience.
        condition = zebra.classify(self.reading("xfer_rollers_heating"))

        self.assertTrue(zebra.is_transient(condition))

    def test_no_card_is_sent_to_a_warming_printer(self):
        self.assertTrue(zebra.is_stop(zebra.classify(self.reading("xfer_rollers_heating"))))

    def test_acknowledging_a_job_is_the_printer_working(self):
        self.assertEqual(zebra.PRINTING, zebra.classify(self.reading("receive_ok")))

    def test_acknowledging_a_job_does_not_stop_the_queue(self):
        self.assertFalse(zebra.is_stop(zebra.classify(self.reading("receive_ok"))))

    def test_a_cancel_at_the_panel_is_named_rather_than_unknown(self):
        self.assertEqual(zebra.CANCELLED_AT_PRINTER,
                         zebra.classify(self.reading("cancelled_by_user")))

    def test_a_cancel_at_the_panel_stops_the_queue(self):
        # The card does not exist and only a person knows what happened to it.
        self.assertTrue(zebra.is_stop(zebra.classify(self.reading("cancelled_by_user"))))

    def test_a_cancel_is_not_waited_out(self):
        # Waiting would never clear: nobody is coming unless we say so.
        self.assertFalse(zebra.is_transient(zebra.classify(self.reading("cancelled_by_user"))))

    def test_a_genuinely_new_word_is_still_unknown(self):
        # The fail-closed default has to survive this change.
        self.assertEqual(zebra.UNKNOWN, zebra.classify(self.reading("flux_capacitor_warming")))

    def test_the_known_ones_stop_filling_the_journal(self):
        for state in ("xfer_rollers_heating", "receive_ok", "cancelled_by_user"):
            found = vocabulary.unknown_strings(self.reading(state))
            self.assertEqual([], found, state)


class TransferFilmTest(unittest.TestCase):
    """Reading the transfer film, which nothing watched until now.

    The standard MIB publishes a second supply that looks like the film and is
    not: on an idle printer it gave 626, then 2, then 1, then 624, sometimes the
    ribbon's remaining count and sometimes its panel counter. Acting on it
    reported film_low on a healthy printer.

    The real figures are in Zebra's private media table, and the XML document at
    1.3.6.1.4.1.10642.8.12.0 names every column, so this is not guesswork.
    """

    def reading(self, ribbon=None, initial=None, used=None, junk=None):
        return zebra.Reading(reachable=True, printer_state="idle", alarms=[],
                             sensor_fault=None, error_bits=set(),
                             supply_level=ribbon, film_level=junk,
                             film_initial=initial, film_panel_used=used)

    def test_what_is_left_is_initial_minus_used(self):
        # Both counters count up as panels are consumed, measured mid-run.
        reading = self.reading(ribbon=592, initial=627, used=578)

        self.assertEqual(49, reading.film_panels_left())
        self.assertEqual(49, reading.film_cards_left())

    def test_a_spent_film_stops_the_queue(self):
        condition = zebra.classify(self.reading(ribbon=592, initial=627, used=627))

        self.assertEqual(zebra.FILM_OUT, condition)
        self.assertTrue(zebra.is_stop(condition))

    def test_a_low_film_only_warns(self):
        condition = zebra.classify(self.reading(ribbon=592, initial=627, used=600))

        self.assertEqual(zebra.FILM_LOW, condition)
        self.assertFalse(zebra.is_stop(condition))

    def test_film_is_named_ahead_of_the_ribbon(self):
        # Both low. Naming the ribbon sends somebody for the wrong box, and the
        # printer cannot print without film whatever the ribbon says.
        condition = zebra.classify(self.reading(ribbon=20, initial=627, used=600))

        self.assertEqual(zebra.FILM_LOW, condition)

    def test_a_healthy_film_leaves_the_ribbon_to_it(self):
        self.assertEqual(zebra.RIBBON_LOW,
                         zebra.classify(self.reading(ribbon=20, initial=627, used=10)))

    def test_a_printer_that_publishes_no_film_is_not_treated_as_empty(self):
        # Absent is not zero, or every printer without the private table stops.
        self.assertEqual(zebra.OK, zebra.classify(self.reading(ribbon=592)))

    def test_a_used_count_past_the_roll_does_not_go_negative(self):
        self.assertEqual(0, self.reading(initial=627, used=700).film_panels_left())

    def test_the_untrustworthy_supply_row_decides_nothing(self):
        # film_level is the standard MIB's supply 2, kept only for the journal.
        # A zero there must not stop a printer with film left.
        condition = zebra.classify(self.reading(ribbon=592, initial=627, used=10, junk=0))

        self.assertEqual(zebra.OK, condition)

    def test_the_figures_are_read_off_the_private_media_table(self):
        poller = zebra.ZebraPoller("printer.test")
        reading = poller._build({
            "1.3.6.1.2.1.43.11.1.1.9.1.1": "592",
            "1.3.6.1.4.1.10642.8.6.1.9.1": "627",
            "1.3.6.1.4.1.10642.8.6.1.10.1": "578",
        })

        self.assertEqual(627, reading.film_initial)
        self.assertEqual(578, reading.film_panel_used)
        self.assertEqual(49, reading.film_cards_left())
