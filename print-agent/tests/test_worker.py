"""The print loop: claim one card, prove it printed, tell the server.

Everything the worker touches is a fake, because the point of the rework is that
the real hardware lies. The fakes are deliberately literal: the printer only
reports a finished job when the sender actually sent one, the server only
accepts a completion that names its source, and the camera only confirms cards
it was told came out.
"""

import os
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import api, monitor, store, vocabulary, worker, zebra  # noqa: E402


class Clock:
    """Time the worker can be driven through without waiting for it.

    sleep() advances the clock, which is the contract the worker relies on for
    its firmware timeout.
    """

    def __init__(self, start=1000.0):
        self.now = start
        self.slept = 0.0

    def __call__(self):
        return self.now

    def sleep(self, seconds):
        self.now += max(0.0, float(seconds))
        self.slept += max(0.0, float(seconds))


class FakePrinter:
    """A Zebra that answers SNMP, including its own rolling job table.

    `condition` is whatever the classifier should see; `jobs` is the firmware
    table the worker reads completion out of. Both are mutated by the tests and
    by the fake sender, so a card only appears in the table if it was sent.
    """

    def __init__(self):
        self.reading_kwargs = dict(reachable=True, printer_state="idle",
                                   supply_level=313, supply_max=627)
        self.jobs = []
        self.reads = 0

    def read(self):
        self.reads += 1
        return zebra.Reading(jobs=list(self.jobs), **self.reading_kwargs)

    # -- what the printer is doing ---------------------------------------

    def jam(self):
        self.reading_kwargs["error_bits"] = ["jammed"]

    def clear(self):
        self.reading_kwargs.pop("error_bits", None)

    def finish(self, job_id="7001", uuid="uuid-7001"):
        self.jobs.append(zebra.JobRow(index=len(self.jobs), job_id=job_id,
                                      uuid=uuid, state="done_ok",
                                      location="not_in_printer"))

    def reject(self, job_id="7002", uuid="uuid-7002", state="cancelled_error"):
        self.jobs.append(zebra.JobRow(index=len(self.jobs), job_id=job_id,
                                      uuid=uuid, state=state))


class FakeApi:
    """The server, reduced to what one card's worth of calls needs.

    Records every call so a test can assert on the completion source, which is
    the field the whole design turns on.
    """

    def __init__(self, jobs=None, batches=None):
        self.queue = list(jobs or [])
        self.available_batches = list(batches or [])

        self.claims = []
        self.printing = []
        self.printed = []
        self.failed = []
        self.verified = []
        self.heartbeats = []
        self.started = []
        self.downloads = []

        # Set to an exception instance to make that call blow up.
        self.printed_error = None
        self.claim_error = None
        self.start_batch_error = None

        # What the server says the batch is once the queue runs dry.
        self.drained_status = "printing"

    def claim(self, batch_id, printer_name=None):
        self.claims.append((batch_id, printer_name))

        if self.claim_error is not None:
            raise self.claim_error

        # (job, batch_status), matching the real client. The status is what
        # tells a drained batch apart from one that was never started.
        if self.queue:
            return self.queue.pop(0), "printing"

        return None, self.drained_status

    def download(self, url, dest_path):
        self.downloads.append((url, dest_path))

        with open(dest_path, "wb") as handle:
            handle.write(b"%PDF-1.4 fake")

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

    def batches(self):
        return list(self.available_batches)

    def start_batch(self, batch_id, printer_name):
        self.started.append((batch_id, printer_name))

        if self.start_batch_error is not None:
            raise self.start_batch_error

        return {}


class FakeCamera:
    """The chute watcher. `sees_card` is what it will report next."""

    def __init__(self, enabled=True, sees_card=True, tray_full=False, blank=False):
        self.enabled = enabled
        self.sees_card = sees_card
        self.blank = blank
        self._tray_full = tray_full
        self.checks = []
        self.armed = 0

    def is_enabled(self):
        return self.enabled

    def arm(self):
        self.armed += 1

    def verify(self, job):
        self.checks.append(job.get("id"))

        if self.blank:
            return worker.Verification(True, False, "the card came out blank", True)

        return worker.Verification(True, self.sees_card,
                                   "card seen" if self.sees_card else "chute stayed empty")

    def tray_full(self):
        return self._tray_full


class FakeNotifier:
    def __init__(self):
        self.alerts = []

    def alert(self, key, title, message, priority=0):
        self.alerts.append((key, title, message))
        return True


class Binding:
    def __init__(self, name="ZXP9-Left"):
        self.name = name
        self.label = name
        self.role = "card"


def job(job_id=1, custom_id="24-0031", sequence=1):
    return {
        "id": job_id,
        "sequence": sequence,
        "type": "badge",
        "status": "queued",
        "printer": "ZXP9-Left",
        "file_url": "https://s3.example/print/%d.pdf" % job_id,
        "duplex": False,
        "paper": None,
        "expected": {"custom_id": custom_id, "fursuit_name": "Tinnu"},
    }


class WorkerTestCase(unittest.TestCase):
    """Shared rig: a healthy printer, an empty store and a sender that works."""

    def setUp(self):
        self.dir = tempfile.mkdtemp()
        self.cache = os.path.join(self.dir, "cache")
        os.makedirs(self.cache)

        self.clock = Clock()
        self.printer = FakePrinter()
        self.api = FakeApi()
        self.camera = None
        self.notifier = FakeNotifier()
        self.store = store.LocalStore(os.path.join(self.dir, "agent.db"))
        self.journal = vocabulary.ConditionJournal(Path(self.dir) / "journal.jsonl")
        self.monitor = monitor.PrinterMonitor(self.printer, self.journal)

        self.progress = []
        self.decisions = []
        self.sent = []

        # The default sender behaves like a working printer: Windows accepts the
        # job and the card turns up in the firmware table.
        self.spool_ok = True
        self.confirm_in_firmware = True

    def tearDown(self):
        self.store.close()
        shutil.rmtree(self.dir, ignore_errors=True)

    def sender(self, job_payload, path, binding):
        self.sent.append((job_payload["id"], path))

        if not self.spool_ok:
            return worker.SpoolResult(False, "the spooler rejected it")

        if self.confirm_in_firmware:
            self.printer.finish(job_id="900%d" % job_payload["id"],
                                uuid="uuid-900%d" % job_payload["id"])

        return worker.SpoolResult(True, "spool id 42")

    def build(self, **kwargs):
        options = dict(
            binding=Binding(),
            api=self.api,
            store=self.store,
            monitor=self.monitor,
            sender=self.sender,
            notifier=self.notifier,
            verifier=self.camera,
            batch_id=77,
            cache_dir=self.cache,
            on_progress=lambda step, state, detail: self.progress.append((step, state)),
            on_decision=self.decisions.append,
            heartbeat_seconds=0.0,
            firmware_timeout=30.0,
            poll_seconds=1.0,
            idle_seconds=1.0,
            clock=self.clock,
            sleep=self.clock.sleep,
        )
        options.update(kwargs)

        return worker.PrintWorker(**options)

    # -- helpers ---------------------------------------------------------

    def auto_answer(self, printer, reprint):
        """Stand in for the operator at the dialog, answering immediately."""
        printer.on_decision = lambda decision: printer.answer_decision(reprint)
        return printer

    def steps(self, step):
        return [state for name, state in self.progress if name == step]


class GateTest(WorkerTestCase):
    def test_a_jammed_printer_is_never_printed_onto(self):
        self.printer.jam()
        printer = self.build()

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.BLOCKED)
        self.assertIn("jam", outcome.detail.lower())
        self.assertEqual(self.api.claims, [])
        self.assertEqual(self.sent, [])

    def test_an_unrecognised_state_also_stops_the_queue(self):
        # The rule that matters: unknown is a stop, not a shrug.
        self.printer.reading_kwargs["alarms"] = ["flipper_stall_17"]
        printer = self.build()

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.BLOCKED)
        self.assertEqual(self.api.claims, [])

    def test_a_blocked_printer_alerts_staff(self):
        self.printer.jam()
        self.build().print_next()

        self.assertTrue(self.notifier.alerts)
        self.assertIn("jam", self.notifier.alerts[0][2].lower())


class HappyPathTest(WorkerTestCase):
    def test_a_card_is_claimed_printed_and_confirmed_from_firmware(self):
        self.api.queue.append(job(11, "24-0031"))
        printer = self.build()

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(self.api.claims, [(77, "ZXP9-Left")])
        self.assertEqual(self.api.printing, [11])
        self.assertEqual(len(self.api.printed), 1)
        self.assertEqual(self.api.printed[0]["completion_source"], api.COMPLETION_FIRMWARE)
        self.assertEqual(self.api.printed[0]["firmware_job_id"], "90011")
        self.assertEqual(self.api.printed[0]["firmware_job_uuid"], "uuid-90011")
        self.assertEqual(self.api.failed, [])

    def test_the_job_and_its_artwork_are_cached_before_the_card_is_sent(self):
        # A network drop after this point must not lose a card we committed to.
        cached = {}

        def sender(job_payload, path, binding):
            cached["record"] = self.store.job(job_payload["id"])
            cached["exists"] = os.path.exists(path)
            return worker.SpoolResult(True)

        self.api.queue.append(job(12))
        self.confirm_in_firmware = False
        self.build(sender=sender).print_next()

        self.assertIsNotNone(cached["record"])
        self.assertTrue(cached["exists"])
        self.assertEqual(cached["record"]["batch_id"], 77)

    def test_the_lease_is_renewed_while_the_card_prints(self):
        self.api.queue.append(job(13))
        self.confirm_in_firmware = False  # forces the full wait, as a real card does
        self.build(firmware_timeout=5.0).print_next()

        self.assertTrue(self.api.heartbeats)
        self.assertEqual(set(self.api.heartbeats), {13})

    def test_the_pipeline_is_reported_step_by_step(self):
        self.api.queue.append(job(14))
        self.build().print_next()

        for step in (worker.STEP_CLAIM, worker.STEP_FETCH, worker.STEP_SPOOL,
                     worker.STEP_FIRMWARE, worker.STEP_REPORT):
            self.assertIn(worker.DONE, self.steps(step), "%s never completed" % step)

    def test_a_drained_batch_is_not_an_error(self):
        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.EMPTY)
        self.assertEqual(self.api.failed, [])


class CompletionEvidenceTest(WorkerTestCase):
    """What counts as proof that a card exists.

    There is no camera on this printer, so a fault always puts the reprint
    question to an operator. These tests answer "yes, reprint" with no attempts
    left, which is the path that ends in a reported failure.
    """

    def test_falls_back_to_spooler_only_when_firmware_never_confirms(self):
        self.api.queue.append(job(21))
        self.confirm_in_firmware = False

        outcome = self.build(firmware_timeout=10.0).print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(self.api.printed[0]["completion_source"], api.COMPLETION_SPOOLER_ONLY)
        self.assertIsNone(self.api.printed[0]["firmware_job_id"])

    def test_the_fallback_waits_the_whole_timeout_rather_than_a_short_timer(self):
        # The system this replaces force-completed after ten seconds. Nothing may
        # be declared printed before the printer has been given its full chance.
        self.api.queue.append(job(22))
        self.confirm_in_firmware = False

        self.build(firmware_timeout=60.0).print_next()

        self.assertGreaterEqual(self.clock.slept, 60.0)

    def test_a_card_the_printer_rejected_is_never_marked_printed(self):
        self.api.queue.append(job(23))
        self.confirm_in_firmware = False

        def sender(job_payload, path, binding):
            self.printer.reject()
            return worker.SpoolResult(True)

        outcome = self.auto_answer(self.build(sender=sender, max_attempts=1), True).print_next()

        self.assertEqual(outcome.kind, worker.JOB_FAILED)
        self.assertEqual(self.api.printed, [])
        self.assertEqual(len(self.api.failed), 1)
        self.assertIn("cancelled_error", self.api.failed[0]["reason"])

    def test_a_printer_that_stops_mid_card_fails_the_card(self):
        self.api.queue.append(job(24))
        self.confirm_in_firmware = False

        def sender(job_payload, path, binding):
            self.printer.jam()
            return worker.SpoolResult(True)

        outcome = self.auto_answer(self.build(sender=sender, max_attempts=1), True).print_next()

        self.assertEqual(outcome.kind, worker.JOB_FAILED)
        self.assertEqual(self.api.printed, [])
        self.assertIn("jam", self.api.failed[0]["reason"].lower())

    def test_a_spooler_refusal_is_never_marked_printed(self):
        self.api.queue.append(job(25))
        self.spool_ok = False

        outcome = self.auto_answer(self.build(max_attempts=1), True).print_next()

        self.assertEqual(outcome.kind, worker.JOB_FAILED)
        self.assertEqual(self.api.printed, [])


class CameraTest(WorkerTestCase):
    def setUp(self):
        super().setUp()
        self.camera = FakeCamera()

    def test_a_verified_card_is_reported_verified(self):
        self.api.queue.append(job(31))

        self.build().print_next()

        self.assertEqual(self.api.verified, [{"job_id": 31, "source": api.VERIFY_CAMERA}])

    def test_verification_is_separate_from_completion(self):
        # A card can be printed and unverified; the printed call must still land.
        self.camera.sees_card = False
        self.api.queue.append(job(32))

        self.build().print_next()

        self.assertEqual(len(self.api.printed), 1)
        self.assertEqual(self.api.verified, [])

    def test_a_card_the_camera_confirmed_is_not_reprinted_after_a_fault(self):
        self.api.queue.append(job(33))
        self.spool_ok = False           # the print path faulted
        self.camera.sees_card = True    # but the card is in the chute

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(len(self.sent), 1, "a confirmed card must not be printed twice")
        self.assertEqual(self.api.printed[0]["completion_source"], api.COMPLETION_SPOOLER_ONLY)
        self.assertEqual(self.api.verified, [{"job_id": 33, "source": api.VERIFY_CAMERA}])

    def test_a_card_the_camera_never_saw_is_reprinted(self):
        self.api.queue.append(job(34))
        self.camera.sees_card = False

        attempts = []

        def sender(job_payload, path, binding):
            attempts.append(job_payload["id"])

            if len(attempts) == 1:
                return worker.SpoolResult(False, "the spooler rejected it")

            self.camera.sees_card = True
            self.printer.finish(job_id="9034", uuid="uuid-9034")

            return worker.SpoolResult(True)

        outcome = self.build(sender=sender).print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(attempts, [34, 34])
        self.assertEqual(self.api.printed[0]["completion_source"], api.COMPLETION_FIRMWARE)

    def test_reprints_are_bounded_and_end_in_a_failure(self):
        self.api.queue.append(job(35))
        self.camera.sees_card = False
        self.spool_ok = False

        outcome = self.build(max_attempts=2).print_next()

        self.assertEqual(outcome.kind, worker.JOB_FAILED)
        self.assertEqual(len(self.sent), 2)
        self.assertEqual(self.api.printed, [])
        self.assertEqual(len(self.api.failed), 1)

    def test_a_reprint_waits_for_the_printer_to_be_fixed(self):
        self.api.queue.append(job(36))
        self.camera.sees_card = False

        attempts = []

        def sender(job_payload, path, binding):
            attempts.append(job_payload["id"])

            if len(attempts) == 1:
                self.printer.jam()
                return worker.SpoolResult(True)

            self.printer.finish(job_id="9036", uuid="uuid-9036")
            return worker.SpoolResult(True)

        printer = self.build(sender=sender)

        # The jam clears itself two idle polls later, as an operator would.
        original = self.monitor.poll
        polls = []

        def poll():
            polls.append(1)
            if len(polls) > 4:
                self.printer.clear()
            return original()

        self.monitor.poll = poll

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(len(attempts), 2)


class BlankCardTest(WorkerTestCase):
    """A card that came out with nothing printed on it.

    The failure this whole rework exists for. The ribbon or the transfer film
    runs out, the card feeds through blank, and the ZXP driver reports success
    exactly as it would for a good card.
    """

    def setUp(self):
        super().setUp()
        self.camera = FakeCamera(blank=True)

    def test_a_blank_card_is_never_reported_as_printed(self):
        self.api.queue.append(job(60))

        self.build().print_next()

        self.assertEqual(self.api.printed, [],
                         "a blank card must not be recorded as a printed badge")
        self.assertEqual(self.api.verified, [])

    def test_a_blank_card_fails_the_job_so_the_batch_pauses(self):
        self.api.queue.append(job(61))

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.JOB_FAILED)
        self.assertEqual([entry["job_id"] for entry in self.api.failed], [61])

    def test_the_reason_names_the_likely_cause(self):
        # Whoever reads this is standing at the machine wondering what to do.
        self.api.queue.append(job(62, custom_id="24-0099"))

        self.build().print_next()

        reason = self.api.failed[0]["reason"]

        self.assertIn("24-0099", reason)
        self.assertIn("blank", reason.lower())
        self.assertIn("ribbon", reason.lower())

    def test_the_operator_is_alerted(self):
        self.api.queue.append(job(63))

        self.build().print_next()

        self.assertTrue(any("blank" in title.lower()
                            for _key, title, _message in self.notifier.alerts))

    def test_the_camera_is_armed_before_the_card_is_sent(self):
        # The presence check compares the bin against how it looked *before*
        # the print. Armed afterwards, it would always see no change.
        self.api.queue.append(job(64))

        self.build().print_next()

        self.assertEqual(self.camera.armed, 1)


class OperatorDecisionTest(WorkerTestCase):
    """No camera: the worker must not decide reprints on its own."""

    def test_a_fault_raises_a_decision_naming_the_card_number(self):
        self.api.queue.append(job(41, custom_id="24-0099"))
        self.spool_ok = False

        printer = self.build(decision_timeout=0.05)
        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.WAITING)
        self.assertEqual(len(self.decisions), 1)
        self.assertEqual(self.decisions[0].card_number, "24-0099")
        self.assertIn("24-0099", self.decisions[0].question())
        self.assertEqual(self.api.printed, [])
        self.assertEqual(self.api.failed, [])
        self.assertEqual(len(self.sent), 1, "nothing may be reprinted without an answer")

    def test_the_lease_is_renewed_while_the_operator_thinks(self):
        # The claim is still ours. Left unrenewed, the reaper would hand the card
        # to another agent while the operator walks over to the printer.
        self.api.queue.append(job(45))
        self.spool_ok = False

        self.build(decision_timeout=0.6).print_next()

        self.assertIn(45, self.api.heartbeats)

    def test_answering_no_records_the_card_as_completed_by_the_operator(self):
        self.api.queue.append(job(42, custom_id="24-0100"))
        self.spool_ok = False

        printer = self.build()
        printer.on_decision = lambda decision: printer.answer_decision(False)

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(self.api.printed[0]["completion_source"], api.COMPLETION_OPERATOR)
        self.assertEqual(self.api.verified, [{"job_id": 42, "source": api.VERIFY_OPERATOR}])
        self.assertEqual(len(self.sent), 1)

    def test_answering_yes_reprints_the_card(self):
        self.api.queue.append(job(43))
        attempts = []

        def sender(job_payload, path, binding):
            attempts.append(job_payload["id"])

            if len(attempts) == 1:
                return worker.SpoolResult(False, "the spooler rejected it")

            self.printer.finish(job_id="9043", uuid="uuid-9043")
            return worker.SpoolResult(True)

        printer = self.build(sender=sender)
        printer.on_decision = lambda decision: printer.answer_decision(True)

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)
        self.assertEqual(attempts, [43, 43])

    def test_the_decision_is_offered_to_the_ui_before_it_is_answered(self):
        self.api.queue.append(job(44, custom_id="24-0101"))
        self.spool_ok = False

        seen = {}
        printer = self.build()

        def answer(decision):
            seen["pending"] = printer.pending_decision
            seen["fursuit"] = decision.fursuit_name
            printer.answer_decision(False, decision.id)

        printer.on_decision = answer
        printer.print_next()

        self.assertIsNotNone(seen["pending"])
        self.assertEqual(seen["fursuit"], "Tinnu")
        self.assertIsNone(printer.pending_decision)


class TrayFullTest(WorkerTestCase):
    def setUp(self):
        super().setUp()
        self.camera = FakeCamera()

    def test_a_full_tray_finishes_the_current_card_then_stops_claiming(self):
        self.api.queue.extend([job(51), job(52)])
        self.confirm_in_firmware = False

        def sender(job_payload, path, binding):
            self.sent.append((job_payload["id"], path))
            # The tray fills while this card is mid-transfer.
            self.camera._tray_full = True
            self.printer.finish(job_id="9051", uuid="uuid-9051")
            return worker.SpoolResult(True)

        printer = self.build(sender=sender)

        first = printer.print_next()
        second = printer.print_next()

        self.assertEqual(first.kind, worker.PRINTED, "the card in the printer must land")
        self.assertEqual(len(self.api.printed), 1)
        self.assertEqual(second.kind, worker.TRAY_FULL)
        self.assertEqual(len(self.sent), 1, "nothing may be claimed onto a full tray")
        self.assertEqual(len(self.api.claims), 1)

    def test_the_latch_only_clears_when_the_operator_resumes(self):
        self.camera._tray_full = True
        printer = self.build()

        self.assertEqual(printer.print_next().kind, worker.TRAY_FULL)

        self.camera._tray_full = False
        self.assertEqual(printer.print_next().kind, worker.TRAY_FULL)

        printer.resume()
        self.api.queue.append(job(53))

        self.assertEqual(printer.print_next().kind, worker.PRINTED)


class OfflineTest(WorkerTestCase):
    def test_an_undeliverable_confirmation_goes_to_the_outbox(self):
        self.api.queue.append(job(61))
        self.api.printed_error = api.NetworkError("connection refused", "/printed", 4)

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)

        pending = self.store.pending_outbox()
        self.assertEqual(len(pending), 1)
        self.assertEqual(pending[0].kind, store.OUTBOX_PRINTED)
        self.assertEqual(pending[0].payload["job_id"], 61)
        self.assertEqual(pending[0].payload["completion_source"], api.COMPLETION_FIRMWARE)

        # The cached job stays until the confirmation lands, so a restart does
        # not reprint a card the server has not heard about.
        self.assertIsNotNone(self.store.job(61))

    def test_the_outbox_drains_once_the_server_is_back(self):
        self.api.queue.append(job(62))
        self.api.printed_error = api.NetworkError("connection refused", "/printed", 4)

        printer = self.build()
        printer.print_next()

        self.api.printed_error = None
        delivered = printer.flush_outbox()

        self.assertEqual(delivered, 1)
        self.assertEqual(self.api.printed[0]["job_id"], 62)
        self.assertEqual(self.store.outbox_depth(), 0)
        self.assertIsNone(self.store.job(62))

    def test_a_server_error_on_claiming_stops_the_queue_rather_than_printing_blind(self):
        self.api.claim_error = api.NetworkError("no route to host", "/jobs/claim", 4)

        outcome = self.build().print_next()

        self.assertEqual(outcome.kind, worker.BLOCKED)
        self.assertEqual(self.sent, [])
        self.assertTrue(self.notifier.alerts)


class BatchAdvanceTest(WorkerTestCase):
    def test_unattended_mode_takes_the_next_batch(self):
        self.api.available_batches = [
            {"id": 77, "name": "Batch A", "status": "printing"},
            {"id": 78, "name": "Batch B", "status": "ready"},
        ]

        printer = self.build(unattended=True)
        nxt = printer.advance_batch()

        self.assertEqual(nxt, 78)
        self.assertEqual(printer.batch_id, 78)
        self.assertEqual(self.api.started, [(78, "ZXP9-Left")])

    def test_unattended_mode_skips_finished_batches(self):
        self.api.available_batches = [
            {"id": 78, "name": "Done", "status": "completed"},
            {"id": 79, "name": "Cancelled", "status": "cancelled"},
            {"id": 80, "name": "Next", "status": "ready"},
        ]

        printer = self.build(unattended=True)

        self.assertEqual(printer.advance_batch(), 80)

    def test_attended_mode_waits_for_an_operator(self):
        self.api.available_batches = [{"id": 78, "name": "Batch B", "status": "ready"}]

        printer = self.build(unattended=False)

        self.assertIsNone(printer.advance_batch())
        self.assertIsNone(printer.batch_id)
        self.assertEqual(self.api.started, [], "a batch must never be started behind an operator's back")


class ControlTest(WorkerTestCase):
    def test_a_stopping_worker_claims_nothing_further(self):
        self.api.queue.append(job(71))
        printer = self.build()
        printer.stop("operator pressed stop")

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.STOPPED)
        self.assertEqual(self.api.claims, [])

    def test_pause_and_resume_are_reflected_in_the_snapshot(self):
        printer = self.build()

        printer.pause("ribbon change")
        self.assertTrue(printer.is_paused())
        self.assertEqual(printer.snapshot()["state"], worker.PAUSED)

        printer.resume()
        self.assertFalse(printer.is_paused())

    def test_a_broken_progress_callback_does_not_stop_the_printer(self):
        def explode(*_):
            raise RuntimeError("the UI fell over")

        self.api.queue.append(job(72))

        outcome = self.build(on_progress=explode).print_next()

        self.assertEqual(outcome.kind, worker.PRINTED)

    def test_the_snapshot_names_the_card_in_the_printer(self):
        self.api.queue.append(job(73, custom_id="24-0202"))
        printer = self.build()
        printer.print_next()

        self.assertEqual(printer.snapshot()["card_number"], "24-0202")


class BatchStartTest(WorkerTestCase):
    """A batch has to be started on the server before it will yield cards.

    The field failure this locks out: the operator pressed Start, the worker
    span up, and nobody ever told the server. The batch stayed "ready", which
    is not claimable, so every claim came back empty. The agent read that as a
    finished batch, unloaded it and announced the run was done -- without
    printing a single card, and with the Start button still live.
    """

    def test_the_batch_is_started_before_the_first_claim(self):
        printer = self.build()
        printer.print_next()

        self.assertEqual([b for b, _ in self.api.started], [77])

    def test_the_batch_is_started_only_once(self):
        printer = self.build()
        printer.print_next()
        printer.print_next()

        self.assertEqual(len(self.api.started), 1)

    def test_a_batch_that_will_not_start_blocks_rather_than_looking_finished(self):
        self.api.start_batch_error = api.ApiError(409, "not startable")
        printer = self.build()

        outcome = printer.print_next()

        self.assertEqual(outcome.kind, worker.BLOCKED)

    def test_a_new_batch_is_started_in_its_own_right(self):
        printer = self.build()
        printer.print_next()

        printer.select_batch(78)
        printer.print_next()

        self.assertEqual([b for b, _ in self.api.started], [77, 78])


class EmptyClaimTest(WorkerTestCase):
    """Why a claim came back empty decides whether the run is over."""

    def test_a_drained_batch_is_finished(self):
        self.api.queue = []
        self.api.drained_status = "printing"
        printer = self.build()

        self.assertEqual(printer.print_next().kind, worker.EMPTY)

    def test_a_paused_batch_still_holds_its_cards(self):
        # Reporting EMPTY here would move the operator on to the next batch and
        # silently skip every card in this one.
        self.api.queue = []
        self.api.drained_status = "paused"
        printer = self.build()

        self.assertEqual(printer.print_next().kind, worker.BLOCKED)

    def test_a_batch_that_never_started_is_not_finished(self):
        self.api.queue = []
        self.api.drained_status = "ready"
        printer = self.build()

        self.assertEqual(printer.print_next().kind, worker.BLOCKED)

    def test_a_blocked_batch_is_started_again_on_the_next_pass(self):
        # Otherwise the worker sits on a stale "already started" marker and
        # never recovers once somebody resumes the batch from the server.
        self.api.queue = []
        self.api.drained_status = "ready"
        printer = self.build()

        printer.print_next()
        printer.print_next()

        self.assertEqual(len(self.api.started), 2)


if __name__ == "__main__":
    unittest.main()
