"""Local durability, so a network drop does not stop printing.

The convention network is a hotel network and it will go away at some point
during the weekend, probably while a queue of two hundred cards is halfway
through. Everything the agent needs to keep working through that lives here:

* the claimed job payload and the local path of its downloaded PDF, so a card
  already paid for and already rendered can still be printed while the server
  is unreachable, and so a restarted agent knows what it was in the middle of;
* an outbox of confirmations that could not be delivered. A printed card whose
  "printed" call failed must never be forgotten, or the badge gets printed a
  second time on the next pass. The outbox is the reason the agent can be
  offline for ten minutes and the server still ends up with the truth;
* a small key/value table for state that is not worth a table of its own, such
  as which batch the operator last selected.

SQLite because it is in the standard library, survives a power cut better than
a JSON file rewritten in place, and can be opened with any tool on the machine
when something needs explaining at 3am.
"""

from __future__ import annotations

import json
import sqlite3
import threading
from typing import Any, Dict, List, Optional

DB_FILENAME = "agent.db"

# What an outbox row is trying to deliver. Kept as plain strings so a row
# written by an older build still means something after an upgrade.
OUTBOX_PRINTED = "printed"
OUTBOX_FAILED = "failed"
OUTBOX_VERIFY = "verify"
OUTBOX_CONDITION = "condition"

SCHEMA = (
    """
    CREATE TABLE IF NOT EXISTS jobs (
        id            INTEGER PRIMARY KEY,
        batch_id      INTEGER,
        printer_name  TEXT,
        payload       TEXT NOT NULL,
        file_path     TEXT,
        status        TEXT NOT NULL DEFAULT 'claimed',
        claimed_at    TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
    )
    """,
    """
    CREATE TABLE IF NOT EXISTS outbox (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        kind        TEXT NOT NULL,
        payload     TEXT NOT NULL,
        attempts    INTEGER NOT NULL DEFAULT 0,
        last_error  TEXT,
        sent_at     TEXT,
        created_at  TEXT NOT NULL DEFAULT (datetime('now'))
    )
    """,
    # Partial index: the only query that matters is "what still needs sending",
    # and after a busy day most rows are sent and uninteresting.
    "CREATE INDEX IF NOT EXISTS outbox_pending ON outbox (id) WHERE sent_at IS NULL",
    # What happened, kept after the job itself is forgotten.
    #
    # `jobs` is working state and rows leave it as cards finish. This is the
    # record somebody reads afterwards to answer "what happened to badge
    # 24-0031", or "why did the queue stop twenty minutes ago", without going
    # to the server or reading a log file over AnyDesk.
    """
    CREATE TABLE IF NOT EXISTS history (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        at            TEXT NOT NULL DEFAULT (datetime('now')),
        kind          TEXT NOT NULL,
        job_id        INTEGER,
        batch_id      INTEGER,
        card_number   TEXT,
        fursuit_name  TEXT,
        printer_name  TEXT,
        outcome       TEXT,
        detail        TEXT
    )
    """,
    "CREATE INDEX IF NOT EXISTS history_recent ON history (id DESC)",
    """
    CREATE TABLE IF NOT EXISTS kv (
        key    TEXT PRIMARY KEY,
        value  TEXT NOT NULL
    )
    """,
)


class OutboxEntry:
    """One undelivered confirmation.

    A plain object rather than a sqlite3.Row so callers are not holding a cursor
    row while the connection is being used by another worker.
    """

    __slots__ = ("id", "kind", "payload", "attempts", "last_error", "created_at")

    def __init__(self, id, kind, payload, attempts, last_error, created_at):
        self.id = id
        self.kind = kind
        self.payload = payload
        self.attempts = attempts
        self.last_error = last_error
        self.created_at = created_at

    def __repr__(self) -> str:
        return "OutboxEntry(id=%r, kind=%r, attempts=%r)" % (self.id, self.kind, self.attempts)


class LocalStore:
    """The agent's own database.

    One connection shared across threads with a lock around it. Each printer
    runs its own worker, but they touch the store briefly and rarely (once per
    card, plus outbox flushes), so contention is irrelevant and a single
    connection avoids the "database is locked" dance that separate writers on
    the same file produce.
    """

    def __init__(self, path: Optional[Any] = None):
        if path is None:
            from .config import config_dir

            path = config_dir() / DB_FILENAME

        self.path = str(path)
        self._lock = threading.RLock()
        self._connection = sqlite3.connect(self.path, check_same_thread=False)
        self._connection.row_factory = sqlite3.Row

        self._migrate()

    def _migrate(self) -> None:
        with self._lock:
            try:
                # Survives a power cut mid-write far better than the rollback
                # journal, and these boxes get switched off at the wall.
                self._connection.execute("PRAGMA journal_mode=WAL")
            except sqlite3.DatabaseError:
                pass

            for statement in SCHEMA:
                self._connection.execute(statement)

            self._connection.commit()

    def close(self) -> None:
        with self._lock:
            self._connection.close()

    # ------------------------------------------------------------------
    # Cached jobs
    # ------------------------------------------------------------------

    def save_job(
        self,
        job: Dict[str, Any],
        printer_name: Optional[str] = None,
        batch_id: Optional[int] = None,
        status: str = "claimed",
    ) -> int:
        """Remember a claimed job, keyed by the server's job id.

        Upsert rather than insert: claiming the same job twice after a restart
        (the server hands held jobs back on /jobs/held) must not produce two
        rows and two cards.
        """
        job_id = int(job["id"])
        payload = json.dumps(job)

        with self._lock:
            self._connection.execute(
                """
                INSERT INTO jobs (id, batch_id, printer_name, payload, status)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET
                    batch_id = excluded.batch_id,
                    printer_name = excluded.printer_name,
                    payload = excluded.payload,
                    status = excluded.status,
                    updated_at = datetime('now')
                """,
                (job_id, batch_id, printer_name, payload, status),
            )
            self._connection.commit()

        return job_id

    def job(self, job_id: int) -> Optional[Dict[str, Any]]:
        """The cached job record, or None. Includes the local file path, so a
        worker resuming after a restart does not re-download a PDF it has."""
        with self._lock:
            row = self._connection.execute(
                "SELECT * FROM jobs WHERE id = ?", (int(job_id),)
            ).fetchone()

        return _job_row(row)

    def jobs(self, status: Optional[str] = None) -> List[Dict[str, Any]]:
        with self._lock:
            if status is None:
                rows = self._connection.execute("SELECT * FROM jobs ORDER BY id").fetchall()
            else:
                rows = self._connection.execute(
                    "SELECT * FROM jobs WHERE status = ? ORDER BY id", (status,)
                ).fetchall()

        return [_job_row(row) for row in rows]

    def set_job_file(self, job_id: int, file_path: str) -> None:
        with self._lock:
            self._connection.execute(
                "UPDATE jobs SET file_path = ?, updated_at = datetime('now') WHERE id = ?",
                (str(file_path), int(job_id)),
            )
            self._connection.commit()

    def set_job_status(self, job_id: int, status: str) -> None:
        with self._lock:
            self._connection.execute(
                "UPDATE jobs SET status = ?, updated_at = datetime('now') WHERE id = ?",
                (status, int(job_id)),
            )
            self._connection.commit()

    def forget_job(self, job_id: int) -> None:
        """Drop a job once it is fully settled server side.

        Deliberately not called until the confirmation has actually been
        delivered: the cached record is what stops a card being reprinted after
        a crash.
        """
        with self._lock:
            self._connection.execute("DELETE FROM jobs WHERE id = ?", (int(job_id),))
            self._connection.commit()

    # ------------------------------------------------------------------
    # Outbox
    # ------------------------------------------------------------------

    def enqueue_outbox(self, kind: str, payload: Dict[str, Any]) -> int:
        """Record a confirmation that still has to reach the server.

        Called on every failed confirmation. Also worth calling *before* the
        attempt for anything that must not be lost, since a row already sent is
        cheap to mark and a lost "printed" costs a reprinted card.
        """
        with self._lock:
            cursor = self._connection.execute(
                "INSERT INTO outbox (kind, payload) VALUES (?, ?)",
                (kind, json.dumps(payload)),
            )
            self._connection.commit()

            return int(cursor.lastrowid)

    def pending_outbox(self, limit: int = 100) -> List[OutboxEntry]:
        """Undelivered rows, oldest first, so confirmations arrive in the order
        the cards actually printed."""
        with self._lock:
            rows = self._connection.execute(
                "SELECT * FROM outbox WHERE sent_at IS NULL ORDER BY id LIMIT ?",
                (int(limit),),
            ).fetchall()

        return [_outbox_row(row) for row in rows]

    def mark_outbox_sent(self, entry_id: int) -> None:
        with self._lock:
            self._connection.execute(
                "UPDATE outbox SET sent_at = datetime('now'), last_error = NULL WHERE id = ?",
                (int(entry_id),),
            )
            self._connection.commit()

    def bump_outbox_attempt(self, entry_id: int, error: str = "") -> int:
        """Record a failed delivery and return the new attempt count.

        The count is what lets the UI say "37 confirmations waiting, last error
        connection refused" instead of the operator discovering days later that
        the server never heard about half the run.
        """
        with self._lock:
            self._connection.execute(
                "UPDATE outbox SET attempts = attempts + 1, last_error = ? WHERE id = ?",
                (str(error)[:1000], int(entry_id)),
            )
            self._connection.commit()

            row = self._connection.execute(
                "SELECT attempts FROM outbox WHERE id = ?", (int(entry_id),)
            ).fetchone()

        return int(row["attempts"]) if row else 0

    # -- history ---------------------------------------------------------

    def record(
        self,
        kind: str,
        outcome: str = "",
        detail: str = "",
        job: Optional[Dict[str, Any]] = None,
        job_id: Optional[int] = None,
        printer_name: str = "",
        batch_id: Optional[int] = None,
    ) -> int:
        """Write one line of history.

        The badge number and fursuit name are pulled off the job and stored
        flat, on purpose: the point of this table is that somebody can answer
        "what happened to 24-0031" from the station itself, weeks later, after
        the job row is long gone and possibly with no network.
        """
        card_number = ""
        fursuit_name = ""

        if job:
            expected = job.get("expected") or {}

            if isinstance(expected, dict):
                card_number = expected.get("custom_id") or ""
                fursuit_name = expected.get("fursuit_name") or ""

            job_id = job_id if job_id is not None else job.get("id")
            batch_id = batch_id if batch_id is not None else job.get("batch_id")
            printer_name = printer_name or (job.get("printer") or "")

        with self._lock:
            cursor = self._connection.execute(
                """
                INSERT INTO history
                    (kind, job_id, batch_id, card_number, fursuit_name,
                     printer_name, outcome, detail)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (kind, job_id, batch_id, card_number, fursuit_name,
                 printer_name, outcome, detail),
            )
            self._connection.commit()

            return int(cursor.lastrowid)

    def history(self, limit: int = 500, search: str = "") -> List[Dict[str, Any]]:
        """Recent history, newest first.

        `search` matches the badge number, the fursuit name or the detail, so
        somebody holding a card can type the number on it and see its story.
        """
        sql = "SELECT * FROM history"
        params: List[Any] = []

        if search:
            sql += (" WHERE card_number LIKE ? OR fursuit_name LIKE ?"
                    " OR detail LIKE ? OR outcome LIKE ?")
            params.extend(["%%%s%%" % search] * 4)

        sql += " ORDER BY id DESC LIMIT ?"
        params.append(int(limit))

        with self._lock:
            return [dict(row) for row in self._connection.execute(sql, params).fetchall()]

    def prune_history(self, keep: int = 5000) -> int:
        """Drop the oldest rows past `keep`, so a season does not grow forever."""
        with self._lock:
            cursor = self._connection.execute(
                "DELETE FROM history WHERE id NOT IN "
                "(SELECT id FROM history ORDER BY id DESC LIMIT ?)",
                (int(keep),),
            )
            self._connection.commit()

            return cursor.rowcount or 0

    def outbox_depth(self) -> int:
        """How far behind we are. Shown in the UI as a health signal."""
        with self._lock:
            row = self._connection.execute(
                "SELECT COUNT(*) AS n FROM outbox WHERE sent_at IS NULL"
            ).fetchone()

        return int(row["n"]) if row else 0

    def prune_outbox(self, keep: int = 500) -> int:
        """Discard old delivered rows. Returns how many went.

        Only ever touches rows that were confirmed sent; a pending row is never
        deleted by anything but successful delivery.
        """
        with self._lock:
            cursor = self._connection.execute(
                """
                DELETE FROM outbox
                WHERE sent_at IS NOT NULL
                  AND id NOT IN (
                      SELECT id FROM outbox WHERE sent_at IS NOT NULL ORDER BY id DESC LIMIT ?
                  )
                """,
                (int(keep),),
            )
            self._connection.commit()

            return cursor.rowcount or 0

    # ------------------------------------------------------------------
    # Key/value
    # ------------------------------------------------------------------

    def set(self, key: str, value: Any) -> None:
        """Store a small piece of state. Values are JSON, so a bool stays a bool."""
        with self._lock:
            self._connection.execute(
                "INSERT INTO kv (key, value) VALUES (?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (key, json.dumps(value)),
            )
            self._connection.commit()

    def get(self, key: str, default: Any = None) -> Any:
        with self._lock:
            row = self._connection.execute(
                "SELECT value FROM kv WHERE key = ?", (key,)
            ).fetchone()

        if row is None:
            return default

        try:
            return json.loads(row["value"])
        except ValueError:
            return default

    def delete(self, key: str) -> None:
        with self._lock:
            self._connection.execute("DELETE FROM kv WHERE key = ?", (key,))
            self._connection.commit()


def _job_row(row: Optional[sqlite3.Row]) -> Optional[Dict[str, Any]]:
    if row is None:
        return None

    try:
        payload = json.loads(row["payload"])
    except ValueError:
        payload = {}

    return {
        "id": row["id"],
        "batch_id": row["batch_id"],
        "printer_name": row["printer_name"],
        "payload": payload,
        "file_path": row["file_path"],
        "status": row["status"],
        "claimed_at": row["claimed_at"],
    }


def _outbox_row(row: sqlite3.Row) -> OutboxEntry:
    try:
        payload = json.loads(row["payload"])
    except ValueError:
        payload = {}

    return OutboxEntry(
        id=row["id"],
        kind=row["kind"],
        payload=payload,
        attempts=row["attempts"],
        last_error=row["last_error"],
        created_at=row["created_at"],
    )
