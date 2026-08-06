"""The Telegram channel, exercised with no network.

Two things are load-bearing here and both are easy to get subtly wrong:

* **The update offset.** Telegram replays every update until it is
  acknowledged. Get this wrong and one press of Pause pauses the printer
  forever, on every poll, and nobody can work out why it will not resume.
* **Failing quietly.** A convention uplink drops. Telegram has outages. None of
  that may reach the print loop, so every entry point swallows and returns.
"""

import json
import os
import ssl
import urllib.error
import urllib.parse
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import telegram  # noqa: E402
from agent.config import TelegramConfig  # noqa: E402


class Response:
    def __init__(self, payload, status=200):
        self._body = json.dumps(payload).encode("utf-8")
        self.status = status
        self.closed = False

    def read(self):
        return self._body

    def close(self):
        self.closed = True


class Opener:
    """Stands in for urllib's opener and records what was sent."""

    def __init__(self, *responses):
        self.responses = list(responses)
        self.requests = []
        self.error = None

    def open(self, request, timeout=None):
        self.requests.append(request)

        if self.error is not None:
            raise self.error

        if not self.responses:
            return Response({"ok": True, "result": []})

        payload = self.responses.pop(0)

        if isinstance(payload, Exception):
            raise payload

        return Response(payload)

    @property
    def last(self):
        return self.requests[-1]


def config(**kwargs):
    settings = dict(enabled=True, bot_token="123:abc", chat_id="-100999",
                    poll_seconds=0.0, long_poll_seconds=1)
    settings.update(kwargs)

    return TelegramConfig(**settings)


def channel(*responses, **kwargs):
    opener = Opener(*responses)

    return telegram.TelegramChannel(config(**kwargs), opener=opener), opener


def callback_update(update_id, data, user="tin"):
    return {
        "update_id": update_id,
        "callback_query": {
            "id": "cb%d" % update_id,
            "data": data,
            "from": {"username": user},
        },
    }


class ConfiguredTest(unittest.TestCase):
    def test_a_half_filled_form_is_not_a_channel(self):
        self.assertFalse(telegram.TelegramChannel(config(bot_token="")).is_configured())
        self.assertFalse(telegram.TelegramChannel(config(chat_id="")).is_configured())

    def test_disabled_is_not_configured(self):
        self.assertFalse(telegram.TelegramChannel(config(enabled=False)).is_configured())

    def test_an_unconfigured_channel_sends_nothing_and_does_not_raise(self):
        opener = Opener()
        subject = telegram.TelegramChannel(config(enabled=False), opener=opener)

        self.assertFalse(subject.send_message("anything"))
        self.assertEqual(opener.requests, [])


class SendTest(unittest.TestCase):
    def test_a_message_goes_to_the_configured_chat(self):
        subject, opener = channel({"ok": True, "result": {}})

        self.assertTrue(subject.send_message("printer stopped"))

        body = opener.last.data.decode("utf-8")

        self.assertIn("sendMessage", opener.last.full_url)
        self.assertIn("chat_id=-100999", body)

    def test_the_bot_token_is_in_the_url_not_the_body(self):
        subject, opener = channel({"ok": True, "result": {}})
        subject.send_message("hello")

        self.assertIn("/bot123:abc/", opener.last.full_url)

    def test_a_photo_is_sent_as_multipart(self):
        subject, opener = channel({"ok": True, "result": {}})

        sent = subject.post_photo(b"\xff\xd8jpegbytes", "Badge 1068-1", paused=False)

        self.assertTrue(sent)
        self.assertIn("multipart/form-data", opener.last.get_header("Content-type"))
        self.assertIn(b"jpegbytes", opener.last.data)
        self.assertIn(b'name="caption"', opener.last.data)

    def test_a_refusal_from_telegram_is_a_failure_not_an_exception(self):
        subject, _opener = channel({"ok": False, "description": "chat not found"})

        self.assertFalse(subject.send_message("hello"))

    def test_a_dead_network_is_a_failure_not_an_exception(self):
        subject, opener = channel()
        opener.error = OSError("no route to host")

        self.assertFalse(subject.send_message("hello"))

    def test_a_card_with_no_frame_still_announces_the_badge(self):
        # No picture is not no message: which badge printed, and the buttons,
        # are most of the value.
        subject, opener = channel({"ok": True, "result": {}})

        sent = subject.send_card({"expected": {"custom_id": "1068-1"}}, None)

        self.assertTrue(sent)
        self.assertIn("sendMessage", opener.last.full_url)


class CaptionTest(unittest.TestCase):
    def test_it_names_the_badge_and_the_fursuit(self):
        caption = telegram.card_caption(
            {"expected": {"custom_id": "1068-1", "fursuit_name": "Marm Helldiver"}})

        self.assertIn("1068-1", caption)
        self.assertIn("Marm Helldiver", caption)

    def test_a_job_with_no_expected_block_does_not_break(self):
        self.assertIn("unknown", telegram.card_caption({}))

    def test_the_verdict_is_carried(self):
        caption = telegram.card_caption({}, verdict="2 of 3 ink points blank")

        self.assertIn("ink points", caption)


class KeyboardTest(unittest.TestCase):
    def test_a_running_printer_is_offered_pause(self):
        buttons = telegram.control_keyboard(paused=False)["inline_keyboard"][0]

        self.assertEqual(buttons[0]["callback_data"], telegram.COMMAND_PAUSE)

    def test_a_paused_printer_is_offered_resume(self):
        # Offering Pause on an already-paused run invites somebody to wonder
        # whether the first press worked.
        buttons = telegram.control_keyboard(paused=True)["inline_keyboard"][0]

        self.assertEqual(buttons[0]["callback_data"], telegram.COMMAND_RESUME)


class PollTest(unittest.TestCase):
    def test_a_button_press_becomes_a_command(self):
        subject, _opener = channel(
            {"ok": True, "result": [callback_update(41, telegram.COMMAND_PAUSE)]})

        commands = subject.poll()

        self.assertEqual(len(commands), 1)
        self.assertEqual(commands[0]["command"], telegram.COMMAND_PAUSE)
        self.assertEqual(commands[0]["from"], "tin")

    def test_the_offset_advances_so_a_press_is_not_replayed(self):
        # The bug this guards: without an advancing offset, Telegram redelivers
        # the same press on every poll and the printer can never be resumed.
        subject, opener = channel(
            {"ok": True, "result": [callback_update(41, telegram.COMMAND_PAUSE)]},
            {"ok": True, "result": []},
        )

        subject.poll()
        subject.poll()

        self.assertIn("offset=42", opener.last.data.decode("utf-8"))

    def test_the_highest_update_id_wins_not_the_last_one(self):
        subject, opener = channel(
            {"ok": True, "result": [
                callback_update(50, telegram.COMMAND_STATUS),
                callback_update(49, telegram.COMMAND_STATUS),
            ]},
            {"ok": True, "result": []},
        )

        subject.poll()
        subject.poll()

        self.assertIn("offset=51", opener.last.data.decode("utf-8"))

    def test_an_unknown_button_is_ignored(self):
        subject, _opener = channel(
            {"ok": True, "result": [callback_update(41, "self_destruct")]})

        self.assertEqual(subject.poll(), [])

    def test_a_typed_command_also_works(self):
        subject, _opener = channel({"ok": True, "result": [{
            "update_id": 7,
            "message": {"text": "/pause@badgebot", "from": {"first_name": "Tin"}},
        }]})

        commands = subject.poll()

        self.assertEqual(len(commands), 1)
        self.assertEqual(commands[0]["command"], telegram.COMMAND_PAUSE)

    def test_ordinary_chatter_is_not_a_command(self):
        subject, _opener = channel({"ok": True, "result": [{
            "update_id": 8,
            "message": {"text": "looks good to me", "from": {"first_name": "Tin"}},
        }]})

        self.assertEqual(subject.poll(), [])

    def test_a_dead_network_yields_no_commands(self):
        subject, opener = channel()
        opener.error = OSError("down")

        self.assertEqual(subject.poll(), [])

    def test_an_offset_is_not_sent_before_anything_has_been_seen(self):
        subject, opener = channel({"ok": True, "result": []})
        subject.poll()

        self.assertNotIn("offset", opener.last.data.decode("utf-8"))


class PollerTest(unittest.TestCase):
    """The background thread. Driven by hand rather than started."""

    def test_commands_reach_the_handler(self):
        subject, _opener = channel(
            {"ok": True, "result": [callback_update(1, telegram.COMMAND_PAUSE)]})

        seen = []
        poller = telegram.CommandPoller(subject, on_command=seen.append)

        self.assertEqual(poller.poll_once(), 1)
        self.assertEqual(seen[0]["command"], telegram.COMMAND_PAUSE)

    def test_a_handler_that_throws_does_not_kill_the_poller(self):
        # The next press has to still work.
        subject, _opener = channel(
            {"ok": True, "result": [callback_update(1, telegram.COMMAND_PAUSE)]})

        def explode(_command):
            raise RuntimeError("the UI fell over")

        poller = telegram.CommandPoller(subject, on_command=explode)

        self.assertEqual(poller.poll_once(), 1)

    def test_an_unconfigured_channel_never_starts_a_thread(self):
        subject = telegram.TelegramChannel(config(enabled=False))
        poller = telegram.CommandPoller(subject, on_command=lambda _c: None)

        self.assertFalse(poller.start())
        self.assertFalse(poller.is_running())


class PhotoSenderTest(unittest.TestCase):
    """The queue that keeps Telegram off the print worker's thread."""

    def sender(self, *responses):
        subject, opener = channel(*responses)

        return telegram.PhotoSender(subject), opener

    def test_submitting_does_not_send_on_the_calling_thread(self):
        # The whole point: a thirty second Telegram timeout must not become a
        # thirty second gap between cards.
        subject, opener = self.sender()

        subject.submit({"expected": {"custom_id": "1068-1"}}, None)

        self.assertEqual(opener.requests, [])

    def test_a_queued_card_is_sent_when_the_thread_runs_it(self):
        # The item is built by hand rather than through submit(), which encodes
        # a camera frame and needs OpenCV. What is under test here is the
        # posting, not the encoding.
        subject, opener = self.sender({"ok": True, "result": {}})
        item = ({"expected": {"custom_id": "1068-1"}}, b"\xff\xd8jpegbytes",
                "", "ZXP9", "", False)

        subject._send(item)

        self.assertEqual(subject.sent, 1)
        self.assertIn("sendPhoto", opener.last.full_url)

    def test_a_card_with_no_picture_posts_nothing(self):
        # The channel carries cards and faults only. A running commentary with
        # no images in it is what makes people stop reading, and then they miss
        # the fault message that mattered.
        subject, opener = self.sender({"ok": True, "result": {}})
        subject.submit({"expected": {"custom_id": "1068-1"}}, None)

        subject._send(subject._pending.pop(0))

        self.assertEqual(subject.sent, 0)
        self.assertEqual(subject.skipped, 1)
        self.assertEqual(opener.requests, [])

    def test_a_backlog_drops_the_oldest_rather_than_blocking(self):
        # An old photo nobody has looked at is worth less than the next card.
        subject, _opener = self.sender()

        for index in range(subject.MAX_PENDING + 3):
            subject.submit({"expected": {"custom_id": "1068-%d" % index}}, None)

        self.assertEqual(len(subject._pending), subject.MAX_PENDING)
        self.assertEqual(subject.dropped, 3)

        oldest = subject._pending[0][0]["expected"]["custom_id"]

        self.assertEqual(oldest, "1068-3")

    def test_an_unconfigured_channel_queues_nothing(self):
        subject = telegram.PhotoSender(telegram.TelegramChannel(config(enabled=False)))

        self.assertFalse(subject.submit({}, None))
        self.assertEqual(subject._pending, [])

    def test_it_never_starts_a_thread_when_unconfigured(self):
        subject = telegram.PhotoSender(telegram.TelegramChannel(config(enabled=False)))

        self.assertFalse(subject.start())
        self.assertFalse(subject.is_running())

    def test_a_failing_send_does_not_kill_the_sender(self):
        subject, opener = self.sender()
        opener.error = OSError("uplink down")

        subject.submit({"expected": {"custom_id": "1068-1"}}, None)
        subject._send(subject._pending.pop(0))

        self.assertEqual(subject.sent, 0)


def join_update(update_id, chat_id, old_status="left", new_status="member"):
    return {
        "update_id": update_id,
        "my_chat_member": {
            "chat": {"id": chat_id, "title": "Badge printing"},
            "old_chat_member": {"status": old_status},
            "new_chat_member": {"status": new_status},
        },
    }


class OnboardingTest(unittest.TestCase):
    """Announcing the chat id when the bot is added somewhere.

    Telegram never shows a chat id in the client, so without this there is no
    way for somebody setting the agent up to discover the one value the config
    needs. That means it has to work with a token alone, before any chat id
    exists.
    """

    def test_a_token_alone_is_enough_to_talk(self):
        subject = telegram.TelegramChannel(config(chat_id=""))

        self.assertTrue(subject.has_token())
        self.assertFalse(subject.is_configured())

    def test_being_added_to_a_group_answers_with_the_chat_id(self):
        subject, opener = channel(
            {"ok": True, "result": [join_update(1, -1001234567890)]},
            {"ok": True, "result": {}},
        )

        subject.poll()

        body = opener.last.data.decode("utf-8")

        self.assertIn("sendMessage", opener.last.full_url)
        self.assertIn("-1001234567890", urllib.parse.unquote_plus(body))

    def test_it_answers_even_with_no_chat_id_configured(self):
        # The case that matters: this is how the id is discovered in the first
        # place, so requiring one already would make the feature impossible.
        subject, opener = channel(
            {"ok": True, "result": [join_update(1, -100999)]},
            {"ok": True, "result": {}},
            chat_id="",
        )

        subject.poll()

        self.assertIn("sendMessage", opener.last.full_url)

    def test_being_promoted_is_not_a_join(self):
        # my_chat_member fires for every membership change. Announcing on a
        # promotion would repeat the message for no reason.
        subject, opener = channel({"ok": True, "result": [
            join_update(1, -100999, old_status="member", new_status="administrator")]})

        subject.poll()

        self.assertEqual(len(opener.requests), 1, "only the getUpdates call")

    def test_being_removed_is_not_a_join(self):
        subject, opener = channel({"ok": True, "result": [
            join_update(1, -100999, old_status="administrator", new_status="left")]})

        subject.poll()

        self.assertEqual(len(opener.requests), 1)

    def test_a_join_still_advances_the_offset(self):
        # Otherwise the bot re-announces on every single poll, forever.
        subject, opener = channel(
            {"ok": True, "result": [join_update(77, -100999)]},
            {"ok": True, "result": {}},
            {"ok": True, "result": []},
        )

        subject.poll()
        subject.poll()

        self.assertIn("offset=78", opener.last.data.decode("utf-8"))

    def test_chatid_is_a_recognised_command(self):
        subject, _opener = channel({"ok": True, "result": [{
            "update_id": 5,
            "message": {"text": "/chatid@something_has_printed_bot",
                        "chat": {"id": -100777},
                        "from": {"username": "tin"}},
        }]})

        commands = subject.poll()

        self.assertEqual(commands[0]["command"], telegram.COMMAND_CHATID)
        self.assertEqual(commands[0]["chat_id"], -100777)

    def test_a_command_carries_the_chat_it_came_from(self):
        subject, _opener = channel(
            {"ok": True, "result": [callback_update(9, telegram.COMMAND_PAUSE)]})

        # No message block on this callback, so there is nothing to reply to
        # and the handler must cope with that rather than assume.
        self.assertIsNone(subject.poll()[0]["chat_id"])

    def test_the_poller_starts_on_a_token_alone(self):
        # Opener injected deliberately. Without it the thread this starts polls
        # the real Telegram API, which is how a unit suite ends up making
        # network calls from a print station.
        subject, _opener = channel(chat_id="")
        poller = telegram.CommandPoller(subject, on_command=lambda _c: None)

        self.assertTrue(poller.start())
        poller.stop()

    def test_sending_with_no_destination_fails_rather_than_guessing(self):
        subject, opener = channel(chat_id="")

        self.assertFalse(subject.send_message("hello"))
        self.assertEqual(opener.requests, [])


class TlsTest(unittest.TestCase):
    """Verification must never be silently weakened to make a send work."""

    def test_a_default_opener_is_built_when_certifi_is_missing(self):
        # Falling back is fine. Turning verification off would not be: a print
        # agent that accepts any certificate is worse than one that cannot
        # reach Telegram.
        with mock.patch.dict("sys.modules", {"certifi": None}):
            opener = telegram.build_opener()

        self.assertIsNotNone(opener)

    def test_the_context_never_disables_verification(self):
        context = telegram._ssl_context()

        if context is None:
            self.skipTest("certifi not installed in this environment")

        self.assertTrue(context.check_hostname)
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)


class AlertRelayTest(unittest.TestCase):
    """Faults have to reach the chat, or a quiet channel warns nobody."""

    class Inner:
        def __init__(self, result=True):
            self.calls = []
            self.result = result

        def alert(self, key, title, message, *args, **kwargs):
            self.calls.append((key, title, message))
            return self.result

    def relay(self, *responses, inner=None, chat_id="-100123"):
        subject, opener = channel(*responses, chat_id=chat_id)
        return telegram.AlertRelay(subject, inner), opener

    def test_a_fault_is_posted_to_the_chat(self):
        subject, opener = self.relay({"ok": True, "result": {}})

        self.assertTrue(subject.alert("jam", "Card jam", "Clear the jammed card"))
        self.assertIn("sendMessage", opener.last.full_url)

        body = urllib.parse.unquote_plus(opener.last.data.decode("utf-8"))

        self.assertIn("Card jam", body)
        self.assertIn("Clear the jammed card", body)

    def test_a_fault_carries_no_control_keyboard(self):
        # The buttons belong on cards. Answering a jam should not mean working
        # out which message's Pause was just pressed.
        subject, opener = self.relay({"ok": True, "result": {}})
        subject.alert("jam", "Card jam", "Clear it")

        self.assertNotIn("reply_markup", opener.last.data.decode("utf-8"))

    def test_pushover_still_gets_it(self):
        inner = self.Inner()
        subject, _opener = self.relay({"ok": True, "result": {}}, inner=inner)

        subject.alert("jam", "Card jam", "Clear it")

        self.assertEqual([c[1] for c in inner.calls], ["Card jam"])

    def test_a_telegram_outage_does_not_swallow_the_pushover_alert(self):
        inner = self.Inner()
        subject, _opener = self.relay(urllib.error.URLError("down"), inner=inner)

        self.assertTrue(subject.alert("jam", "Card jam", "Clear it"))
        self.assertEqual(len(inner.calls), 1)

    def test_a_broken_pushover_does_not_stop_the_chat_message(self):
        class Exploding:
            def alert(self, *args, **kwargs):
                raise RuntimeError("pushover is down")

        subject, opener = self.relay({"ok": True, "result": {}}, inner=Exploding())

        self.assertTrue(subject.alert("jam", "Card jam", "Clear it"))
        self.assertIn("sendMessage", opener.last.full_url)

    def test_nothing_is_posted_when_the_channel_is_not_configured(self):
        inner = self.Inner()
        subject, opener = self.relay(inner=inner, chat_id="")

        subject.alert("jam", "Card jam", "Clear it")

        self.assertEqual(opener.requests, [])
        self.assertEqual(len(inner.calls), 1)


if __name__ == "__main__":
    unittest.main()
