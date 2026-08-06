"""A photo of every card, and a pause button, in a Telegram channel.

Why this exists alongside the automated checks. The blank-card test in
`camera.py` is a brightness and saturation threshold somebody chose by looking
at one frame on one rig. It catches the failure it was written for and nothing
else. A human glancing at a photo catches a colour cast, a half-transferred
card, artwork that is simply the wrong badge, and whatever nobody has thought
of yet, and they can do it from a bar across the road.

The pause button matters as much as the photo. Until now, stopping a run meant
standing at the machine or opening a remote session. A batch of two hundred
cards printing something subtly wrong is a bad thing to be slow about.

Design notes:

* **Every card is photographed.** A ZXP9 retransfer cycle is around a minute,
  so the channel sees roughly one message a minute, well inside Telegram's
  limit of about twenty a minute to one chat. Sampling would only add a way to
  miss the card that went wrong.
* **Long polling, not a webhook.** A webhook needs a public URL; this runs on a
  Windows 7 box behind a convention NAT.
* **Nothing here may raise into the worker.** Telegram being down, the token
  being wrong or the venue wifi dropping are all non-events for printing. Every
  public method swallows its own failures and returns a bool.

The bot needs no special permissions beyond posting to the chat. Create it with
@BotFather, add it to the channel as an administrator so it can post, and put
the numeric chat id in the config.
"""

from __future__ import annotations

import json
import logging
import mimetypes
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any, Callable, Dict, List, Optional

from .config import TelegramConfig

API_ROOT = "https://api.telegram.org"

# What a button press asks for. Sent as callback_data, which Telegram caps at
# 64 bytes, so these stay short.
COMMAND_PAUSE = "pause"
COMMAND_RESUME = "resume"
COMMAND_STATUS = "status"

log = logging.getLogger(__name__)


def _multipart(fields: Dict[str, str], photo: Optional[bytes],
               filename: str = "card.jpg") -> tuple:
    """Encode a form the way sendPhoto wants it.

    Hand-rolled rather than pulled from a library: the station is Windows 7 on
    Python 3.8 with no compiler, and every dependency added there is a
    dependency that has to have a cp38 wheel forever.
    """
    boundary = "----agent%s" % uuid.uuid4().hex
    body = bytearray()

    for name, value in fields.items():
        body.extend(("--%s\r\n" % boundary).encode("utf-8"))
        body.extend(
            ('Content-Disposition: form-data; name="%s"\r\n\r\n' % name).encode("utf-8"))
        body.extend(("%s\r\n" % value).encode("utf-8"))

    if photo is not None:
        content_type = mimetypes.guess_type(filename)[0] or "application/octet-stream"

        body.extend(("--%s\r\n" % boundary).encode("utf-8"))
        body.extend((
            'Content-Disposition: form-data; name="photo"; filename="%s"\r\n'
            % filename).encode("utf-8"))
        body.extend(("Content-Type: %s\r\n\r\n" % content_type).encode("utf-8"))
        body.extend(photo)
        body.extend(b"\r\n")

    body.extend(("--%s--\r\n" % boundary).encode("utf-8"))

    return bytes(body), "multipart/form-data; boundary=%s" % boundary


def encode_jpeg(frame: Any, quality: int = 80) -> Optional[bytes]:
    """A BGR frame as JPEG bytes, or None if that is not possible here.

    Quality is deliberately modest. Somebody is looking at this on a phone to
    answer "does that card look right", not counting pixels, and a convention
    uplink is not to be trusted with full-size stills every minute.
    """
    if frame is None:
        return None

    try:
        import cv2  # type: ignore
    except ImportError:
        return None

    try:
        ok, buffer = cv2.imencode(".jpg", frame, [int(cv2.IMWRITE_JPEG_QUALITY), quality])
    except Exception:  # noqa: BLE001 - a bad frame is not worth a traceback
        return None

    return bytes(buffer.tobytes()) if ok else None


def control_keyboard(paused: bool) -> Dict[str, Any]:
    """The buttons under a message.

    Only the action that makes sense right now is offered: a Pause button on an
    already-paused run is an invitation to wonder whether the first press
    worked.
    """
    if paused:
        buttons = [{"text": "Resume printing", "callback_data": COMMAND_RESUME}]
    else:
        buttons = [{"text": "Pause printing", "callback_data": COMMAND_PAUSE}]

    buttons.append({"text": "Status", "callback_data": COMMAND_STATUS})

    return {"inline_keyboard": [buttons]}


def card_caption(job: Dict[str, Any], verdict: str = "", printer: str = "",
                 position: str = "") -> str:
    """What goes under the photo.

    Written so somebody who was not watching can answer "is that right?"
    without opening anything else: which badge it claims to be, who it is for,
    and what the agent concluded about it.
    """
    expected = job.get("expected") or {}

    if not isinstance(expected, dict):
        expected = {}

    lines = []
    card = expected.get("custom_id") or job.get("custom_id") or "unknown"
    name = expected.get("fursuit_name") or ""

    lines.append("Badge %s%s" % (card, " - %s" % name if name else ""))

    if position:
        lines.append(position)
    if printer:
        lines.append(printer)
    if verdict:
        lines.append(verdict)

    return "\n".join(lines)


class TelegramChannel:
    """Posts cards to a chat and reads the buttons back.

    Holds no thread of its own; `CommandPoller` drives the reading side.
    """

    def __init__(
        self,
        config: TelegramConfig,
        opener: Optional[Any] = None,
        timeout: float = 30.0,
    ):
        self.config = config
        self.timeout = timeout

        # Injected so tests can assert on the request without a network.
        self._opener = opener or urllib.request.build_opener()

        # Telegram replays updates until they are acknowledged by asking for a
        # higher offset. Without this every poll would re-deliver the same
        # button press and the printer would pause forever.
        self._offset = 0
        self._lock = threading.RLock()

    # -- posting ---------------------------------------------------------

    def is_configured(self) -> bool:
        return bool(self.config and self.config.is_configured())

    def send_card(self, job: Dict[str, Any], frame: Any, verdict: str = "",
                  printer: str = "", position: str = "", paused: bool = False) -> bool:
        """Post a photo of a card that just printed."""
        photo = encode_jpeg(frame)

        caption = card_caption(job, verdict=verdict, printer=printer, position=position)

        if photo is None:
            # No picture is not no message: the caption still says which badge
            # printed and the buttons still work, which is most of the value.
            return self.send_message(caption, paused=paused)

        return self.post_photo(photo, caption, paused)

    def send_message(self, text: str, paused: bool = False,
                     buttons: bool = True) -> bool:
        fields = {"chat_id": str(self.config.chat_id), "text": text}

        if buttons:
            fields["reply_markup"] = json.dumps(control_keyboard(paused))

        return self._call("sendMessage", fields) is not None

    def answer_callback(self, callback_id: str, text: str = "") -> bool:
        """Stop the button spinning on the sender's phone."""
        fields = {"callback_query_id": str(callback_id)}

        if text:
            fields["text"] = text

        return self._call("answerCallbackQuery", fields) is not None

    # -- reading ---------------------------------------------------------

    def poll(self) -> List[Dict[str, Any]]:
        """Fetch button presses since the last call.

        Returns a list of {"command", "callback_id", "from"} dicts. An empty
        list means nothing happened, the channel is not configured, or Telegram
        could not be reached; none of those are distinguishable to a caller and
        none of them should change what the printer does.
        """
        with self._lock:
            offset = self._offset

        fields = {
            "timeout": str(int(self.config.long_poll_seconds)),
            "allowed_updates": json.dumps(["callback_query", "message"]),
        }

        if offset:
            fields["offset"] = str(offset)

        payload = self._call("getUpdates", fields)

        if not payload:
            return []

        return self._commands_from(payload.get("result") or [])

    def _commands_from(self, updates: List[Any]) -> List[Dict[str, Any]]:
        commands = []
        highest = 0

        for update in updates:
            if not isinstance(update, dict):
                continue

            highest = max(highest, int(update.get("update_id") or 0))

            command = _command_of(update)

            if command is not None:
                commands.append(command)

        if highest:
            with self._lock:
                # +1 is Telegram's contract for "I have seen everything up to
                # and including this one".
                self._offset = highest + 1

        return commands

    # -- transport -------------------------------------------------------

    def post_photo(self, photo: bytes, caption: str, paused: bool) -> bool:
        fields = {
            "chat_id": str(self.config.chat_id),
            "caption": caption,
            "reply_markup": json.dumps(control_keyboard(paused)),
        }

        return self._call("sendPhoto", fields, photo=photo) is not None

    def _call(self, method: str, fields: Dict[str, str],
              photo: Optional[bytes] = None) -> Optional[Dict[str, Any]]:
        """One Telegram API call. None on any failure, never raises."""
        if not self.is_configured():
            return None

        url = "%s/bot%s/%s" % (API_ROOT, self.config.bot_token, method)

        try:
            if photo is None:
                body = urllib.parse.urlencode(fields).encode("utf-8")
                content_type = "application/x-www-form-urlencoded"
            else:
                body, content_type = _multipart(fields, photo)

            request = urllib.request.Request(url, data=body, method="POST")
            request.add_header("Content-Type", content_type)

            response = self._opener.open(request, timeout=self.timeout)

            try:
                raw = response.read()
            finally:
                closer = getattr(response, "close", None)
                if callable(closer):
                    closer()

            payload = json.loads(raw.decode("utf-8"))
        except Exception as error:  # noqa: BLE001 - see module docstring
            log.warning("telegram %s failed: %s", method, error)
            return None

        if not isinstance(payload, dict) or not payload.get("ok"):
            log.warning("telegram %s refused: %s", method, payload)
            return None

        return payload


def _command_of(update: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    """Pull a command out of one update, or None if there is not one.

    Both button presses and typed messages are accepted. The buttons are the
    intended route, but somebody who has scrolled away from the last photo can
    still type /pause, and refusing that would be a poor joke at three in the
    morning.
    """
    query = update.get("callback_query")

    if isinstance(query, dict):
        command = str(query.get("data") or "").strip().lower()

        if command in (COMMAND_PAUSE, COMMAND_RESUME, COMMAND_STATUS):
            return {
                "command": command,
                "callback_id": query.get("id") or "",
                "from": _sender_of(query.get("from")),
            }

        return None

    message = update.get("message")

    if isinstance(message, dict):
        text = str(message.get("text") or "").strip().lower()

        # Group messages arrive as "/pause@thebotname".
        text = text.split("@")[0].lstrip("/")

        if text in (COMMAND_PAUSE, COMMAND_RESUME, COMMAND_STATUS):
            return {
                "command": text,
                "callback_id": "",
                "from": _sender_of(message.get("from")),
            }

    return None


def _sender_of(user: Any) -> str:
    """A human-readable name for whoever pressed the button.

    Recorded so the log can say who paused the run, which is the first thing
    anybody asks when they find it paused.
    """
    if not isinstance(user, dict):
        return "someone"

    name = user.get("username") or user.get("first_name") or ""

    return str(name) or "someone"


class PhotoSender:
    """Posts cards on a thread of its own.

    Sending inline would put a network call on the print worker's thread, where
    a Telegram outage and a thirty second socket timeout would become a thirty
    second pause between cards. Nothing about a chat message is worth slowing
    the printer for, so the queue is bounded and drops rather than blocks: an
    old photo nobody has looked at is worth less than the next card.
    """

    #: Roughly ten minutes of cards at one a minute. Past that, whoever is
    #: watching has stopped watching.
    MAX_PENDING = 10

    def __init__(self, channel: TelegramChannel):
        self.channel = channel

        self.sent = 0
        self.dropped = 0

        self._pending: List[tuple] = []
        self._lock = threading.Condition()
        self._stop = threading.Event()
        self._thread: Optional[threading.Thread] = None

    def start(self) -> bool:
        if not self.channel.is_configured():
            return False

        if self._thread is not None and self._thread.is_alive():
            return True

        self._stop.clear()
        self._thread = threading.Thread(target=self.run, name="telegram-photos",
                                        daemon=True)
        self._thread.start()

        return True

    def stop(self, timeout: float = 2.0) -> None:
        self._stop.set()

        with self._lock:
            self._lock.notify_all()

        thread = self._thread

        if thread is not None and thread.is_alive():
            thread.join(timeout)

    def is_running(self) -> bool:
        return self._thread is not None and self._thread.is_alive()

    def submit(self, job: Dict[str, Any], frame: Any, verdict: str = "",
               printer: str = "", position: str = "", paused: bool = False) -> bool:
        """Queue a card. Returns False if it was dropped, never blocks."""
        if not self.channel.is_configured():
            return False

        # Encoded here, on the caller's thread, because holding a reference to
        # a live frame means the next capture may overwrite it before the
        # sender gets to it.
        photo = encode_jpeg(frame)

        with self._lock:
            if len(self._pending) >= self.MAX_PENDING:
                self._pending.pop(0)
                self.dropped += 1

            self._pending.append((job, photo, verdict, printer, position, paused))
            self._lock.notify()

        return True

    def run(self) -> None:
        while not self._stop.is_set():
            with self._lock:
                while not self._pending and not self._stop.is_set():
                    self._lock.wait(0.5)

                if self._stop.is_set():
                    return

                item = self._pending.pop(0)

            self._send(item)

    def _send(self, item) -> None:
        job, photo, verdict, printer, position, paused = item

        caption = card_caption(job, verdict=verdict, printer=printer,
                               position=position)

        try:
            if photo is None:
                ok = self.channel.send_message(caption, paused=paused)
            else:
                ok = self.channel.post_photo(photo, caption, paused)
        except Exception as error:  # noqa: BLE001 - see module docstring
            log.warning("telegram photo failed: %s", error)
            return

        if ok:
            self.sent += 1


class CommandPoller:
    """Background thread turning button presses into callbacks.

    Deliberately owns no state about printing. It reports what somebody asked
    for; whoever holds the workers decides what that means.
    """

    def __init__(
        self,
        channel: TelegramChannel,
        on_command: Callable[[Dict[str, Any]], None],
        sleep: Optional[Callable[[float], None]] = None,
    ):
        self.channel = channel
        self.on_command = on_command

        self._sleep = sleep or time.sleep
        self._stop = threading.Event()
        self._thread: Optional[threading.Thread] = None

    def start(self) -> bool:
        if not self.channel.is_configured():
            return False

        if self._thread is not None and self._thread.is_alive():
            return True

        self._stop.clear()
        self._thread = threading.Thread(target=self.run, name="telegram-poller",
                                        daemon=True)
        self._thread.start()

        return True

    def stop(self, timeout: float = 2.0) -> None:
        self._stop.set()

        thread = self._thread

        if thread is not None and thread.is_alive():
            # Bounded: getUpdates is long-polling, so the thread may be sitting
            # in a socket read. It is a daemon, so a straggler cannot keep the
            # process alive after the window closes.
            thread.join(timeout)

    def is_running(self) -> bool:
        return self._thread is not None and self._thread.is_alive()

    def run(self) -> None:
        while not self._stop.is_set():
            self.poll_once()

            if self._stop.is_set():
                return

            self._sleep(max(0.1, float(self.channel.config.poll_seconds)))

    def poll_once(self) -> int:
        """One round of polling. Returns how many commands were dispatched."""
        try:
            commands = self.channel.poll()
        except Exception as error:  # noqa: BLE001 - see module docstring
            log.warning("telegram poll failed: %s", error)
            return 0

        for command in commands:
            try:
                self.on_command(command)
            except Exception as error:  # noqa: BLE001
                # A handler that throws must not kill the poller; the next
                # press has to still work.
                log.warning("telegram command handler failed: %s", error)

        return len(commands)
