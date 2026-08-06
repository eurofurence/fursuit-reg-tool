"""Pushover alerts, and the two rules that keep them useful.

The printer sits on a private LAN behind the agent. When it jams at 2am the
agent is the only piece of software in the world that knows, and the operator
may be at the other end of the hall.

**Alerting must never be able to stop printing.** A notification is a courtesy,
a card is the product. Every test in FailureTest is there because a notifier
that can raise into the print loop would be a new way to lose badges, which is
the exact failure mode this whole rework exists to remove.

**One alert per fault, not one per poll.** The monitor polls every few seconds.
Without the cooldown a single jam sends several hundred notifications, staff
mute the app, and the next real alert goes unread. The cooldown is per key, so
a suppressed jam on printer A must never silence an empty ribbon on printer B.

Nothing here reaches api.pushover.net: the transport is injected, and the clock
with it, so the cooldown can be tested without waiting five minutes.
"""

import io
import os
import sys
import unittest
import urllib.error
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import notify  # noqa: E402
from agent.config import PushoverConfig  # noqa: E402


class FakeResponse(io.BytesIO):
    def close(self):
        io.BytesIO.close(self)


class FakeOpener:
    """Stands in for urllib's opener. Records requests, or raises on demand."""

    def __init__(self, body=b'{"status": 1}', raises=None):
        self.body = body
        self.raises = raises
        self.requests = []

    def open(self, request, timeout=None):
        self.requests.append(request)

        if self.raises is not None:
            raise self.raises

        return FakeResponse(self.body)

    @property
    def calls(self) -> int:
        return len(self.requests)

    @property
    def last_payload(self) -> dict:
        raw = urllib.parse.parse_qs(self.requests[-1].data.decode("utf-8"))
        return {key: values[0] for key, values in raw.items()}


class Clock:
    """A hand-wound clock, so a five minute cooldown takes no time to test."""

    def __init__(self, now=1000.0):
        self.now = now

    def __call__(self) -> float:
        return self.now

    def advance(self, seconds: float) -> None:
        self.now += seconds


def enabled_config(**overrides) -> PushoverConfig:
    settings = dict(
        enabled=True, user_key="user-key", api_token="app-token", cooldown_seconds=300
    )
    settings.update(overrides)
    return PushoverConfig(**settings)


def build(config=None, opener=None, clock=None):
    opener = opener if opener is not None else FakeOpener()
    clock = clock if clock is not None else Clock()
    notifier = notify.Notifier(
        config if config is not None else enabled_config(), opener=opener, clock=clock
    )

    return notifier, opener, clock


class ConfigurationTest(unittest.TestCase):
    def test_a_disabled_channel_never_touches_the_network(self):
        notifier, opener, _ = build(enabled_config(enabled=False))

        self.assertFalse(notifier.alert("printer:jam", "Jam", "Card stuck"))
        self.assertEqual(opener.calls, 0)

    def test_a_half_filled_form_is_not_an_alerting_channel(self):
        # Worse than no alerting: staff believe someone is watching.
        for config in (
            enabled_config(user_key=""),
            enabled_config(api_token=""),
            enabled_config(user_key="", api_token=""),
        ):
            notifier, opener, _ = build(config)

            self.assertFalse(notifier.is_configured())
            self.assertFalse(notifier.alert("k", "T", "M"))
            self.assertEqual(opener.calls, 0)

    def test_a_complete_config_is_usable(self):
        notifier, _, _ = build()

        self.assertTrue(notifier.is_configured())

    def test_alerting_is_off_until_the_operator_turns_it_on(self):
        # The default config ships disabled; nobody's phone should buzz because
        # they installed the agent.
        notifier, opener, _ = build(PushoverConfig())

        self.assertFalse(notifier.alert("k", "T", "M"))
        self.assertEqual(opener.calls, 0)


class SendTest(unittest.TestCase):
    def test_an_alert_posts_to_pushover(self):
        notifier, opener, _ = build()

        self.assertTrue(notifier.alert("printer:jam", "Jam", "Card stuck in flipper"))
        self.assertEqual(opener.requests[-1].full_url, notify.PUSHOVER_URL)
        self.assertEqual(opener.requests[-1].get_method(), "POST")

    def test_the_body_is_form_encoded_as_the_pushover_api_expects(self):
        notifier, opener, _ = build()
        notifier.alert("printer:jam", "Jam", "Card stuck in flipper")

        self.assertEqual(
            opener.requests[-1].get_header("Content-type"),
            "application/x-www-form-urlencoded",
        )

    def test_the_payload_carries_the_credentials_and_the_text(self):
        notifier, opener, _ = build()
        notifier.alert("printer:jam", "Jam", "Card stuck in flipper")

        self.assertEqual(opener.last_payload, {
            "token": "app-token",
            "user": "user-key",
            "title": "Jam",
            "message": "Card stuck in flipper",
            "priority": "0",
        })

    def test_a_quiet_priority_travels_as_minus_one(self):
        # "Ribbon getting low" should not wake anybody up.
        notifier, opener, _ = build()
        notifier.alert("ribbon", "Ribbon low", "48 cards left", notify.PRIORITY_QUIET)

        self.assertEqual(opener.last_payload["priority"], "-1")

    def test_a_high_priority_travels_as_one(self):
        # A stopped printer is worth bypassing the operator's quiet hours for.
        notifier, opener, _ = build()
        notifier.alert("jam", "Jam", "Card stuck", notify.PRIORITY_HIGH)

        self.assertEqual(opener.last_payload["priority"], "1")

    def test_pushover_rejecting_the_message_reports_failure(self):
        # status 0 with a 200 is how Pushover says the token is wrong.
        notifier, _, _ = build(opener=FakeOpener(body=b'{"status": 0, "errors": ["bad token"]}'))

        self.assertFalse(notifier.alert("k", "T", "M"))

    def test_an_unrecognised_body_counts_as_delivered(self):
        # We got a 200. Treating a surprise response shape as a failure would
        # only fill the log with noise nobody can act on.
        for body in (b"", b"<html>ok</html>", b"[1, 2]"):
            notifier, _, _ = build(opener=FakeOpener(body=body))

            self.assertTrue(notifier.alert("k", "T", "M"))


class CooldownTest(unittest.TestCase):
    def test_the_same_fault_alerts_once_per_window(self):
        # The monitor re-reports a jam every poll. Without this, one jam is
        # several hundred notifications and staff mute the app.
        notifier, opener, _ = build()

        self.assertTrue(notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck"))

        for _ in range(50):
            self.assertFalse(notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck"))

        self.assertEqual(opener.calls, 1)

    def test_a_different_fault_still_gets_through(self):
        # A suppressed jam on one printer must never silence an empty ribbon on
        # another; they need two different people doing two different things.
        notifier, opener, _ = build()

        self.assertTrue(notifier.alert("printer:ZXP-left:card_jam", "Jam", "left"))
        self.assertTrue(notifier.alert("printer:ZXP-right:out_of_cards", "Empty", "right"))
        self.assertEqual(opener.calls, 2)

    def test_the_cooldown_expires(self):
        notifier, opener, clock = build()
        notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck")

        clock.advance(299)
        self.assertFalse(notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck"))

        clock.advance(2)
        self.assertTrue(notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck"))
        self.assertEqual(opener.calls, 2)

    def test_the_window_restarts_from_the_most_recent_send(self):
        notifier, _, clock = build()
        notifier.alert("k", "T", "M")

        clock.advance(301)
        notifier.alert("k", "T", "M")

        clock.advance(10)
        self.assertFalse(notifier.alert("k", "T", "M"))

    def test_a_zero_cooldown_lets_everything_through(self):
        # An operator who wants every poll reported should get every poll.
        notifier, opener, _ = build(enabled_config(cooldown_seconds=0))

        for _ in range(3):
            self.assertTrue(notifier.alert("k", "T", "M"))

        self.assertEqual(opener.calls, 3)

    def test_a_nonsense_cooldown_does_not_break_alerting(self):
        # The value comes out of a JSON file the operator may have edited.
        notifier, opener, _ = build(enabled_config(cooldown_seconds=-5))

        self.assertTrue(notifier.alert("k", "T", "M"))
        self.assertTrue(notifier.alert("k", "T", "M"))
        self.assertEqual(opener.calls, 2)

    def test_clearing_a_key_lets_the_next_occurrence_through_at_once(self):
        # A jam cleared in thirty seconds and re-jammed a minute later is
        # exactly the moment staff most need telling.
        notifier, opener, _ = build()
        notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck")

        notifier.clear("printer:ZXP:card_jam")

        self.assertTrue(notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck"))
        self.assertEqual(opener.calls, 2)

    def test_clearing_a_key_that_never_fired_is_harmless(self):
        notifier, _, _ = build()
        notifier.clear("never:alerted")

    def test_reset_forgets_every_key(self):
        notifier, opener, _ = build()
        notifier.alert("a", "T", "M")
        notifier.alert("b", "T", "M")

        notifier.reset()

        self.assertTrue(notifier.alert("a", "T", "M"))
        self.assertTrue(notifier.alert("b", "T", "M"))
        self.assertEqual(opener.calls, 4)


class FailureTest(unittest.TestCase):
    """A failed notification must never stop a card from printing.

    Each of these asserts the same thing from a different angle: the caller
    gets False, not an exception. assertLogs doubles as a check that the
    operator at least gets a log line out of it.
    """

    def assert_swallowed(self, opener):
        notifier, _, _ = build(opener=opener)

        with self.assertLogs("agent.notify", "WARNING"):
            result = notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck")

        self.assertFalse(result)

    def test_an_unreachable_pushover_is_swallowed(self):
        # The venue network being down is precisely when a printer alert
        # matters, and precisely when the alert cannot be sent.
        self.assert_swallowed(FakeOpener(raises=urllib.error.URLError("no route to host")))

    def test_a_dns_or_tls_failure_is_swallowed(self):
        self.assert_swallowed(FakeOpener(raises=OSError("certificate verify failed")))

    def test_a_socket_timeout_is_swallowed(self):
        self.assert_swallowed(FakeOpener(raises=urllib.error.URLError("timed out")))

    def test_a_rejected_token_is_swallowed(self):
        self.assert_swallowed(FakeOpener(raises=urllib.error.HTTPError(
            notify.PUSHOVER_URL, 400, "Bad Request", {}, io.BytesIO(b'{"status": 0}')
        )))

    def test_a_pushover_outage_is_swallowed(self):
        self.assert_swallowed(FakeOpener(raises=urllib.error.HTTPError(
            notify.PUSHOVER_URL, 503, "Service Unavailable", {}, io.BytesIO(b"")
        )))

    def test_an_entirely_unexpected_error_is_still_swallowed(self):
        # The blanket catch is deliberate: nothing in this module is worth
        # taking the print loop down for.
        self.assert_swallowed(FakeOpener(raises=RuntimeError("something new")))

    def test_a_broken_response_object_does_not_escape(self):
        class Unreadable:
            def read(self):
                raise IOError("connection reset")

            def close(self):
                pass

        class BrokenOpener:
            def open(self, request, timeout=None):
                return Unreadable()

        notifier, _, _ = build(opener=BrokenOpener())

        with self.assertLogs("agent.notify", "WARNING"):
            self.assertFalse(notifier.alert("k", "T", "M"))

    def test_a_failed_send_does_not_stop_later_alerts_for_other_faults(self):
        notifier, _, _ = build(opener=FakeOpener(raises=urllib.error.URLError("down")))

        with self.assertLogs("agent.notify", "WARNING"):
            notifier.alert("printer:ZXP:card_jam", "Jam", "Card stuck")
            self.assertFalse(notifier.alert("printer:ZXP:out_of_cards", "Empty", "Refill"))


class PrinterStoppedTest(unittest.TestCase):
    def test_it_names_the_printer_in_the_title(self):
        # Two card printers per station: "a printer stopped" sends staff to the
        # wrong machine.
        notifier, opener, _ = build()
        notifier.printer_stopped("ZXP Series 9 left", "card_jam", "Open the lid and pull the card")

        self.assertEqual(opener.last_payload["title"], "ZXP Series 9 left stopped")

    def test_it_says_what_broke_and_what_to_do(self):
        notifier, opener, _ = build()
        notifier.printer_stopped("ZXP Series 9", "card_jam", "Open the lid and pull the card")

        message = opener.last_payload["message"]

        self.assertIn("card jam", message)
        self.assertIn("Open the lid and pull the card", message)

    def test_the_condition_is_readable_rather_than_an_enum_name(self):
        # Staff read this on a phone at 2am; "out_of_cards" reads as a bug.
        notifier, opener, _ = build()
        notifier.printer_stopped("ZXP Series 9", "out_of_cards")

        self.assertEqual(opener.last_payload["message"], "out of cards")

    def test_a_fault_with_no_known_remedy_still_gets_reported(self):
        notifier, opener, _ = build()
        notifier.printer_stopped("ZXP Series 9", "")

        self.assertEqual(opener.last_payload["message"], "unknown fault")

    def test_it_bypasses_quiet_hours_by_default(self):
        # A stopped printer holds up the whole queue.
        notifier, opener, _ = build()
        notifier.printer_stopped("ZXP Series 9", "card_jam")

        self.assertEqual(opener.last_payload["priority"], str(notify.PRIORITY_HIGH))

    def test_the_same_fault_on_the_same_printer_alerts_once(self):
        notifier, opener, _ = build()

        self.assertTrue(notifier.printer_stopped("ZXP Series 9", "card_jam"))
        self.assertFalse(notifier.printer_stopped("ZXP Series 9", "card_jam"))
        self.assertEqual(opener.calls, 1)

    def test_two_different_faults_on_one_printer_are_two_alerts(self):
        # A jam and a later out-of-cards need two different fixes; collapsing
        # them sends staff to do the wrong thing.
        notifier, opener, _ = build()

        self.assertTrue(notifier.printer_stopped("ZXP Series 9", "card_jam"))
        self.assertTrue(notifier.printer_stopped("ZXP Series 9", "out_of_cards"))
        self.assertEqual(opener.calls, 2)

    def test_the_same_fault_on_two_printers_are_two_alerts(self):
        notifier, opener, _ = build()

        self.assertTrue(notifier.printer_stopped("ZXP left", "card_jam"))
        self.assertTrue(notifier.printer_stopped("ZXP right", "card_jam"))
        self.assertEqual(opener.calls, 2)

    def test_an_unnamed_printer_still_produces_a_sensible_title(self):
        notifier, opener, _ = build()
        notifier.printer_stopped("", "card_jam")

        self.assertEqual(opener.last_payload["title"], "Printer stopped")

    def test_a_disabled_channel_makes_it_a_no_op(self):
        notifier, opener, _ = build(enabled_config(enabled=False))

        self.assertFalse(notifier.printer_stopped("ZXP Series 9", "card_jam"))
        self.assertEqual(opener.calls, 0)


if __name__ == "__main__":
    unittest.main()
