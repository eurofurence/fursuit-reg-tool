"""The receipt loop: claim by printer, print, say so, come straight back.

A receipt exists because a sale already happened, so somebody is at the counter
waiting for the paper. Everything here follows from that: no batch to select, no
camera to ask, no operator to interrupt, and a failure that affects exactly one
strip of paper rather than a queue of two hundred cards.

The fakes are literal in the same way as test_worker's. The server only hands
out a receipt when asked by printer name, the spooler only accepts what it is
given, and the monitor and camera are landmines: touching either one at all
fails the test, because a thermal printer has neither.
"""

import os
import shutil
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import api, store, worker  # noqa: E402


class Clock:
    """Time the worker can be driven through without waiting for it."""

    def __init__(self, start=1000.0):
        self.now = start
        self.slept = 0.0

    def __call__(self):
        return self.now

    def sleep(self, seconds):
        self.now += max(0.0, float(seconds))
        self.slept += max(0.0, float(seconds))


class FakeApi:
    """The server's receipt lane, reduced to the calls one receipt needs.

    `claim_unbatched` is the whole point: it takes a printer name and no batch
    id, and every claim made is recorded so a test can prove that.
    """

    def __init__(self, jobs=None):
        self.queue = list(jobs or [])

        self.claims = []
        self.printing = []
        self.printed = []
        self.failed = []
        self.verified = []
        self.heartbeats = []
        self.downloads = []

        # Anything a card worker would call. Present so that calling one is a
        # test failure rather than an AttributeError somewhere confusing.
        self.batch_calls = []

        self.printed_error = None
        self.claim_error = None

    def claim_unbatched(self, printer_name):
        self.claims.append(printer_name)

        if self.claim_error is not None:
            raise self.claim_error

        return self.queue.pop(0) if self.queue else None

    def download(self, url, dest_path):
        self.downloads.append((url, dest_path))

        with open(dest_path, "wb") as handle:
            handle.write(b"%PDF-1.4 fake receipt")

        return dest_path

    def mark_printing(self, job_id):
        self.printing.append(job_id)
        return {}

    def heartbeat(self, job_id):
        self.heartbeats.append(job_id)
        return {}

    def mark_printed(self, job_id, completion_source, firmware_job_id=None, firmware_job_uuid=None):
        if self.printed_error is not None:
            raise self.printed_error

        self.printed.append({
            "job_id": job_id,
            "completion_source": completion_source,
            "firmware_job_id": firmware_job_id,
            "firmware_job_uuid": firmware_job_uuid,
        })
        return {}

    def mark_failed(self, job_id, reason):
        self.failed.append({"job_id": job_id, "reason": reason})
        return {}

    def verify(self, job_id, source):
        self.verified.append({"job_id": job_id, "source": source})
        return {}

    # -- the batch lane, which must stay untouched ------------------------

    def claim(self, batch_id, printer_name=None):
        self.batch_calls.append(("claim", batch_id))
        raise AssertionError("a receipt worker must never claim from a batch")

    def batches(self):
        self.batch_calls.append(("batches", None))
        raise AssertionError("a receipt worker must never list batches")

    def start_batch(self, batch_id, printer_name):
        self.batch_calls.append(("start_batch", batch_id))
        raise AssertionError("a receipt worker must never start a batch")

    def pause_batch(self, batch_id, reason):
        self.batch_calls.append(("pause_batch", batch_id))
        raise AssertionError("a receipt worker must never pause a batch")


class PostOnlyApi(FakeApi):
    """A transport that has not grown a typed receipt claim yet.

    The worker has to fall back to posting the call itself, because the receipt
    lane is a shape of the existing endpoint rather than a new one.
    """

    def __init__(self, jobs=None):
        super().__init__(jobs)
        self.posts = []

    claim_unbatched = None

    def post(self, path, payload):
        self.posts.append((path, payload))

        if self.claim_error is not None:
            raise self.claim_error

        return {"job": self.queue.pop(0) if self.queue else None}


class FakeNotifier:
    def __init__(self):
        self.alerts = []

    def alert(self, key, title, message, priority=0):
        self.alerts.append((key, title, message))
        return True


class Landmine:
    """Anything touched on this is a test failure.

    Stands in for the SNMP monitor and the camera. A thermal printer has no job
    table and nothing is pointed at the paper, so the receipt worker must never
    reach for either, and "never" is easier to prove than to assert call by
    call.
    """

    def __init__(self, what):
        self.what = what

    def __getattr__(self, name):
        raise AssertionError("a receipt worker touched the %s (%s)" % (self.what, name))

    def __call__(self, *args, **kwargs):
        raise AssertionError("a receipt worker called the %s" % self.what)


class Binding:
    def __init__(self, name="TM-T88"):
        self.name = name
        self.label = "Receipts"
        self.role = "receipt"


def receipt(job_id=1, sequence=1):
    """What the server sends for a receipt: no badge, so no `expected` block."""
    return {
        "id": job_id,
        "sequence": sequence,
        "type": "receipt",
        "status": "queued",
        "printer": "TM-T88",
        "file_url": "https://s3.example/print/receipt-%d.pdf" % job_id,
        "duplex": False,
        "paper": {"name": "80mm"},
        "expected": None,
    }


class ReceiptWorkerTestCase(unittest.TestCase):
    """Shared rig: an empty store, a working spooler and nothing to watch it."""

    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.cache = os.path.join(self.dir, "cache")
        os.makedirs(self.cache)

        self.clock = Clock()
        self.api = FakeApi()
        self.notifier = FakeNotifier()
        self.store = store.LocalStore(os.path.join(self.dir, "agent.db"))

        self.progress = []
        self.sent = []

        self.spool_ok = True

    def tearDown(self):
        self.store.close()
        shutil.rmtree(self.dir, ignore_errors=True)

    def sender(self, job_payload, path, binding):
        self.sent.append((job_payload["id"], path))

        if not self.spool_ok:
            return worker.SpoolResult(False, "the spooler rejected it")

        return worker.SpoolResult(True, "spool id 7")

    def build(self, **kwargs):
        options = dict(
            binding=Binding(),
            api=self.api,
            store=self.store,
            sender=self.sender,
            notifier=self.notifier,
            cache_dir=self.cache,
            on_progress=lambda step, state, detail: self.progress.append((step, state)),
            heartbeat_seconds=45.0,
            idle_seconds=1.0,
            clock=self.clock,
            sleep=self.clock.sleep,
        )
        options.update(kwargs)

        return worker.ReceiptWorker(**options)

    def steps(self, step):
        return [state for name, state in self.progress if name == step]


class ClaimTest(ReceiptWorkerTestCase):
    def test_it_claims_by_printer_name_with_no_batch_id(self):
        self.api.queue.append(receipt(101))

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(self.api.claims, ["TM-T88"])
        self.assertEqual(self.api.batch_calls, [])

    def test_it_posts_the_claim_itself_when_the_client_has_no_receipt_method(self):
        # The receipt lane is a shape of POST /jobs/claim: printer name, no
        # batch id. A transport without a typed method must not stop receipts.
        self.api = PostOnlyApi([receipt(102)])

        outcome = self.build(api=self.api).print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(self.api.posts[0], ("/jobs/claim", {"printer_name": "TM-T88"}))
        self.assertNotIn("batch_id", self.api.posts[0][1])

    def test_an_empty_queue_is_the_normal_state_between_sales(self):
        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.EMPTY)
        self.assertEqual(self.api.failed, [])
        self.assertEqual(self.sent, [])

    def test_an_unreachable_server_blocks_rather_than_printing_blind(self):
        self.api.claim_error = api.NetworkError("no route to host", "/jobs/claim", 4)

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.BLOCKED)
        self.assertEqual(self.sent, [])
        self.assertTrue(self.notifier.alerts)


class HappyPathTest(ReceiptWorkerTestCase):
    def test_a_receipt_is_printed_and_confirmed_as_spooler_only(self):
        self.api.queue.append(receipt(111))

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(self.api.printing, [111])
        self.assertEqual(len(self.api.printed), 1)
        self.assertEqual(self.api.printed[0]["completion_source"], api.COMPLETION_SPOOLER_ONLY)
        self.assertIsNone(self.api.printed[0]["firmware_job_id"])
        self.assertEqual(self.api.failed, [])

    def test_nothing_is_ever_reported_verified(self):
        # There is no camera and no operator holding the paper up, so there is
        # no verification to report. Silence is the honest answer.
        self.api.queue.append(receipt(112))

        self.build().print_next()

        self.assertEqual(self.api.verified, [])

    def test_the_checks_that_do_not_apply_are_reported_skipped_not_done(self):
        self.api.queue.append(receipt(113))

        self.build().print_next()

        for step in (worker.STEP_CLAIM, worker.STEP_FETCH, worker.STEP_SPOOL, worker.STEP_REPORT):
            self.assertIn(worker.DONE, self.steps(step), "%s never completed" % step)

        for step in (worker.STEP_FIRMWARE, worker.STEP_CAMERA):
            self.assertIn(worker.SKIPPED, self.steps(step))
            self.assertNotIn(worker.DONE, self.steps(step),
                             "%s never ran and must not show as done" % step)

    def test_the_receipt_is_cached_locally_before_it_is_sent(self):
        cached = {}

        def sender(job_payload, path, binding):
            cached["record"] = self.store.job(job_payload["id"])
            cached["exists"] = os.path.exists(path)
            return worker.SpoolResult(True)

        self.api.queue.append(receipt(114))
        self.build(sender=sender).print_next()

        self.assertIsNotNone(cached["record"])
        self.assertTrue(cached["exists"])
        self.assertIsNone(cached["record"]["batch_id"], "a receipt never belongs to a batch")

    def test_the_lease_is_renewed_when_the_spooler_takes_its_time(self):
        # A receipt spools in about a second, but a printer that is off leaves
        # the spooler blocking, and the reaper would take the job back.
        self.api.queue.append(receipt(115))

        def slow_sender(job_payload, path, binding):
            self.clock.sleep(120.0)
            return worker.SpoolResult(True)

        self.build(sender=slow_sender, heartbeat_seconds=45.0).print_next()

        self.assertEqual(self.api.heartbeats, [115])

    def test_a_quick_receipt_does_not_bother_the_server_with_a_heartbeat(self):
        self.api.queue.append(receipt(116))

        self.build().print_next()

        self.assertEqual(self.api.heartbeats, [])


class FailureTest(ReceiptWorkerTestCase):
    def test_a_refused_receipt_is_reported_failed_and_never_printed(self):
        self.api.queue.append(receipt(121))
        self.spool_ok = False

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.JOB_FAILED)
        self.assertEqual(self.api.printed, [])
        self.assertEqual(len(self.api.failed), 1)
        self.assertEqual(self.api.failed[0]["job_id"], 121)

    def test_a_failure_touches_no_batch_at_all(self):
        self.api.queue.append(receipt(122))
        self.spool_ok = False

        printer = self.build()
        printer.print_next()

        self.assertEqual(self.api.batch_calls, [], "a receipt must not reach a batch")
        self.assertIsNone(printer.batch_id)
        self.assertIsNone(printer.snapshot()["batch_id"])

    def test_a_failure_does_not_park_the_queue(self):
        # The next customer is already waiting. A card worker pauses here; this
        # one reports the failure and takes the next receipt.
        self.api.queue.extend([receipt(123), receipt(124)])

        attempts = []

        def sender(job_payload, path, binding):
            attempts.append(job_payload["id"])

            if job_payload["id"] == 123:
                return worker.SpoolResult(False, "out of paper")

            return worker.SpoolResult(True)

        printer = self.build(sender=sender)

        first = printer.print_next()
        second = printer.print_next()

        self.assertEqual(first.kind, worker.JOB_FAILED)
        self.assertEqual(second.kind, worker.PRINTED)
        self.assertFalse(printer.is_paused())
        self.assertEqual(attempts, [123, 124])

    def test_a_failed_receipt_is_not_reprinted_behind_anybodys_back(self):
        self.api.queue.append(receipt(125))
        self.spool_ok = False

        self.build().print_next()

        self.assertEqual(len(self.sent), 1)

    def test_a_failure_alerts_staff_because_somebody_is_waiting_for_it(self):
        self.api.queue.append(receipt(126))
        self.spool_ok = False

        self.build().print_next()

        self.assertTrue(self.notifier.alerts)
        self.assertIn("receipt", self.notifier.alerts[0][0])


class ContinuousTest(ReceiptWorkerTestCase):
    """Nobody is standing at this printer choosing what it does next."""

    def test_it_keeps_claiming_without_an_operator(self):
        self.api.queue.extend([receipt(131), receipt(132), receipt(133)])

        held = {}
        idles = []

        def sleep(seconds):
            self.clock.sleep(seconds)
            idles.append(seconds)

            # Two idle passes with nothing to print is enough to prove it kept
            # asking rather than parking.
            if len(idles) >= 2:
                held["worker"].stop()

        printer = self.build(sleep=sleep)
        held["worker"] = printer

        printer.run()

        self.assertEqual([entry["job_id"] for entry in self.api.printed], [131, 132, 133])
        self.assertGreaterEqual(len(self.api.claims), 5)
        self.assertEqual(set(self.api.claims), {"TM-T88"})
        self.assertFalse(printer.is_paused(), "an empty receipt queue is not a reason to stop")

    def test_a_drained_queue_never_looks_for_a_next_batch(self):
        held = {}

        def sleep(seconds):
            self.clock.sleep(seconds)
            held["worker"].stop()

        printer = self.build(sleep=sleep)
        held["worker"] = printer

        printer.run()

        self.assertEqual(self.api.batch_calls, [])
        self.assertFalse(hasattr(printer, "advance_batch"))
        self.assertFalse(hasattr(printer, "select_batch"))

    def test_a_failure_mid_run_does_not_end_the_loop(self):
        self.api.queue.extend([receipt(134), receipt(135)])

        held = {}
        idles = []

        def sleep(seconds):
            self.clock.sleep(seconds)
            idles.append(seconds)

            if len(idles) >= 2:
                held["worker"].stop()

        def sender(job_payload, path, binding):
            self.sent.append((job_payload["id"], path))

            if job_payload["id"] == 134:
                return worker.SpoolResult(False, "out of paper")

            return worker.SpoolResult(True)

        printer = self.build(sender=sender, sleep=sleep)
        held["worker"] = printer

        printer.run()

        self.assertEqual(len(self.api.failed), 1)
        self.assertEqual([entry["job_id"] for entry in self.api.printed], [135])


class OfflineTest(ReceiptWorkerTestCase):
    def test_an_undeliverable_confirmation_goes_to_the_outbox(self):
        self.api.queue.append(receipt(141))
        self.api.printed_error = api.NetworkError("connection refused", "/printed", 4)

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)

        pending = self.store.pending_outbox()
        self.assertEqual(len(pending), 1)
        self.assertEqual(pending[0].kind, store.OUTBOX_PRINTED)
        self.assertEqual(pending[0].payload["job_id"], 141)
        self.assertEqual(pending[0].payload["completion_source"], api.COMPLETION_SPOOLER_ONLY)

        # The cached job stays until the confirmation lands, so a restart does
        # not print the same receipt a second time.
        self.assertIsNotNone(self.store.job(141))

    def test_the_outbox_drains_once_the_server_is_back(self):
        self.api.queue.append(receipt(142))
        self.api.printed_error = api.NetworkError("connection refused", "/printed", 4)

        printer = self.build()
        printer.print_next()

        self.api.printed_error = None
        delivered = printer.flush_outbox()

        self.assertEqual(delivered, 1)
        self.assertEqual(self.api.printed[0]["job_id"], 142)
        self.assertEqual(self.store.outbox_depth(), 0)
        self.assertIsNone(self.store.job(142))

    def test_a_failure_the_server_never_heard_is_kept_too(self):
        self.api.queue.append(receipt(143))
        self.spool_ok = False
        self.api.mark_failed = _raising(api.NetworkError("connection refused", "/failed", 4))

        self.build().print_next()

        pending = self.store.pending_outbox()
        self.assertEqual([entry.kind for entry in pending], [store.OUTBOX_FAILED])


class IndependenceTest(ReceiptWorkerTestCase):
    """A thermal printer has no camera and nothing worth asking over SNMP."""

    def test_it_never_touches_the_monitor_or_the_camera(self):
        self.api.queue.append(receipt(151))

        printer = self.build()

        # Not constructor arguments, so the only way to get them onto the worker
        # is to put them there. Any use at all now blows up.
        printer.monitor = Landmine("monitor")
        printer.verifier = Landmine("camera")

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)

    def test_it_cannot_be_given_a_monitor_or_a_camera(self):
        with self.assertRaises(TypeError):
            self.build(monitor=object())

        with self.assertRaises(TypeError):
            self.build(verifier=object())

        with self.assertRaises(TypeError):
            self.build(batch_id=77)

    def test_a_stopped_card_printer_is_not_this_printers_problem(self):
        # Separate workers, separate threads, separate everything: the receipt
        # worker holds no reference to the card printer's health at all.
        printer = self.build()

        self.assertFalse(hasattr(printer, "monitor"))
        self.assertFalse(hasattr(printer, "verifier"))
        self.assertFalse(hasattr(printer, "tray_full"))
        self.assertIsNone(printer.snapshot()["pending_decision"])


class ControlTest(ReceiptWorkerTestCase):
    def test_a_stopping_worker_claims_nothing_further(self):
        self.api.queue.append(receipt(161))

        printer = self.build()
        printer.stop("operator pressed stop")

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.STOPPED)
        self.assertEqual(self.api.claims, [])
        self.assertEqual(self.sent, [])

    def test_pause_and_resume_are_reflected_in_the_snapshot(self):
        printer = self.build()

        printer.pause("changing the paper roll")
        self.assertTrue(printer.is_paused())
        self.assertEqual(printer.snapshot()["state"], worker.PAUSED)

        printer.resume()
        self.assertFalse(printer.is_paused())

    def test_the_snapshot_says_what_it_is_and_what_it_is_printing(self):
        self.api.queue.append(receipt(162))

        printer = self.build()
        printer.print_next()

        snapshot = printer.snapshot()

        self.assertEqual(snapshot["role"], "receipt")
        self.assertEqual(snapshot["printer"], "TM-T88")
        self.assertEqual(snapshot["job_id"], 162)
        self.assertEqual(snapshot["card_number"], "#162", "a receipt has no badge number")

    def test_a_broken_progress_callback_does_not_stop_the_printer(self):
        def explode(*_):
            raise RuntimeError("the UI fell over")

        self.api.queue.append(receipt(163))

        outcome = self.build(on_progress=explode).print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)


def _raising(error):
    def call(*args, **kwargs):
        raise error

    return call


if __name__ == "__main__":
    unittest.main()
