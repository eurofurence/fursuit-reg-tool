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
import sys
import unittest

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
        subject, opener = self.sender({"ok": True, "result": {}})
        subject.submit({"expected": {"custom_id": "1068-1"}}, None)

        subject._send(subject._pending.pop(0))

        self.assertEqual(subject.sent, 1)
        self.assertIn("sendMessage", opener.last.full_url)

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


if __name__ == "__main__":
    unittest.main()
