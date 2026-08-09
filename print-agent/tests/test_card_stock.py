"""Counting the blank cards, and warning before they run out.

The printer only reports empty once it already is, which strands a run
mid-batch with somebody walking to the supply cupboard. Counting down from a
figure the operator entered turns that into a refill nobody had to rush.
"""

import unittest

import agent.config as cfg
import agent.notify as notify
import agent.telegram as telegram_module
import agent.worker as worker

from test_worker import FakeNotifier, WorkerTestCase, job


class CardStockStoreTest(WorkerTestCase):
    """The count itself, which lives in the local store."""

    def test_an_unset_count_is_not_zero(self):
        # None is "nobody is counting", 0 is "counted, and empty". Confusing
        # the two warns every station that never set a figure.
        self.assertIsNone(self.store.card_stock())

    def test_taking_a_card_from_an_untracked_hopper_does_nothing(self):
        self.assertIsNone(self.store.take_card())

    def test_it_counts_down(self):
        self.store.set_card_stock(100)

        self.assertEqual(99, self.store.take_card())

    def test_it_stops_at_empty_rather_than_going_negative(self):
        self.store.set_card_stock(0)

        self.assertEqual(0, self.store.take_card())

    def test_the_count_survives_being_set_back(self):
        self.store.set_card_stock(100)
        self.store.set_card_stock(40)

        self.assertEqual(40, self.store.card_stock())


class CountingDownTest(WorkerTestCase):
    """What the worker does as cards leave the hopper."""

    def loaded(self, remaining, **kwargs):
        self.store.set_card_stock(remaining)

        options = dict(count_cards=True, low_card_threshold=10)
        options.update(kwargs)

        return self.build(**options)

    def test_a_printed_card_comes_off_the_count(self):
        w = self.loaded(50)

        w._take_card()

        self.assertEqual(49, self.store.card_stock())

    def test_it_leaves_the_count_alone_when_counting_is_off(self):
        w = self.loaded(50, count_cards=False)

        w._take_card()

        self.assertEqual(50, self.store.card_stock())

    def test_a_full_hopper_raises_nothing(self):
        w = self.loaded(50)

        w._take_card()

        self.assertEqual([], self.notifier.alerts)

    def test_it_warns_at_the_threshold(self):
        w = self.loaded(11)

        w._take_card()

        self.assertTrue(any("cards-low" in key for key, _t, _m in self.notifier.alerts))

    def test_the_warning_reaches_a_phone(self):
        # This is the whole point: somebody has to walk to the cupboard.
        w = self.loaded(11)

        w._take_card()

        self.assertTrue(any("cards-low" in key for key in self.notifier.urgent))

    def test_it_warns_again_as_the_stack_shrinks(self):
        # Keyed per level on purpose. One alert at ten and silence down to zero
        # is a warning nobody acts on, because it looks like it already passed.
        w = self.loaded(11)

        w._take_card()
        w._take_card()
        w._take_card()

        keys = {key for key, _t, _m in self.notifier.alerts}
        self.assertEqual(3, len(keys))

    def test_running_out_says_so_plainly(self):
        w = self.loaded(1)

        w._take_card()

        titles = [title for _k, title, _m in self.notifier.alerts]
        self.assertTrue(any("out of blanks" in t for t in titles))

    def test_it_reports_the_new_count_to_the_console(self):
        seen = []
        w = self.loaded(50, on_stock=seen.append)

        w._take_card()

        self.assertEqual([49], seen)

    def test_an_unreadable_store_never_stops_the_printer(self):
        class Broken:
            def take_card(self):
                raise RuntimeError("disk gone")

            def __getattr__(self, name):
                raise AttributeError(name)

        w = self.build(count_cards=True)
        w.store = Broken()

        w._take_card()  # must not raise


class PushoverIsForStoppingTest(WorkerTestCase):
    """Which alerts reach a phone.

    Pushover buzzing for every blank card in a run of four hundred is a
    Pushover that gets silenced, and then it is silent for the jam too.
    """

    def test_a_stopped_printer_reaches_the_phone(self):
        w = self.build()

        w._alert("printer:x:stopped", "Printer stopped", "out of ribbon")

        self.assertIn("printer:x:stopped", self.notifier.urgent)

    def test_a_single_blank_card_does_not(self):
        w = self.build()

        w._alert("blank:x:1", "Card came out blank", "reprinting",
                 stops_printing=False)

        self.assertEqual([], self.notifier.urgent)
        # It still gets recorded, for the chat.
        self.assertEqual(1, len(self.notifier.alerts))

    def test_the_pushover_client_enforces_it_too(self):
        # Not only the worker: anything holding a Notifier gets the same rule,
        # because the flag is what keeps the channel worth reading.
        sent = []
        notifier = notify.Notifier(
            cfg.PushoverConfig(enabled=True, user_key="u", api_token="t"))
        notifier._send = lambda title, message, priority: sent.append(title) or True

        notifier.alert("a", "Quiet", "body", stops_printing=False)
        notifier.alert("b", "Loud", "body")

        self.assertEqual(["Loud"], sent)


class RelayTest(unittest.TestCase):
    """The chat gets everything; the phone gets the urgent ones."""

    class FakeChannel:
        def __init__(self):
            self.messages = []

        def is_configured(self):
            return True

        def send_message(self, text, buttons=True, **kwargs):
            self.messages.append(text)
            return True

    def test_the_chat_gets_a_non_urgent_alert(self):
        channel = self.FakeChannel()
        inner = FakeNotifier()
        relay = telegram_module.AlertRelay(channel, inner)

        relay.alert("blank:1", "Card blank", "reprinting", stops_printing=False)

        self.assertEqual(1, len(channel.messages))
        self.assertEqual([], inner.alerts)

    def test_both_get_an_urgent_one(self):
        channel = self.FakeChannel()
        inner = FakeNotifier()
        relay = telegram_module.AlertRelay(channel, inner)

        relay.alert("printer:stopped", "Printer stopped", "jam")

        self.assertEqual(1, len(channel.messages))
        self.assertEqual(1, len(inner.alerts))


if __name__ == "__main__":
    unittest.main()


class EndToEndTest(WorkerTestCase):
    """The count follows real printing, not only the helper that adjusts it."""

    def test_printing_a_card_takes_one_off_the_hopper(self):
        self.store.set_card_stock(40)
        w = self.build(count_cards=True)

        self.api.queue.append(job(11, "24-0031"))
        w.print_next()

        self.assertEqual(39, self.store.card_stock())
