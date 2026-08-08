"""Closing the agent mid-batch must not reprint the card that already came out.

The server hands a job back once its lease lapses, because from where it sits a
silent agent is indistinguishable from a dead one. It cannot know whether a card
reached the output bin. This station can: it wrote down how far the job got.
"""

import unittest

import agent.worker as worker

from test_worker import WorkerTestCase, job


class AlreadyPrintedTest(WorkerTestCase):
    def setUp(self):
        super().setUp()
        self.api.queue.append(job(11, "24-0031"))

    def printed_but_never_reported(self):
        """The window that produces duplicates.

        The card comes out, then the confirmation cannot be delivered -- the
        agent was closed, or the network went. The server still has the job
        outstanding, so once the lease lapses it hands it back. The local row
        survives as `reported`, which is the evidence this station has and the
        server does not.
        """
        self.api.printed_error = worker.NetworkError("server unreachable")
        self.build().print_next()

        self.api.printed_error = None
        self.api.printing = []
        self.sent = []

    def test_the_first_run_prints_normally(self):
        outcome = self.build().print_next()

        self.assertEqual(worker.PRINTED, outcome.kind)
        self.assertEqual(1, len(self.sent))

    def test_a_returned_job_is_not_printed_a_second_time(self):
        self.printed_but_never_reported()
        self.api.queue.append(job(11, "24-0031"))   # the server hands it back

        self.build().print_next()

        self.assertEqual([], self.sent)

    def test_it_tells_the_server_the_card_exists(self):
        # The confirmation the server never received, sent again -- otherwise
        # the job sits pending and comes round once more.
        self.printed_but_never_reported()
        self.api.queue.append(job(11, "24-0031"))

        self.build().print_next()

        self.assertEqual(1, len(self.api.printed))
        self.assertEqual("recovered", self.api.printed[0]["completion_source"])

    def test_it_reports_the_card_as_printed_rather_than_failed(self):
        self.printed_but_never_reported()
        self.api.queue.append(job(11, "24-0031"))

        outcome = self.build().print_next()

        self.assertEqual(worker.PRINTED, outcome.kind)
        self.assertEqual([], self.api.failed)

    def test_a_card_this_station_never_printed_is_printed(self):
        # The guard must not swallow honest work: a job with no local history
        # is a job whose card does not exist.
        self.api.queue.append(job(12, "24-0032"))
        w = self.build()

        w.print_next()
        w.print_next()

        self.assertEqual(2, len(self.sent))

    def test_an_attempt_that_never_confirmed_is_not_treated_as_printed(self):
        # `printing` is written before the artwork is spooled, so it proves an
        # attempt, not a card. Reading it as evidence would skip real work.
        self.store.save_job(job(11, "24-0031"), "ZXP9-Left", 77, worker.JOB_PRINTING)

        self.build().print_next()

        self.assertEqual(1, len(self.sent))

    def test_an_unreadable_store_never_blocks_a_print(self):
        class Broken:
            def __getattr__(self, name):
                raise RuntimeError("disk gone")

        w = self.build()
        w.store = Broken()

        self.assertIsNone(w._already_printed(11))


if __name__ == "__main__":
    unittest.main()
