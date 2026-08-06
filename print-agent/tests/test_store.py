"""The local database that lets the agent keep printing while the server is gone.

The convention network is a hotel network. It will go away for ten minutes at
some point during the weekend, probably with two hundred cards queued. Two
things in here decide whether that is a shrug or a mess:

* the cached job, which is how a restarted agent knows what it was in the middle
  of and does not re-download a PDF it already has;
* the outbox, which holds confirmations that could not be delivered. A printed
  card whose "printed" call was lost gets printed a second time on the next
  pass, so the outbox surviving a crash is not a nicety.

The restart tests are therefore the point of this file: they close the store and
open it again on the same file, which is what a killed process looks like from
SQLite's side. The threading test matters because each printer runs its own
worker and sqlite3 is unforgiving about connections shared across threads.
"""

import json
import os
import sqlite3
import sys
import tempfile
import threading
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agent import store as st  # noqa: E402


def job(job_id: int = 42, **extra) -> dict:
    payload = {"id": job_id, "custom_id": "EF29-0042", "file_url": "https://s3.test/x.pdf"}
    payload.update(extra)
    return payload


class StoreTestCase(unittest.TestCase):
    """Every store lives in its own temporary directory and is closed again.

    APPDATA is redirected too, so nothing here can touch a real agent's
    database on a developer's machine.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.previous_appdata = os.environ.get("APPDATA")
        os.environ["APPDATA"] = self.dir.name

        self.stores = []
        self.store = self.open()

    def tearDown(self):
        for store in self.stores:
            try:
                store.close()
            except sqlite3.Error:
                pass

        if self.previous_appdata is None:
            os.environ.pop("APPDATA", None)
        else:
            os.environ["APPDATA"] = self.previous_appdata

        self.dir.cleanup()

    @property
    def db_path(self) -> str:
        return os.path.join(self.dir.name, "agent.db")

    def open(self) -> st.LocalStore:
        store = st.LocalStore(self.db_path)
        self.stores.append(store)
        return store

    def restart(self) -> st.LocalStore:
        """Close the store and open the same file again: a killed agent."""
        self.store.close()
        self.store = self.open()
        return self.store


class SchemaTest(StoreTestCase):
    def test_first_use_creates_every_table(self):
        direct = sqlite3.connect(self.db_path)
        names = {
            row[0] for row in direct.execute(
                "SELECT name FROM sqlite_master WHERE type = 'table'"
            )
        }
        direct.close()

        self.assertTrue({"jobs", "outbox", "kv"}.issubset(names))

    def test_opening_an_existing_database_again_is_safe(self):
        # Every start of the agent runs the schema statements. They have to be
        # a no-op on the second run or the agent never starts twice.
        self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 1})

        reopened = self.restart()

        self.assertEqual(reopened.outbox_depth(), 1)

    def test_two_stores_can_share_the_same_file(self):
        # The UI thread and a worker may each hold one during a reconfigure.
        second = self.open()
        self.store.set("owner", "first")

        self.assertEqual(second.get("owner"), "first")

    def test_it_defaults_to_a_database_beside_the_config(self):
        # Operators are told to look in %APPDATA%\BadgePrintAgent when asked to
        # send us the file at 3am.
        default = st.LocalStore()
        self.stores.append(default)

        self.assertTrue(default.path.endswith(st.DB_FILENAME))
        self.assertIn("BadgePrintAgent", default.path)


class JobCacheTest(StoreTestCase):
    def test_a_claimed_job_survives_a_round_trip(self):
        self.store.save_job(job(42), printer_name="ZXP Series 9", batch_id=7)

        cached = self.store.job(42)

        self.assertEqual(cached["id"], 42)
        self.assertEqual(cached["batch_id"], 7)
        self.assertEqual(cached["printer_name"], "ZXP Series 9")
        self.assertEqual(cached["payload"]["custom_id"], "EF29-0042")
        self.assertEqual(cached["status"], "claimed")

    def test_an_unknown_job_is_none_rather_than_an_error(self):
        self.assertIsNone(self.store.job(999))

    def test_saving_the_same_job_twice_does_not_produce_two_cards(self):
        # /jobs/held hands back what we were already holding after a restart.
        # A second row here would mean a second print.
        self.store.save_job(job(42), printer_name="ZXP Series 9", batch_id=7)
        self.store.save_job(job(42, custom_id="EF29-0042"), printer_name="ZXP Series 9", batch_id=7)

        self.assertEqual(len(self.store.jobs()), 1)

    def test_reclaiming_a_job_keeps_the_pdf_we_already_downloaded(self):
        # Re-downloading is the slow part, and the venue link is the thing
        # least likely to be working when we resume.
        self.store.save_job(job(42))
        self.store.set_job_file(42, r"C:\cache\42.pdf")
        self.store.save_job(job(42), status="claimed")

        self.assertEqual(self.store.job(42)["file_path"], r"C:\cache\42.pdf")

    def test_the_payload_is_refreshed_when_the_server_sends_a_newer_one(self):
        self.store.save_job(job(42, file_url="https://s3.test/old.pdf"))
        self.store.save_job(job(42, file_url="https://s3.test/new.pdf"))

        self.assertEqual(
            self.store.job(42)["payload"]["file_url"], "https://s3.test/new.pdf"
        )

    def test_jobs_can_be_filtered_by_status(self):
        self.store.save_job(job(1), status="claimed")
        self.store.save_job(job(2), status="printing")
        self.store.save_job(job(3), status="printing")

        self.assertEqual(len(self.store.jobs()), 3)
        self.assertEqual([j["id"] for j in self.store.jobs("printing")], [2, 3])

    def test_the_status_moves_as_the_card_goes_through(self):
        self.store.save_job(job(42))
        self.store.set_job_status(42, "printing")

        self.assertEqual(self.store.job(42)["status"], "printing")

    def test_forgetting_a_job_removes_it(self):
        self.store.save_job(job(42))
        self.store.forget_job(42)

        self.assertIsNone(self.store.job(42))
        self.assertEqual(self.store.jobs(), [])

    def test_forgetting_an_unknown_job_is_harmless(self):
        self.store.forget_job(999)

    def test_a_job_id_arriving_as_a_string_finds_the_same_row(self):
        # Job ids come out of JSON and out of the UI; both must hit one row.
        self.store.save_job({"id": "42"})

        self.assertIsNotNone(self.store.job("42"))
        self.assertEqual(self.store.job(42)["id"], 42)

    def test_a_corrupt_payload_does_not_take_the_agent_down(self):
        # A half-written row after a power cut must not stop the agent from
        # starting; losing one job's detail beats losing the whole queue.
        self.store.save_job(job(42))
        direct = sqlite3.connect(self.db_path)
        direct.execute("UPDATE jobs SET payload = '{broken' WHERE id = 42")
        direct.commit()
        direct.close()

        self.assertEqual(self.restart().job(42)["payload"], {})

    def test_cached_jobs_survive_a_restart(self):
        self.store.save_job(job(42), printer_name="ZXP Series 9", batch_id=7)
        self.store.set_job_file(42, r"C:\cache\42.pdf")

        cached = self.restart().job(42)

        self.assertEqual(cached["file_path"], r"C:\cache\42.pdf")
        self.assertEqual(cached["batch_id"], 7)


class OutboxTest(StoreTestCase):
    def test_an_entry_round_trips_with_its_payload(self):
        entry_id = self.store.enqueue_outbox(
            st.OUTBOX_PRINTED, {"job_id": 42, "completion_source": "firmware"}
        )

        pending = self.store.pending_outbox()

        self.assertEqual(len(pending), 1)
        self.assertEqual(pending[0].id, entry_id)
        self.assertEqual(pending[0].kind, st.OUTBOX_PRINTED)
        self.assertEqual(pending[0].payload["completion_source"], "firmware")
        self.assertEqual(pending[0].attempts, 0)
        self.assertIsNone(pending[0].last_error)

    def test_confirmations_come_back_in_the_order_the_cards_printed(self):
        # Out of order, the server would record the wrong card as the last one
        # printed when the batch finishes.
        for job_id in (1, 2, 3):
            self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": job_id})

        self.assertEqual(
            [e.payload["job_id"] for e in self.store.pending_outbox()], [1, 2, 3]
        )

    def test_the_pending_list_can_be_capped(self):
        # A flush after a long outage should not build a list of a thousand
        # entries in memory on a Windows 7 box.
        for job_id in range(10):
            self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": job_id})

        self.assertEqual(len(self.store.pending_outbox(limit=3)), 3)

    def test_a_delivered_confirmation_stops_being_pending(self):
        entry_id = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 42})
        self.store.mark_outbox_sent(entry_id)

        self.assertEqual(self.store.pending_outbox(), [])
        self.assertEqual(self.store.outbox_depth(), 0)

    def test_marking_one_entry_sent_leaves_the_rest_alone(self):
        first = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 1})
        self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 2})

        self.store.mark_outbox_sent(first)

        self.assertEqual([e.payload["job_id"] for e in self.store.pending_outbox()], [2])

    def test_a_failed_delivery_records_the_error_and_counts_up(self):
        # This is what lets the UI say "37 waiting, connection refused" instead
        # of the operator finding out days later.
        entry_id = self.store.enqueue_outbox(st.OUTBOX_FAILED, {"job_id": 42})

        self.assertEqual(self.store.bump_outbox_attempt(entry_id, "connection refused"), 1)
        self.assertEqual(self.store.bump_outbox_attempt(entry_id, "connection refused"), 2)

        entry = self.store.pending_outbox()[0]

        self.assertEqual(entry.attempts, 2)
        self.assertEqual(entry.last_error, "connection refused")

    def test_a_bumped_entry_is_still_pending(self):
        # A failed attempt must never look like a delivered one.
        entry_id = self.store.enqueue_outbox(st.OUTBOX_VERIFY, {"job_id": 42})
        self.store.bump_outbox_attempt(entry_id, "timeout")

        self.assertEqual(self.store.outbox_depth(), 1)

    def test_a_giant_error_is_truncated_rather_than_bloating_the_database(self):
        # A Laravel HTML error page is tens of kilobytes and there is one per
        # failed confirmation.
        entry_id = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 42})
        self.store.bump_outbox_attempt(entry_id, "x" * 50000)

        self.assertEqual(len(self.store.pending_outbox()[0].last_error), 1000)

    def test_bumping_an_unknown_entry_returns_zero_instead_of_raising(self):
        self.assertEqual(self.store.bump_outbox_attempt(999, "gone"), 0)

    def test_the_depth_is_what_the_operator_sees_as_a_health_signal(self):
        self.assertEqual(self.store.outbox_depth(), 0)

        for job_id in range(5):
            self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": job_id})

        self.assertEqual(self.store.outbox_depth(), 5)

    def test_the_four_kinds_are_stable_strings(self):
        # Rows written by last year's build have to still mean something.
        self.assertEqual(
            [st.OUTBOX_PRINTED, st.OUTBOX_FAILED, st.OUTBOX_VERIFY, st.OUTBOX_CONDITION],
            ["printed", "failed", "verify", "condition"],
        )

    def test_pruning_never_discards_something_still_undelivered(self):
        # The single rule the outbox exists to enforce.
        sent = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 1})
        self.store.mark_outbox_sent(sent)
        self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 2})

        self.store.prune_outbox(keep=0)

        self.assertEqual([e.payload["job_id"] for e in self.store.pending_outbox()], [2])

    def test_pruning_keeps_the_most_recent_delivered_rows_for_diagnosis(self):
        for job_id in range(10):
            entry_id = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": job_id})
            self.store.mark_outbox_sent(entry_id)

        self.assertEqual(self.store.prune_outbox(keep=4), 6)
        self.assertEqual(self.store.prune_outbox(keep=4), 0)

    def test_a_corrupt_outbox_payload_does_not_stop_the_flush(self):
        self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 1})
        direct = sqlite3.connect(self.db_path)
        direct.execute("UPDATE outbox SET payload = 'not json'")
        direct.commit()
        direct.close()

        self.assertEqual(self.restart().pending_outbox()[0].payload, {})


class RestartTest(StoreTestCase):
    """A crashed agent must not lose confirmations.

    These close the connection and open the file again, which is the closest a
    test gets to the process being killed with the queue half flushed.
    """

    def test_undelivered_confirmations_survive_a_restart(self):
        for job_id in (1, 2, 3):
            self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": job_id})

        reopened = self.restart()

        self.assertEqual(reopened.outbox_depth(), 3)
        self.assertEqual(
            [e.payload["job_id"] for e in reopened.pending_outbox()], [1, 2, 3]
        )

    def test_the_kind_and_payload_survive_intact(self):
        # Without these the flusher cannot tell which endpoint to call.
        self.store.enqueue_outbox(
            st.OUTBOX_VERIFY, {"job_id": 42, "source": "camera"}
        )

        entry = self.restart().pending_outbox()[0]

        self.assertEqual(entry.kind, st.OUTBOX_VERIFY)
        self.assertEqual(entry.payload, {"job_id": 42, "source": "camera"})

    def test_the_attempt_count_survives_so_a_poison_entry_is_visible(self):
        entry_id = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 42})
        for _ in range(3):
            self.store.bump_outbox_attempt(entry_id, "connection refused")

        entry = self.restart().pending_outbox()[0]

        self.assertEqual(entry.attempts, 3)
        self.assertEqual(entry.last_error, "connection refused")

    def test_a_delivered_confirmation_does_not_come_back_after_a_restart(self):
        # Otherwise the flusher re-sends it and the card gets counted twice.
        entry_id = self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": 42})
        self.store.mark_outbox_sent(entry_id)

        self.assertEqual(self.restart().pending_outbox(), [])

    def test_the_operators_last_selection_survives_a_restart(self):
        self.store.set("selected_batch", 7)

        self.assertEqual(self.restart().get("selected_batch"), 7)


class ConcurrencyTest(StoreTestCase):
    """Each printer runs its own worker, and they all share one store.

    sqlite3 objects are not safe to use from several threads unless the caller
    says so and serialises access, and getting that wrong shows up as
    "database is locked" or "SQLite objects created in a thread..." halfway
    through a run rather than in development.
    """

    def run_threads(self, target, count=6):
        errors = []

        def wrapped(index):
            try:
                target(index)
            except Exception as error:  # noqa: BLE001 - the failure is the finding
                errors.append(error)

        threads = [threading.Thread(target=wrapped, args=(i,)) for i in range(count)]

        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()

        return errors

    def test_concurrent_writers_do_not_raise(self):
        def write(index):
            for n in range(25):
                self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": index * 100 + n})

        errors = self.run_threads(write)

        self.assertEqual(errors, [])

    def test_every_concurrent_confirmation_is_actually_stored(self):
        # A dropped one is a card that gets printed again.
        def write(index):
            for n in range(25):
                self.store.enqueue_outbox(st.OUTBOX_PRINTED, {"job_id": index * 100 + n})

        self.run_threads(write)

        self.assertEqual(self.store.outbox_depth(), 150)

    def test_workers_saving_their_own_jobs_do_not_collide(self):
        def write(index):
            for n in range(20):
                self.store.save_job(job(index * 100 + n), printer_name="printer-%d" % index)

        errors = self.run_threads(write)

        self.assertEqual(errors, [])
        self.assertEqual(len(self.store.jobs()), 120)

    def test_reading_while_another_thread_writes_does_not_raise(self):
        def churn(index):
            for n in range(30):
                if index % 2:
                    self.store.enqueue_outbox(st.OUTBOX_CONDITION, {"n": n})
                else:
                    self.store.pending_outbox()
                    self.store.outbox_depth()
                    self.store.jobs()

        self.assertEqual(self.run_threads(churn), [])


class KeyValueTest(StoreTestCase):
    def test_a_value_round_trips(self):
        self.store.set("selected_batch", 7)

        self.assertEqual(self.store.get("selected_batch"), 7)

    def test_a_missing_key_returns_the_default(self):
        self.assertIsNone(self.store.get("never_set"))
        self.assertEqual(self.store.get("never_set", "fallback"), "fallback")

    def test_types_are_preserved_rather_than_stringified(self):
        # A bool read back as the string "False" is truthy, which is how a
        # disabled feature quietly turns itself on.
        self.store.set("camera_enabled", False)
        self.store.set("threshold", 0.04)
        self.store.set("printers", ["left", "right"])

        self.assertIs(self.store.get("camera_enabled"), False)
        self.assertEqual(self.store.get("threshold"), 0.04)
        self.assertEqual(self.store.get("printers"), ["left", "right"])

    def test_setting_a_key_again_overwrites_it(self):
        self.store.set("selected_batch", 7)
        self.store.set("selected_batch", 8)

        self.assertEqual(self.store.get("selected_batch"), 8)

    def test_a_key_can_be_deleted(self):
        self.store.set("selected_batch", 7)
        self.store.delete("selected_batch")

        self.assertIsNone(self.store.get("selected_batch"))

    def test_deleting_an_unknown_key_is_harmless(self):
        self.store.delete("never_set")

    def test_a_corrupt_value_falls_back_to_the_default(self):
        self.store.set("selected_batch", 7)
        direct = sqlite3.connect(self.db_path)
        direct.execute("UPDATE kv SET value = 'not json' WHERE key = 'selected_batch'")
        direct.commit()
        direct.close()

        self.assertEqual(self.restart().get("selected_batch", 1), 1)

    def test_the_stored_form_is_json_so_a_human_can_read_the_file(self):
        # These get opened with a sqlite browser at 3am.
        self.store.set("selected_batch", 7)
        direct = sqlite3.connect(self.db_path)
        raw = direct.execute(
            "SELECT value FROM kv WHERE key = 'selected_batch'"
        ).fetchone()[0]
        direct.close()

        self.assertEqual(json.loads(raw), 7)


if __name__ == "__main__":
    unittest.main()


class HistoryTest(unittest.TestCase):
    """The local record of what happened, kept after the job is forgotten.

    `jobs` is working state and rows leave it as cards finish. This is what
    answers "what happened to badge 24-0031" at the station, weeks later, with
    no network and nobody logged into the admin panel.
    """

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.store = st.LocalStore(os.path.join(self.dir.name, "agent.db"))

        self.job = {
            "id": 41,
            "batch_id": 7,
            "printer": "ZXP Series 9",
            "expected": {"custom_id": "24-0031", "fursuit_name": "Tinnu"},
        }

    def tearDown(self):
        self.store.close()
        self.dir.cleanup()

    def test_a_card_is_recorded_with_its_badge_number_and_name(self):
        self.store.record("job", "printed", "firmware confirmed", job=self.job)

        row = self.store.history()[0]

        self.assertEqual(row["card_number"], "24-0031")
        self.assertEqual(row["fursuit_name"], "Tinnu")
        self.assertEqual(row["printer_name"], "ZXP Series 9")
        self.assertEqual(row["outcome"], "printed")

    def test_printer_faults_are_recorded_without_a_job(self):
        # "Why did the queue stop twenty minutes ago" is the other question
        # this table has to answer.
        self.store.record("printer", "card_jam", "Clear the jammed card.",
                          printer_name="ZXP Series 9")

        row = self.store.history()[0]

        self.assertEqual(row["kind"], "printer")
        self.assertEqual(row["outcome"], "card_jam")
        self.assertIsNone(row["job_id"])

    def test_history_outlives_the_job_row(self):
        self.store.save_job(self.job, "ZXP Series 9", 7)
        self.store.record("job", "printed", "", job=self.job)
        self.store.forget_job(41)

        self.assertIsNone(self.store.job(41))
        self.assertEqual(len(self.store.history()), 1)

    def test_newest_first(self):
        self.store.record("job", "printed", "one", job=self.job)
        self.store.record("job", "failed", "two", job=self.job)

        self.assertEqual([r["detail"] for r in self.store.history()], ["two", "one"])

    def test_searching_by_badge_number(self):
        other = dict(self.job, id=42, expected={"custom_id": "24-0099",
                                                "fursuit_name": "Someone"})
        self.store.record("job", "printed", "", job=self.job)
        self.store.record("job", "printed", "", job=other)

        found = self.store.history(search="24-0099")

        self.assertEqual(len(found), 1)
        self.assertEqual(found[0]["card_number"], "24-0099")

    def test_searching_by_fursuit_name(self):
        self.store.record("job", "printed", "", job=self.job)

        self.assertEqual(len(self.store.history(search="Tinnu")), 1)

    def test_history_survives_a_restart(self):
        self.store.record("job", "printed", "before the power cut", job=self.job)
        self.store.close()

        reopened = st.LocalStore(os.path.join(self.dir.name, "agent.db"))

        try:
            self.assertEqual(len(reopened.history()), 1)
        finally:
            reopened.close()

    def test_pruning_keeps_the_newest(self):
        # A season of conventions should not grow the file forever.
        for index in range(20):
            self.store.record("job", "printed", "card %d" % index, job=self.job)

        self.store.prune_history(keep=5)
        rows = self.store.history()

        self.assertEqual(len(rows), 5)
        self.assertEqual(rows[0]["detail"], "card 19")
