"""When an idle unattended station should go looking for work.

Unattended used to be able to continue a run but never begin one: the worker
is built by the Start button, so on a freshly opened agent ticking the box
changed a flag nothing was left to read. A station restarted overnight sat idle
with the box ticked and batches waiting.

The policy is a plain function because the rest of the UI module needs a
display, and this is the part with the decision in it.
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from agent.autostart import should_autostart, should_poll_batches  # noqa: E402


def decision(**overrides):
    settings = dict(
        unattended=True,
        demo=False,
        worker_running=False,
        configured=True,
        has_printer=True,
        printer_ready=True,
        since_last=99.0,
    )
    settings.update(overrides)

    return should_autostart(**settings)


class ShouldAutostartTest(unittest.TestCase):
    def test_an_idle_unattended_station_looks_for_work(self):
        self.assertTrue(decision())

    def test_attended_never_starts_by_itself(self):
        # Picking a batch by hand means printing the wrong hundred cards if it
        # is the wrong one, so an operator chooses.
        self.assertFalse(decision(unattended=False))

    def test_a_running_worker_is_left_alone(self):
        self.assertFalse(decision(worker_running=True))

    def test_an_unconfigured_agent_does_not_try(self):
        self.assertFalse(decision(configured=False))

    def test_no_card_printer_means_nothing_to_start(self):
        self.assertFalse(decision(has_printer=False))

    def test_a_faulted_printer_is_not_started_onto(self):
        # It would fail the first card and pause, which is a worse place to be
        # than simply waiting for somebody to clear the fault.
        self.assertFalse(decision(printer_ready=False))

    def test_the_hunt_is_throttled(self):
        # Otherwise a start that does not take is retried on every UI tick.
        self.assertFalse(decision(since_last=0.5))

    def test_it_tries_again_once_the_interval_passes(self):
        self.assertTrue(decision(since_last=5.0))

    def test_demo_mode_never_reaches_the_network(self):
        self.assertFalse(decision(demo=True))


class ShouldPollBatchesTest(unittest.TestCase):
    """The background listing that feeds both the chooser and the hunt."""

    def test_an_idle_configured_station_polls(self):
        # The whole point: a batch built in the admin panel is noticed without
        # anybody walking over and opening the chooser.
        self.assertTrue(should_poll_batches(
            configured=True, demo=False, printing_now=False))

    def test_it_stays_off_the_wire_mid_run(self):
        # The worker is already claiming card by card, and nothing is waiting
        # on a refreshed listing until that run ends.
        self.assertFalse(should_poll_batches(
            configured=True, demo=False, printing_now=True))

    def test_an_unconfigured_agent_does_not_poll(self):
        self.assertFalse(should_poll_batches(
            configured=False, demo=False, printing_now=False))

    def test_demo_mode_never_reaches_the_network(self):
        self.assertFalse(should_poll_batches(
            configured=True, demo=True, printing_now=False))


if __name__ == "__main__":
    unittest.main()
