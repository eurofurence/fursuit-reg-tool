"""Every call the agent makes to Laravel, and what the venue network does to it.

The agent is the only thing that knows a card came out of the printer. If a
confirmation goes out to the wrong URL, or without the machine token, or gets
abandoned after one failed attempt on a hotel wifi that drops for ten seconds,
the server's idea of what printed diverges from reality and an attendee either
gets two badges or none.

So these tests pin two things down. First the wire format: the exact URL, method
and JSON body for every endpoint, checked against routes/print-agent.php and the
controllers' validation rules. Second the failure policy: what is worth retrying
(the server is busy, the network blinked) and what is not (we asked wrongly, and
asking again identically cannot help).

Transport is injected through the ``opener`` argument, which is the seam the
module leaves for exactly this. Nothing here touches a socket.
"""

import io
import json
import os
import socket
import sys
import tempfile
import unittest
import urllib.error

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import __version__, api  # noqa: E402
from agent import config as cfg  # noqa: E402


class FakeResponse(io.BytesIO):
    """What a successful ``opener.open()`` hands back: something to read and close."""

    def __init__(self, body=b"{}"):
        io.BytesIO.__init__(self, body)
        self.was_closed = False

    def close(self):
        self.was_closed = True
        io.BytesIO.close(self)


class FakeOpener:
    """Stands in for ``urllib.request.build_opener()``.

    Records the real ``urllib.request.Request`` objects, so assertions are on
    what would genuinely go out on the wire rather than on an invented shape.
    Outcomes are consumed in order; an ``Exception`` is raised instead of
    returned, which is how a transport failure looks to the caller.
    """

    def __init__(self, *outcomes):
        self.outcomes = list(outcomes)
        self.requests = []
        self.timeouts = []

    def open(self, request, timeout=None):
        self.requests.append(request)
        self.timeouts.append(timeout)

        outcome = self.outcomes.pop(0) if self.outcomes else FakeResponse()

        if isinstance(outcome, Exception):
            raise outcome

        return outcome

    @property
    def calls(self) -> int:
        return len(self.requests)

    @property
    def last(self):
        return self.requests[-1]


def http_error(status: int, body: bytes = b"") -> urllib.error.HTTPError:
    """An HTTPError shaped the way urllib raises one, body included."""
    return urllib.error.HTTPError(
        "https://example.test/x", status, "error", {}, io.BytesIO(body)
    )


def header(request, name: str):
    """Look a header up case-insensitively.

    urllib capitalises header names as it stores them ("X-Agent-Version"
    becomes "X-agent-version"), and HTTP header names are case-insensitive
    anyway, so the test should not care which casing the module used.
    """
    for key, value in request.headers.items():
        if key.lower() == name.lower():
            return value

    return None


def body_of(request):
    return json.loads(request.data.decode("utf-8"))


def build(*outcomes, **kwargs):
    """A client wired to a fake transport and a fake clock.

    ``sleep`` is swallowed by default so the backoff tests do not spend real
    seconds proving that a backoff exists.
    """
    server_url = kwargs.pop("server_url", "https://reg.example.test")
    api_token = kwargs.pop("api_token", "machine-token")
    sleeps = kwargs.pop("sleeps", None)

    opener = FakeOpener(*outcomes)
    config = cfg.AgentConfig(server_url=server_url, api_token=api_token)
    client = api.PrintAgentClient(
        config,
        opener=opener,
        sleep=(sleeps.append if sleeps is not None else (lambda _: None)),
        **kwargs
    )

    return client, opener


class UrlTest(unittest.TestCase):
    def test_paths_are_prefixed_with_the_api_namespace(self):
        client, _ = build()
        self.assertEqual(
            client.url_for("/jobs/claim"),
            "https://reg.example.test/api/print-agent/jobs/claim",
        )

    def test_a_trailing_slash_on_the_server_url_does_not_double_up(self):
        # Operators type the server URL into a text box by hand, and half of
        # them will end it with a slash.
        client, _ = build(server_url="https://reg.example.test/")
        self.assertEqual(
            client.url_for("/config"), "https://reg.example.test/api/print-agent/config"
        )

    def test_a_path_without_a_leading_slash_lands_in_the_same_place(self):
        client, _ = build()
        self.assertEqual(client.url_for("config"), client.url_for("/config"))


class SessionEndpointTest(unittest.TestCase):
    def test_config_is_a_get(self):
        client, opener = build(FakeResponse(b'{"lease_seconds": 90}'))

        self.assertEqual(client.config(), {"lease_seconds": 90})
        self.assertEqual(opener.last.get_method(), "GET")
        self.assertEqual(
            opener.last.full_url, "https://reg.example.test/api/print-agent/config"
        )
        self.assertIsNone(opener.last.data)

    def test_register_printers_calls_a_card_printer_a_badge_printer(self):
        # The agent thinks in hardware, the server in what it prints, and the
        # server's validation rejects anything but badge/receipt.
        client, opener = build()
        client.register_printers([
            cfg.PrinterBinding(name="ZXP Series 9", role=cfg.ROLE_CARD),
            cfg.PrinterBinding(name="TM-T88", role=cfg.ROLE_RECEIPT),
        ])

        self.assertEqual(
            opener.last.full_url, "https://reg.example.test/api/print-agent/printers"
        )
        self.assertEqual(opener.last.get_method(), "POST")
        self.assertEqual(body_of(opener.last), {"printers": [
            {"name": "ZXP Series 9", "role": "badge"},
            {"name": "TM-T88", "role": "receipt"},
        ]})

    def test_register_printers_also_accepts_plain_dicts(self):
        # The receipt side describes its printers as dicts, with paper sizes
        # the card side has no concept of.
        client, opener = build()
        client.register_printers([
            {"name": "TM-T88", "role": cfg.ROLE_RECEIPT,
             "paper_sizes": ["80mm", "58mm"], "default_paper_size": "80mm"},
        ])

        self.assertEqual(body_of(opener.last)["printers"][0], {
            "name": "TM-T88",
            "role": "receipt",
            "paper_sizes": ["80mm", "58mm"],
            "default_paper_size": "80mm",
        })

    def test_an_unrecognised_role_is_refused_rather_than_guessed(self):
        # The agent says card/receipt, the server says badge/receipt. This used
        # to be `badge if role == card else receipt`, so any value that was not
        # exactly "card" quietly registered as a receipt printer. A card printer
        # registered that way is never sent a single card and nothing complains.
        client, opener = build()

        with self.assertRaises(ValueError) as caught:
            client.register_printers([{"name": "ZXP9", "role": "badge"}])

        self.assertIn("ZXP9", str(caught.exception))
        self.assertEqual(opener.requests, [], "nothing should have been sent")

    def test_only_the_printers_handed_in_are_registered(self):
        # The old QZ integration registered everything the spooler reported and
        # filled the server with "Microsoft Print to PDF".
        client, opener = build()
        client.register_printers([cfg.PrinterBinding(name="ZXP Series 9")])

        self.assertEqual(len(body_of(opener.last)["printers"]), 1)

    def test_report_condition_carries_the_ribbon_count(self):
        client, opener = build()
        client.report_condition(
            "ZXP Series 9", "card_jam", message="Card stuck in flipper",
            cards_remaining=48, cards_capacity=627, raw={"hrPrinterStatus": 5},
        )

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/printers/condition",
        )
        self.assertEqual(body_of(opener.last), {
            "printer_name": "ZXP Series 9",
            "condition": "card_jam",
            "message": "Card stuck in flipper",
            "cards_remaining": 48,
            "cards_capacity": 627,
            "raw": {"hrPrinterStatus": 5},
        })

    def test_optional_fields_are_left_out_rather_than_sent_as_null(self):
        # Laravel's nullable rules treat both the same; a lean body is what
        # makes these debuggable in a packet capture.
        client, opener = build()
        client.report_condition("ZXP Series 9", "ok")

        self.assertEqual(
            body_of(opener.last), {"printer_name": "ZXP Series 9", "condition": "ok"}
        )


class BatchEndpointTest(unittest.TestCase):
    def test_batches_comes_out_of_its_envelope(self):
        client, _ = build(FakeResponse(b'{"batches": [{"id": 7}, {"id": 8}]}'))

        self.assertEqual(client.batches(), [{"id": 7}, {"id": 8}])

    def test_batches_is_empty_when_the_server_has_nothing_queued(self):
        client, _ = build(FakeResponse(b"{}"))

        self.assertEqual(client.batches(), [])

    def test_start_batch_names_the_printer_it_will_run_on(self):
        client, opener = build()
        client.start_batch(7, "ZXP Series 9")

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/batches/7/start",
        )
        self.assertEqual(body_of(opener.last), {"printer_name": "ZXP Series 9"})

    def test_pause_batch_sends_the_reason(self):
        client, opener = build()
        client.pause_batch(7, "out of ribbon")

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/batches/7/pause",
        )
        self.assertEqual(body_of(opener.last), {"reason": "out of ribbon"})

    def test_resume_batch_posts_an_empty_object(self):
        # Still a POST with a body: the server route is a POST, and an empty
        # JSON object is what Laravel expects to parse.
        client, opener = build()
        client.resume_batch(7)

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/batches/7/resume",
        )
        self.assertEqual(opener.last.get_method(), "POST")
        self.assertEqual(body_of(opener.last), {})

    def test_cancel_batch_without_a_reason_omits_the_key(self):
        client, opener = build()
        client.cancel_batch(7)

        self.assertEqual(body_of(opener.last), {})

    def test_cancel_batch_with_a_reason_sends_it(self):
        client, opener = build()
        client.cancel_batch(7, "wrong badge design")

        self.assertEqual(body_of(opener.last), {"reason": "wrong badge design"})

    def test_a_string_batch_id_still_builds_a_numeric_url(self):
        # Batch ids arrive from JSON and from the UI; both must produce the
        # same URL rather than one with a quoted id in it.
        client, opener = build()
        client.start_batch("7", "ZXP Series 9")

        self.assertTrue(opener.last.full_url.endswith("/batches/7/start"))


class JobEndpointTest(unittest.TestCase):
    def test_claim_asks_for_one_card_from_one_batch(self):
        client, opener = build(FakeResponse(b'{"job": {"id": 42}}'))

        self.assertEqual(client.claim(7, "ZXP Series 9"), ({"id": 42}, ""))
        self.assertEqual(
            opener.last.full_url, "https://reg.example.test/api/print-agent/jobs/claim"
        )
        self.assertEqual(
            body_of(opener.last), {"batch_id": 7, "printer_name": "ZXP Series 9"}
        )

    def test_a_drained_batch_returns_none_and_is_not_an_error(self):
        # The server answers {"job": null} rather than 404 when the batch is
        # empty, so a worker must read that as "nothing left", not "broken".
        client, _ = build(FakeResponse(b'{"job": null, "batch_status": "completed"}'))

        self.assertEqual(client.claim(7, "ZXP Series 9"), (None, "completed"))

    def test_heartbeat_renews_the_lease_for_one_job(self):
        # A retransfer card takes over a minute; without this the lease reaper
        # takes the job back mid-print and a second machine prints it again.
        client, opener = build()
        client.heartbeat(42)

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/jobs/42/heartbeat",
        )
        self.assertEqual(body_of(opener.last), {})

    def test_mark_printing_posts_to_the_printing_endpoint(self):
        client, opener = build()
        client.mark_printing(42)

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/jobs/42/printing",
        )

    def test_mark_printed_carries_the_firmware_evidence(self):
        # The firmware job table is the only witness that does not go through
        # the Windows driver, which reports success for cards that never came out.
        client, opener = build()
        client.mark_printed(
            42, api.COMPLETION_FIRMWARE,
            firmware_job_id="118", firmware_job_uuid="f3a1-...",
        )

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/jobs/42/printed",
        )
        self.assertEqual(body_of(opener.last), {
            "completion_source": "firmware",
            "firmware_job_id": "118",
            "firmware_job_uuid": "f3a1-...",
        })

    def test_mark_printed_without_firmware_ids_still_states_the_source(self):
        # completion_source is required server side: a job cannot reach Printed
        # without saying how we know it printed.
        client, opener = build()
        client.mark_printed(42, api.COMPLETION_SPOOLER_ONLY)

        self.assertEqual(
            body_of(opener.last), {"completion_source": "spooler_only"}
        )

    def test_the_completion_sources_are_the_ones_the_server_accepts(self):
        # Mirrors PrintCompletionSourceEnum; a typo here is a 422 at the desk.
        self.assertEqual(
            [api.COMPLETION_FIRMWARE, api.COMPLETION_SPOOLER_ONLY, api.COMPLETION_OPERATOR],
            ["firmware", "spooler_only", "operator"],
        )

    def test_mark_failed_sends_the_reason(self):
        client, opener = build()
        client.mark_failed(42, "card jam")

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/jobs/42/failed",
        )
        self.assertEqual(body_of(opener.last), {"reason": "card jam"})

    def test_verify_sends_only_the_verdict(self):
        # No frames and no OCR text leave the machine: the camera is a local
        # confidence check, not a data feed.
        client, opener = build()
        client.verify(42, api.VERIFY_CAMERA)

        self.assertEqual(
            opener.last.full_url,
            "https://reg.example.test/api/print-agent/jobs/42/verify",
        )
        self.assertEqual(body_of(opener.last), {"source": "camera"})

    def test_the_verification_sources_are_the_ones_the_server_accepts(self):
        self.assertEqual([api.VERIFY_CAMERA, api.VERIFY_OPERATOR], ["camera", "operator"])

    def test_held_jobs_comes_out_of_its_envelope(self):
        # What this machine was in the middle of when it died, so a restarted
        # agent picks the cards up instead of abandoning them.
        client, opener = build(FakeResponse(b'{"jobs": [{"id": 42}]}'))

        self.assertEqual(client.held_jobs(), [{"id": 42}])
        self.assertEqual(opener.last.get_method(), "GET")
        self.assertEqual(
            opener.last.full_url, "https://reg.example.test/api/print-agent/jobs/held"
        )

    def test_held_jobs_is_empty_when_the_body_says_nothing(self):
        client, _ = build(FakeResponse(b"{}"))

        self.assertEqual(client.held_jobs(), [])


class HeaderTest(unittest.TestCase):
    def test_every_call_carries_the_machine_token(self):
        client, opener = build()
        client.heartbeat(42)

        self.assertEqual(
            header(opener.last, "Authorization"), "Bearer machine-token"
        )

    def test_every_call_announces_the_agent_version(self):
        # The server uses this to spot a station still running last year's
        # build, which is otherwise invisible until it misbehaves.
        client, opener = build()
        client.config()

        self.assertEqual(header(opener.last, "X-Agent-Version"), __version__)

    def test_every_call_asks_for_json(self):
        # Without this Laravel renders an HTML error page for a validation
        # failure and the operator sees no message at all.
        client, opener = build()
        client.config()

        self.assertEqual(header(opener.last, "Accept"), "application/json")

    def test_a_body_declares_itself_as_json(self):
        client, opener = build()
        client.mark_failed(42, "card jam")

        self.assertEqual(header(opener.last, "Content-Type"), "application/json")

    def test_a_get_sends_no_content_type_because_it_has_no_body(self):
        client, opener = build()
        client.config()

        self.assertIsNone(header(opener.last, "Content-Type"))

    def test_an_empty_token_still_sends_a_wellformed_header(self):
        # An unpaired agent must get a clean 401 to show the operator, not a
        # urllib crash on None.
        client, opener = build(api_token="")
        client.config()

        self.assertEqual(header(opener.last, "Authorization"), "Bearer ")

    def test_the_configured_timeout_reaches_the_transport(self):
        client, opener = build(timeout=3.5)
        client.config()

        self.assertEqual(opener.timeouts[-1], 3.5)


class RetryTest(unittest.TestCase):
    def test_a_500_is_retried_and_the_caller_never_sees_it(self):
        client, opener = build(http_error(500), FakeResponse(b'{"ok": true}'))

        self.assertEqual(client.config(), {"ok": True})
        self.assertEqual(opener.calls, 2)

    def test_a_429_is_retried_because_it_means_come_back_later(self):
        client, opener = build(http_error(429), FakeResponse(b'{"ok": true}'))

        self.assertEqual(client.config(), {"ok": True})
        self.assertEqual(opener.calls, 2)

    def test_a_dropped_connection_is_retried(self):
        client, opener = build(
            urllib.error.URLError("connection refused"), FakeResponse(b'{"ok": true}')
        )

        self.assertEqual(client.config(), {"ok": True})
        self.assertEqual(opener.calls, 2)

    def test_a_timeout_is_retried(self):
        client, opener = build(socket.timeout("timed out"), FakeResponse(b"{}"))

        client.config()
        self.assertEqual(opener.calls, 2)

    def test_a_reset_while_the_body_streams_is_retried(self):
        # A hotel network drops mid-transfer as readily as mid-connect. If the
        # body read sat outside the retry it would escape as a bare URLError,
        # past both the backoff and this module's error contract.
        class Truncated:
            def read(self):
                raise urllib.error.URLError("connection reset by peer")

            def close(self):
                pass

        client, opener = build(Truncated(), FakeResponse(b'{"ok": true}'))

        self.assertEqual(client.config(), {"ok": True})
        self.assertEqual(opener.calls, 2)

    def test_a_body_that_never_arrives_ends_as_a_network_error(self):
        class Truncated:
            def read(self):
                raise urllib.error.URLError("connection reset by peer")

            def close(self):
                pass

        client, _ = build(*[Truncated() for _ in range(4)])

        with self.assertRaises(api.NetworkError):
            client.config()

    def test_the_backoff_doubles_between_attempts(self):
        sleeps = []
        client, _ = build(
            http_error(503), http_error(503), http_error(503), FakeResponse(b"{}"),
            sleeps=sleeps, backoff_seconds=0.5,
        )
        client.config()

        self.assertEqual(sleeps, [0.5, 1.0, 2.0])

    def test_the_backoff_is_capped_so_a_worker_does_not_stall_for_minutes(self):
        sleeps = []
        client, _ = build(
            http_error(503), http_error(503), http_error(503), FakeResponse(b"{}"),
            sleeps=sleeps, backoff_seconds=4.0, max_backoff_seconds=5.0,
        )
        client.config()

        self.assertEqual(sleeps, [4.0, 5.0, 5.0])

    def test_a_422_is_not_retried(self):
        # The request itself is wrong. Repeating it verbatim burns the retry
        # budget and delays the operator seeing the real message.
        client, opener = build(http_error(422, b'{"message": "invalid"}'))

        with self.assertRaises(api.ApiError):
            client.mark_printed(42, "nonsense")

        self.assertEqual(opener.calls, 1)

    def test_a_dead_token_is_not_retried(self):
        client, opener = build(http_error(401, b'{"message": "Unauthenticated."}'))

        with self.assertRaises(api.ApiError):
            client.config()

        self.assertEqual(opener.calls, 1)

    def test_a_409_is_not_retried(self):
        # "This batch is already running elsewhere" will still be true in two
        # seconds; a human has to resolve it.
        client, opener = build(http_error(409, b'{"error": "already started"}'))

        with self.assertRaises(api.ApiError):
            client.start_batch(7, "ZXP Series 9")

        self.assertEqual(opener.calls, 1)

    def test_a_4xx_never_sleeps(self):
        sleeps = []
        client, _ = build(http_error(404), sleeps=sleeps)

        with self.assertRaises(api.ApiError):
            client.config()

        self.assertEqual(sleeps, [])

    def test_a_server_that_stays_down_finally_raises_the_last_status(self):
        client, opener = build(*[http_error(503, b"maintenance") for _ in range(4)])

        with self.assertRaises(api.ApiError) as caught:
            client.config()

        self.assertEqual(caught.exception.status, 503)
        self.assertEqual(opener.calls, 4)

    def test_an_unreachable_server_raises_a_network_error_after_the_last_attempt(self):
        # NetworkError rather than ApiError on purpose: it tells the worker to
        # keep printing from the local queue and flush the outbox later.
        client, opener = build(*[urllib.error.URLError("no route") for _ in range(4)])

        with self.assertRaises(api.NetworkError) as caught:
            client.heartbeat(42)

        self.assertEqual(caught.exception.attempts, 4)
        self.assertEqual(
            caught.exception.url,
            "https://reg.example.test/api/print-agent/jobs/42/heartbeat",
        )
        self.assertEqual(opener.calls, 4)

    def test_the_network_error_names_the_underlying_fault(self):
        # This string is what the operator reads in the status bar.
        client, _ = build(*[urllib.error.URLError("no route to host") for _ in range(2)],
                          max_attempts=2)

        with self.assertRaises(api.NetworkError) as caught:
            client.config()

        self.assertIn("no route to host", str(caught.exception))

    def test_a_single_attempt_client_gives_up_without_sleeping(self):
        sleeps = []
        client, opener = build(urllib.error.URLError("down"), sleeps=sleeps, max_attempts=1)

        with self.assertRaises(api.NetworkError):
            client.config()

        self.assertEqual(opener.calls, 1)
        self.assertEqual(sleeps, [])

    def test_a_nonsensical_attempt_count_still_tries_once(self):
        client, opener = build(FakeResponse(b"{}"), max_attempts=0)
        client.config()

        self.assertEqual(opener.calls, 1)


class ApiErrorTest(unittest.TestCase):
    def test_it_carries_the_status_and_the_parsed_body(self):
        error = api.ApiError(422, '{"message": "The printer name is invalid."}')

        self.assertEqual(error.status, 422)
        self.assertEqual(error.payload["message"], "The printer name is invalid.")

    def test_a_laravel_html_error_page_does_not_break_the_accessor(self):
        # Production Laravel answers a 500 with HTML, and every caller reading
        # .payload would otherwise need its own try/except.
        error = api.ApiError(500, "<!DOCTYPE html><html>Whoops</html>")

        self.assertEqual(error.payload, {})
        self.assertEqual(error.message, "HTTP 500")

    def test_a_json_body_that_is_not_an_object_is_treated_as_no_body(self):
        error = api.ApiError(500, "[1, 2, 3]")

        self.assertEqual(error.payload, {})

    def test_the_message_prefers_what_the_server_said(self):
        # This is the text that ends up in front of whoever is at the printer.
        self.assertEqual(
            api.ApiError(409, '{"error": "Batch already running"}').message,
            "Batch already running",
        )
        self.assertEqual(
            api.ApiError(422, '{"message": "Validation failed"}').message,
            "Validation failed",
        )

    def test_the_message_falls_back_to_the_status_when_the_body_says_nothing(self):
        self.assertEqual(api.ApiError(503, "").message, "HTTP 503")

    def test_a_dead_token_is_recognisable_so_the_ui_can_say_re_pair(self):
        self.assertTrue(api.ApiError(401, "").is_auth_failure())
        self.assertTrue(api.ApiError(403, "").is_auth_failure())
        self.assertFalse(api.ApiError(422, "").is_auth_failure())
        self.assertFalse(api.ApiError(500, "").is_auth_failure())

    def test_the_string_form_names_the_call_that_failed(self):
        error = api.ApiError(500, "boom", "https://reg.example.test/x", "POST")

        self.assertIn("POST", str(error))
        self.assertIn("https://reg.example.test/x", str(error))
        self.assertIn("500", str(error))

    def test_the_error_body_reaches_the_caller_from_a_real_response(self):
        client, _ = build(http_error(422, b'{"message": "printer_name required"}'))

        with self.assertRaises(api.ApiError) as caught:
            client.start_batch(7, "")

        self.assertEqual(caught.exception.payload["message"], "printer_name required")


class DecodingTest(unittest.TestCase):
    def test_an_empty_body_is_a_success_with_nothing_to_say(self):
        client, _ = build(FakeResponse(b""))

        self.assertEqual(client.config(), {})

    def test_a_body_that_is_not_json_does_not_crash_the_caller(self):
        client, _ = build(FakeResponse(b"<html>proxy error</html>"))

        self.assertEqual(client.config(), {})

    def test_a_bare_json_list_is_wrapped_rather_than_dropped(self):
        client, _ = build(FakeResponse(b"[1, 2]"))

        self.assertEqual(client.config(), {"data": [1, 2]})

    def test_the_response_is_closed_so_a_long_run_does_not_leak_sockets(self):
        response = FakeResponse(b"{}")
        client, _ = build(response)
        client.config()

        self.assertTrue(response.was_closed)


class DownloadTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()

    def tearDown(self):
        self.dir.cleanup()

    def path(self, *parts) -> str:
        return os.path.join(self.dir.name, *parts)

    def test_it_writes_the_bytes_to_disk_and_returns_the_path(self):
        client, _ = build(FakeResponse(b"%PDF-1.4 badge"))
        dest = self.path("badge.pdf")

        self.assertEqual(client.download("https://s3.test/signed", dest), dest)
        with open(dest, "rb") as handle:
            self.assertEqual(handle.read(), b"%PDF-1.4 badge")

    def test_it_creates_the_cache_directory_if_it_is_missing(self):
        client, _ = build(FakeResponse(b"%PDF"))
        dest = self.path("cache", "7", "badge.pdf")

        client.download("https://s3.test/signed", dest)

        self.assertTrue(os.path.exists(dest))

    def test_it_replaces_an_existing_file(self):
        # Windows refuses to rename onto an existing name, so a reprint of the
        # same job would otherwise fail on the second attempt.
        dest = self.path("badge.pdf")
        with open(dest, "wb") as handle:
            handle.write(b"stale")

        client, _ = build(FakeResponse(b"fresh"))
        client.download("https://s3.test/signed", dest)

        with open(dest, "rb") as handle:
            self.assertEqual(handle.read(), b"fresh")

    def test_no_partial_file_is_left_where_the_printer_would_find_it(self):
        # A truncated PDF that got printed anyway would come out as a blank card.
        client, _ = build(FakeResponse(b"%PDF"))
        dest = self.path("badge.pdf")

        client.download("https://s3.test/signed", dest)

        self.assertFalse(os.path.exists(dest + ".part"))

    def test_it_sends_no_authorization_header(self):
        # The URL is a pre-signed S3 link, and S3 rejects a signed request that
        # also carries an Authorization header.
        client, opener = build(FakeResponse(b"%PDF"))
        client.download("https://s3.test/signed?X-Amz-Signature=abc", self.path("b.pdf"))

        self.assertIsNone(header(opener.last, "Authorization"))
        self.assertEqual(opener.last.full_url, "https://s3.test/signed?X-Amz-Signature=abc")
        self.assertEqual(opener.last.get_method(), "GET")

    def test_an_expired_link_raises_rather_than_writing_an_error_page_as_a_pdf(self):
        client, _ = build(http_error(403, b"<Error>Request has expired</Error>"))

        with self.assertRaises(api.ApiError):
            client.download("https://s3.test/expired", self.path("b.pdf"))

        self.assertFalse(os.path.exists(self.path("b.pdf")))

    def test_a_flaky_link_is_retried_like_any_other_call(self):
        client, opener = build(http_error(500), FakeResponse(b"%PDF"))

        client.download("https://s3.test/signed", self.path("b.pdf"))

        self.assertEqual(opener.calls, 2)


class ReprintEndpointTest(unittest.TestCase):
    """Queue one card again without stopping the run."""

    def test_it_posts_to_the_reprint_endpoint(self):
        client, opener = build(FakeResponse(b'{"job": {"id": 99, "sequence": 4}}'))

        client.reprint(42, "smudged")

        self.assertIn("/jobs/42/reprint", opener.last.full_url)

    def test_it_sends_a_reason(self):
        client, opener = build(FakeResponse())

        client.reprint(42, "half transferred")

        self.assertIn("half transferred", opener.last.data.decode("utf-8"))

    def test_a_missing_reason_still_says_something_useful(self):
        # The reason lands on the printed job's record, and "" there tells
        # whoever reads it later nothing at all.
        client, opener = build(FakeResponse())

        client.reprint(42)

        self.assertIn("rejected", opener.last.data.decode("utf-8").lower())


if __name__ == "__main__":
    unittest.main()
