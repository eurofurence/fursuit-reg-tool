"""Pushover alerts for things only the agent can see.

The printer is on a private LAN behind the agent, so when it jams at 2am the
agent is the only piece of software in the world that knows. Laravel cannot
poll it and the POS operator may be at the other end of the hall.

Two rules shape everything here.

**Alerting must never be able to stop printing.** A notification is a courtesy;
a card is the product. Every path is wrapped, no exception escapes to the
caller, and a Pushover outage is logged and forgotten. This is deliberate: the
old system's failure mode was already "silently lose badges" and adding a
notifier that can raise into the print loop would be a new way to do the same
thing.

**One alert per fault, not one per poll.** The monitor polls every few seconds.
Without a cooldown a single jam would send several hundred notifications, staff
would mute the app, and the next real alert would go unread. Cooldown is per
alert key, so a jam on printer A being suppressed never suppresses "out of
ribbon" on printer B.
"""

from __future__ import annotations

import json
import logging
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, Optional

from .config import PushoverConfig

PUSHOVER_URL = "https://api.pushover.net/1/messages.json"

# Pushover priorities. -1 is delivered without making a noise, which is right
# for "ribbon getting low"; 1 bypasses the user's quiet hours, which is right
# for a printer that has stopped mid-run.
PRIORITY_QUIET = -1
PRIORITY_NORMAL = 0
PRIORITY_HIGH = 1

log = logging.getLogger(__name__)


class Notifier:
    """Sends Pushover messages, at most one per key per cooldown window."""

    def __init__(
        self,
        config: PushoverConfig,
        opener: Optional[Any] = None,
        clock: Optional[Any] = None,
        timeout: float = 10.0,
    ):
        self.config = config
        self.timeout = timeout

        # Injected so tests can move time forward without sleeping and can
        # assert on the request without touching the network.
        self._clock = clock or time.time
        self._opener = opener or urllib.request.build_opener()

        self._lock = threading.RLock()
        self._last_sent: Dict[str, float] = {}

    # ------------------------------------------------------------------

    def is_configured(self) -> bool:
        """Enabled and actually usable. A half-filled form is not an alerting
        channel, and pretending otherwise means nobody is watching."""
        return bool(
            self.config
            and self.config.enabled
            and self.config.user_key
            and self.config.api_token
        )

    def alert(self, key: str, title: str, message: str, priority: int = PRIORITY_NORMAL) -> bool:
        """Send one alert. Returns True only if it actually went out.

        False means disabled, still inside the cooldown for this key, or the
        send failed. All three are non-events for the caller: there is nothing
        useful a print worker can do about an undelivered notification.
        """
        try:
            if not self.is_configured():
                return False

            if not self._claim_slot(key):
                return False

            return self._send(title, message, priority)
        except Exception as error:  # noqa: BLE001 - see module docstring
            log.warning("notification failed: %s", error)
            return False

    def printer_stopped(
        self,
        printer_name: str,
        condition: str,
        remedy: str = "",
        priority: int = PRIORITY_HIGH,
    ) -> bool:
        """A printer needs a human before anything else can print.

        Keyed on printer *and* condition, so a jam and a subsequent out-of-cards
        on the same machine are two alerts: they need two different fixes, and
        collapsing them would send staff to do the wrong thing.
        """
        title = "%s stopped" % (printer_name or "Printer")
        body = condition.replace("_", " ") if condition else "unknown fault"

        if remedy:
            body = "%s\n%s" % (body, remedy)

        return self.alert("printer:%s:%s" % (printer_name, condition), title, body, priority)

    def clear(self, key: str) -> None:
        """Forget a key's cooldown, so the next occurrence alerts immediately.

        Call this when the fault resolves. Otherwise a jam cleared in thirty
        seconds and re-jammed a minute later would be silent for the rest of the
        cooldown, which is exactly the moment staff most need telling.
        """
        with self._lock:
            self._last_sent.pop(key, None)

    def reset(self) -> None:
        with self._lock:
            self._last_sent.clear()

    # ------------------------------------------------------------------

    def _claim_slot(self, key: str) -> bool:
        """Take this key's send slot if the cooldown has elapsed.

        Claim and check are one locked operation: two printer workers hitting
        the same condition in the same instant must not both decide they are
        first.
        """
        cooldown = max(0, int(getattr(self.config, "cooldown_seconds", 0) or 0))
        now = self._clock()

        with self._lock:
            last = self._last_sent.get(key)

            if last is not None and (now - last) < cooldown:
                return False

            self._last_sent[key] = now

        return True

    def _send(self, title: str, message: str, priority: int) -> bool:
        payload = {
            "token": self.config.api_token,
            "user": self.config.user_key,
            "title": title,
            "message": message,
            "priority": str(int(priority)),
        }

        request = urllib.request.Request(
            PUSHOVER_URL,
            data=urllib.parse.urlencode(payload).encode("utf-8"),
            method="POST",
        )
        request.add_header("Content-Type", "application/x-www-form-urlencoded")

        try:
            response = self._opener.open(request, timeout=self.timeout)
        except urllib.error.HTTPError as error:
            # Worth distinguishing in the log: a 4xx here is a bad token the
            # operator can fix, a 5xx is Pushover's problem and will pass.
            log.warning("pushover rejected the alert: HTTP %s", getattr(error, "code", "?"))
            return False
        except Exception as error:  # noqa: BLE001 - unreachable network, DNS, TLS
            log.warning("pushover unreachable: %s", error)
            return False

        try:
            body = response.read()
        finally:
            closer = getattr(response, "close", None)
            if callable(closer):
                closer()

        return _accepted(body)


def _accepted(body: Any) -> bool:
    """Pushover answers 200 with {"status": 1} on success.

    An unparseable body counts as success: we got a 200, and treating a
    surprise response shape as a failure would only produce noise in the log.
    """
    if isinstance(body, bytes):
        body = body.decode("utf-8", "replace")

    if not body:
        return True

    try:
        decoded = json.loads(body)
    except ValueError:
        return True

    if not isinstance(decoded, dict):
        return True

    return decoded.get("status", 1) == 1
