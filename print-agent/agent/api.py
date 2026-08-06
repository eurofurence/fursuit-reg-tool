"""Talking to Laravel.

The convention network is a hotel network. It drops, it goes slow, it comes
back. Every call in here therefore assumes failure is normal: transient faults
are retried with a backoff, permanent ones (a 4xx, meaning we asked wrongly or
the token died) are raised straight away so a worker does not sit in a retry
loop over a mistake that will never fix itself.

Standard library only. This ships as a PyInstaller bundle onto Windows 7 boxes
and every dependency is another thing that has to have a cp38 wheel and another
thing that can break the build the morning of the event.

Transport is injected rather than imported, so every endpoint can be tested
against a fake opener without patching urllib for the whole process. The fake
receives the real ``urllib.request.Request`` object, so tests assert on the URL,
the method and the body that would actually go out on the wire.
"""

from __future__ import annotations

import json
import os
import socket
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional

from . import __version__
from .config import AgentConfig, ROLE_CARD, ROLE_RECEIPT

API_PREFIX = "api/print-agent"

# Roles differ on purpose. The agent thinks in terms of the physical machine
# ("card printer"), the server in terms of what it is printing ("badge"). The
# server's validation rejects anything else, so translate once, here.
SERVER_ROLE_BADGE = "badge"
SERVER_ROLE_RECEIPT = "receipt"

# Accepted by POST /jobs/{job}/printed. See PrintCompletionSourceEnum.
COMPLETION_FIRMWARE = "firmware"
COMPLETION_SPOOLER_ONLY = "spooler_only"
COMPLETION_OPERATOR = "operator"

# Accepted by POST /jobs/{job}/verify. See PrintVerificationSourceEnum.
VERIFY_CAMERA = "camera"
VERIFY_OPERATOR = "operator"

# 429 is included because it is the server telling us to come back later, which
# is exactly what a retry does. Every other 4xx means the request itself is
# wrong and repeating it verbatim cannot help.
RETRYABLE_STATUSES = (429,)


class ApiError(Exception):
    """The server answered, and the answer was an error.

    Carries the status and the raw body because the useful part of a Laravel
    error is inside the body (validation messages, the 409 explaining why a
    batch cannot start), and the caller has to be able to show it to whoever is
    standing at the printer.
    """

    def __init__(self, status: int, body: str, url: str = "", method: str = ""):
        self.status = status
        self.body = body or ""
        self.url = url
        self.method = method

        super().__init__("%s %s -> HTTP %d: %s" % (method or "?", url or "?", status, self.body[:300]))

    @property
    def payload(self) -> Dict[str, Any]:
        """The body as JSON, or an empty dict if it was not JSON.

        A 500 from Laravel in production mode is an HTML page, and the caller
        should not have to guard against that every single time.
        """
        try:
            decoded = json.loads(self.body)
        except (ValueError, TypeError):
            return {}

        return decoded if isinstance(decoded, dict) else {}

    @property
    def message(self) -> str:
        payload = self.payload
        for key in ("error", "message"):
            value = payload.get(key)
            if isinstance(value, str) and value:
                return value

        return "HTTP %d" % self.status

    def is_auth_failure(self) -> bool:
        """A dead or revoked machine token. The operator has to re-pair."""
        return self.status in (401, 403)


class NetworkError(Exception):
    """The server could not be reached at all, after exhausting retries.

    Distinct from ApiError on purpose: this one means "keep printing from the
    local queue and flush the outbox later", not "something is wrong with what
    we asked for".
    """

    def __init__(self, message: str, url: str = "", attempts: int = 0):
        self.url = url
        self.attempts = attempts

        super().__init__(message)


class PrintAgentClient:
    """Thin, typed wrapper over the print agent API.

    One instance per agent process. Safe to share between printer workers: it
    holds no per-request state, and urllib openers are independent per call.
    """

    def __init__(
        self,
        config: AgentConfig,
        opener: Optional[Any] = None,
        timeout: float = 20.0,
        max_attempts: int = 4,
        backoff_seconds: float = 0.5,
        max_backoff_seconds: float = 8.0,
        sleep: Optional[Any] = None,
    ):
        # Not `self.config`: the method mirroring GET /config needs that name,
        # and the endpoint list is the thing worth keeping literal.
        self.agent_config = config
        self.timeout = timeout
        self.max_attempts = max(1, max_attempts)
        self.backoff_seconds = backoff_seconds
        self.max_backoff_seconds = max_backoff_seconds

        # Injected so tests do not spend real seconds proving the backoff works.
        self._sleep = sleep or time.sleep
        self._opener = opener or urllib.request.build_opener()

    # ------------------------------------------------------------------
    # Session
    # ------------------------------------------------------------------

    def config(self) -> Dict[str, Any]:
        """GET /config. Who we are, what we drive, and how long a lease lasts."""
        return self.get("/config")

    def register_printers(self, bindings: List[Any]) -> Dict[str, Any]:
        """POST /printers.

        Accepts either PrinterBinding objects or plain dicts. Only printers the
        operator explicitly mapped are sent: registering everything the spooler
        reports is how the old QZ integration filled the server with "Microsoft
        Print to PDF" entries.
        """
        printers = [self._printer_entry(binding) for binding in bindings]

        return self.post("/printers", {"printers": printers})

    def report_condition(
        self,
        printer_name: str,
        condition: str,
        message: Optional[str] = None,
        cards_remaining: Optional[int] = None,
        cards_capacity: Optional[int] = None,
        raw: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        """POST /printers/condition.

        The server cannot see the printer, so this is the only way the POS ever
        learns that a machine is jammed or out of ribbon.
        """
        return self.post("/printers/condition", {
            "printer_name": printer_name,
            "condition": condition,
            "message": message,
            "cards_remaining": cards_remaining,
            "cards_capacity": cards_capacity,
            "raw": raw,
        })

    # ------------------------------------------------------------------
    # Batches
    # ------------------------------------------------------------------

    def batches(self) -> List[Dict[str, Any]]:
        """GET /batches. Returns the list directly; nothing else is in the body."""
        return self.get("/batches").get("batches", [])

    def start_batch(self, batch_id: int, printer_name: str) -> Dict[str, Any]:
        return self.post("/batches/%d/start" % int(batch_id), {"printer_name": printer_name})

    def pause_batch(self, batch_id: int, reason: str) -> Dict[str, Any]:
        return self.post("/batches/%d/pause" % int(batch_id), {"reason": reason})

    def resume_batch(self, batch_id: int) -> Dict[str, Any]:
        return self.post("/batches/%d/resume" % int(batch_id), {})

    def cancel_batch(self, batch_id: int, reason: Optional[str] = None) -> Dict[str, Any]:
        return self.post("/batches/%d/cancel" % int(batch_id), {"reason": reason})

    # ------------------------------------------------------------------
    # Jobs
    # ------------------------------------------------------------------

    def claim(self, batch_id: int, printer_name: Optional[str] = None) -> Optional[Dict[str, Any]]:
        """POST /jobs/claim. One card, or None when the batch has no more work.

        The server returns ``{"job": null, "batch_status": ...}`` rather than a
        404 when a batch is drained, so a null job is an ordinary outcome and
        not an error.
        """
        response = self.post("/jobs/claim", {
            "batch_id": int(batch_id),
            "printer_name": printer_name,
        })

        return response.get("job")

    def heartbeat(self, job_id: int) -> Dict[str, Any]:
        """Renew the lease. A retransfer card takes over a minute to print, and
        an unrenewed lease gets reaped out from under us mid-print."""
        return self.post("/jobs/%d/heartbeat" % int(job_id), {})

    def mark_printing(self, job_id: int) -> Dict[str, Any]:
        return self.post("/jobs/%d/printing" % int(job_id), {})

    def mark_printed(
        self,
        job_id: int,
        completion_source: str,
        firmware_job_id: Optional[str] = None,
        firmware_job_uuid: Optional[str] = None,
    ) -> Dict[str, Any]:
        """POST /jobs/{job}/printed.

        ``completion_source`` is mandatory server side: a job cannot reach
        Printed without saying how we know it printed. Send the firmware job id
        and uuid whenever the printer's own job table gave them to us, because
        that is the only evidence that does not go through the lying driver.
        """
        return self.post("/jobs/%d/printed" % int(job_id), {
            "completion_source": completion_source,
            "firmware_job_id": firmware_job_id,
            "firmware_job_uuid": firmware_job_uuid,
        })

    def mark_failed(self, job_id: int, reason: str) -> Dict[str, Any]:
        """POST /jobs/{job}/failed. Also pauses the batch server side, so nothing
        else drains onto a jammed printer."""
        return self.post("/jobs/%d/failed" % int(job_id), {"reason": reason})

    def verify(self, job_id: int, source: str) -> Dict[str, Any]:
        """POST /jobs/{job}/verify. Only the verdict travels: no frames, no OCR."""
        return self.post("/jobs/%d/verify" % int(job_id), {"source": source})

    def held_jobs(self) -> List[Dict[str, Any]]:
        """GET /jobs/held. What this machine was in the middle of, so a restarted
        agent picks up rather than abandoning cards."""
        return self.get("/jobs/held").get("jobs", [])

    # ------------------------------------------------------------------
    # Files
    # ------------------------------------------------------------------

    def download(self, url: str, dest_path: str) -> str:
        """Fetch a print file to disk and return the path it landed at.

        The URL is a pre-signed S3 link from the claim response, so it carries
        no Authorization header: S3 rejects a signed request that also has one.

        Written to a temporary name and renamed into place, so a drop halfway
        through cannot leave a truncated PDF that later gets printed as a blank
        card.
        """
        request = urllib.request.Request(url, method="GET")
        request.add_header("User-Agent", self._user_agent())

        body = self._send(request, url, "GET")

        directory = os.path.dirname(os.path.abspath(dest_path))
        if directory:
            try:
                os.makedirs(directory)
            except OSError:
                pass

        partial = dest_path + ".part"
        with open(partial, "wb") as handle:
            handle.write(body)

        # Windows will not rename onto an existing file.
        if os.path.exists(dest_path):
            try:
                os.remove(dest_path)
            except OSError:
                pass

        os.rename(partial, dest_path)

        return dest_path

    # ------------------------------------------------------------------
    # Plumbing
    # ------------------------------------------------------------------

    def get(self, path: str) -> Dict[str, Any]:
        return self._request("GET", path, None)

    def post(self, path: str, payload: Optional[Dict[str, Any]]) -> Dict[str, Any]:
        return self._request("POST", path, payload)

    def url_for(self, path: str) -> str:
        base = (self.agent_config.server_url or "").rstrip("/")
        return "%s/%s/%s" % (base, API_PREFIX, path.lstrip("/"))

    def _request(self, method: str, path: str, payload: Optional[Dict[str, Any]]) -> Dict[str, Any]:
        url = self.url_for(path)
        data = None

        if payload is not None:
            # Laravel's `nullable` rules accept a missing key and an explicit
            # null identically, but dropping the nulls keeps the wire payload
            # readable in a packet capture, which is how these get debugged.
            data = json.dumps(_without_nulls(payload)).encode("utf-8")

        request = urllib.request.Request(url, data=data, method=method)
        request.add_header("Accept", "application/json")
        request.add_header("Authorization", "Bearer %s" % (self.agent_config.api_token or ""))
        request.add_header("X-Agent-Version", __version__)
        request.add_header("User-Agent", self._user_agent())

        if data is not None:
            request.add_header("Content-Type", "application/json")

        body = self._send(request, url, method)

        return _decode(body)

    def _send(self, request: Any, url: str, method: str) -> bytes:
        """Perform the request, retrying what is worth retrying.

        Retries: unreachable server, timeout, 5xx, 429. Everything else is
        raised on the first attempt.
        """
        last_network_error = None

        for attempt in range(1, self.max_attempts + 1):
            try:
                response = self._opener.open(request, timeout=self.timeout)

                # Reading the body is inside the retry, not after it: a hotel
                # network drops connections mid-stream, and a reset while the
                # body streams is exactly as transient as one during connect.
                # Left outside, it escaped as a bare URLError and skipped both
                # the retry and this module's ApiError/NetworkError contract.
                try:
                    return response.read()
                finally:
                    closer = getattr(response, "close", None)
                    if callable(closer):
                        closer()
            except urllib.error.HTTPError as error:
                status = int(getattr(error, "code", 0) or 0)
                body = _read_error_body(error)

                if not self._should_retry_status(status) or attempt == self.max_attempts:
                    raise ApiError(status, body, url, method)

                self._back_off(attempt)
                continue
            except (urllib.error.URLError, socket.timeout, OSError) as error:
                last_network_error = error

                if attempt == self.max_attempts:
                    break

                self._back_off(attempt)
                continue

        raise NetworkError(
            "%s %s unreachable after %d attempts: %s" % (method, url, self.max_attempts, last_network_error),
            url,
            self.max_attempts,
        )

    def _should_retry_status(self, status: int) -> bool:
        return status >= 500 or status in RETRYABLE_STATUSES

    def _back_off(self, attempt: int) -> None:
        delay = min(self.backoff_seconds * (2 ** (attempt - 1)), self.max_backoff_seconds)
        self._sleep(delay)

    def _user_agent(self) -> str:
        return "BadgePrintAgent/%s" % __version__

    @staticmethod
    def _printer_entry(binding: Any) -> Dict[str, Any]:
        if isinstance(binding, dict):
            name = binding.get("name", "")
            role = binding.get("role", ROLE_CARD)
            paper_sizes = binding.get("paper_sizes")
            default_paper_size = binding.get("default_paper_size")
        else:
            name = getattr(binding, "name", "")
            role = getattr(binding, "role", ROLE_CARD)
            paper_sizes = getattr(binding, "paper_sizes", None)
            default_paper_size = getattr(binding, "default_paper_size", None)

        # The agent says card/receipt, the server says badge/receipt. Translate
        # explicitly and refuse anything else: treating an unrecognised role as a
        # receipt printer silently registers the card printer as the wrong type,
        # after which it is never sent a single card and nothing reports an error.
        if role == ROLE_CARD:
            server_role = SERVER_ROLE_BADGE
        elif role == ROLE_RECEIPT:
            server_role = SERVER_ROLE_RECEIPT
        else:
            raise ValueError(
                "Printer %r has role %r; expected %r or %r."
                % (name, role, ROLE_CARD, ROLE_RECEIPT)
            )

        entry = {"name": name, "role": server_role}

        if paper_sizes is not None:
            entry["paper_sizes"] = paper_sizes
        if default_paper_size is not None:
            entry["default_paper_size"] = default_paper_size

        return entry


def _without_nulls(payload: Dict[str, Any]) -> Dict[str, Any]:
    return {key: value for key, value in payload.items() if value is not None}


def _decode(body: bytes) -> Dict[str, Any]:
    """Body to dict. An empty 204-ish body is a success with nothing to say."""
    if not body:
        return {}

    if isinstance(body, bytes):
        body = body.decode("utf-8", "replace")

    try:
        decoded = json.loads(body)
    except ValueError:
        return {}

    return decoded if isinstance(decoded, dict) else {"data": decoded}


def _read_error_body(error: Any) -> str:
    try:
        raw = error.read()
    except Exception:
        return ""

    if isinstance(raw, bytes):
        return raw.decode("utf-8", "replace")

    return raw or ""
