"""Which batch an unattended station may pick up on its own.

Deliberately stricter than what an operator may choose. The loop this prevents:
a card fails, markFailed() pauses the batch, the agent restarts it because
`start` allows Paused -> Printing, finds nothing pending, drops it, and picks
it straight back up.
"""

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from agent.autostart import (  # noqa: E402
    first_auto_startable, is_auto_startable, pending_jobs,
)


def batch(status="ready", jobs=3, printed=0, failed=0, id=1, name="Friday"):
    return {
        "id": id,
        "name": name,
        "status": status,
        "totals": {"jobs": jobs, "printed": printed, "failed": failed, "verified": 0},
    }


class PendingJobsTest(unittest.TestCase):
    def test_counts_what_is_left(self):
        self.assertEqual(pending_jobs(batch(jobs=5, printed=2, failed=1)), 2)

    def test_a_finished_batch_has_none_left(self):
        self.assertEqual(pending_jobs(batch(jobs=3, printed=3)), 0)

    def test_a_batch_with_no_totals_is_unknown_not_empty(self):
        # Refusing to print because a field is missing would idle the station
        # over a server change. Unknown means "try it".
        self.assertIsNone(pending_jobs({"id": 1}))

    def test_the_servers_own_pending_count_wins(self):
        # The API counts pending jobs directly, which is exactly what the agent
        # can claim; the arithmetic is only a fallback.
        counted = {"totals": {"jobs": 9, "printed": 0, "failed": 0, "remaining": 2}}

        self.assertEqual(pending_jobs(counted), 2)

    def test_it_never_goes_negative(self):
        self.assertEqual(pending_jobs(batch(jobs=1, printed=5)), 0)


class AutoStartableTest(unittest.TestCase):
    def test_a_ready_batch_with_work_is_taken(self):
        self.assertTrue(is_auto_startable(batch()))

    def test_a_paused_batch_is_never_resurrected(self):
        # markFailed() pauses the batch. Restarting it automatically is what
        # turned one failed card into an endless loop; whoever fixed the
        # printer decides when it goes again.
        self.assertFalse(is_auto_startable(batch(status="paused")))

    def test_a_batch_with_unknown_totals_is_still_tried(self):
        self.assertTrue(is_auto_startable({"id": 1, "status": "ready"}))

    def test_a_batch_with_nothing_left_is_not_taken(self):
        # Batch 11 in the field: printing, one failed job, nothing pending. It
        # stayed selectable and yielded nothing, forever.
        self.assertFalse(is_auto_startable(batch(status="printing", jobs=1, failed=1)))

    def test_our_own_printing_batch_with_work_is_resumed(self):
        self.assertTrue(is_auto_startable(batch(status="printing", jobs=3, printed=1)))

    def test_completed_and_cancelled_are_ignored(self):
        self.assertFalse(is_auto_startable(batch(status="completed", printed=3)))
        self.assertFalse(is_auto_startable(batch(status="cancelled")))

    def test_a_draft_is_not_taken(self):
        self.assertFalse(is_auto_startable(batch(status="draft")))


class FirstAutoStartableTest(unittest.TestCase):
    def test_it_picks_the_first_usable_batch(self):
        found = first_auto_startable([
            batch(id=1, status="paused"),
            batch(id=2, status="printing", jobs=1, failed=1),
            batch(id=3, status="ready"),
        ])

        self.assertEqual(found["id"], 3)

    def test_it_skips_the_batch_just_finished(self):
        found = first_auto_startable([batch(id=7), batch(id=8)], skip_id=7)

        self.assertEqual(found["id"], 8)

    def test_nothing_usable_returns_none(self):
        self.assertIsNone(first_auto_startable([batch(status="paused")]))

    def test_an_empty_list_returns_none(self):
        self.assertIsNone(first_auto_startable([]))
        self.assertIsNone(first_auto_startable(None))


if __name__ == "__main__":
    unittest.main()
