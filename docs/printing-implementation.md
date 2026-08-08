# Printing rework: implementation

Companion to `docs/printing.md`, which covers the design. This one is for whoever has to build,
extend or debug it. Branch: `printing-rework`.

## Status

| Component | State |
|---|---|
| Schema, batches, leases, verification | done |
| Atomic claim, lease reaper | done |
| Print-file split, one-shot generation | done |
| Image preparation and downscaling | done |
| Invalidation on change | done |
| Immutable batches, badge lock, cancel | done |
| Agent API (Sanctum machine tokens) | done |
| SNMP status layer, fault-vocabulary journal | done |
| Multi-printer config model | done |
| Agent UI: console, batch page, setup, diagnostics | done |
| Camera preview and calibration page | done |
| Worker loop, reprint decision, wired to the console | done |
| Agent HTTP client, SQLite outbox, Pushover | done |
| Windows spooler path and PDF rasterisation | written, **never run on hardware** |
| Receipt lane in claim API | done |
| Filament batch UI | done |
| QZ removal | done |
| Camera blank-card check, Telegram channel | done |

Tests: 214 passed / 10 skipped PHP (Pest), 568 Python (unittest). The skips are pre-existing and unrelated: Fiskaly needs the external service, the DSFinV-K suite is disabled upstream.

**The honest gap.** Every Python test uses fakes. Nothing in the print path, the
camera, or PDF rasterisation has run against the real printer, and `pywin32`,
`opencv-python` and `pypdfium2` cannot be installed on a Mac to change that. The
remaining risk lives entirely in that gap rather than in coverage. See
"First run on the Windows box" below.

## Server

### Data model

`print_batches` — an immutable run. `status` is `PrintBatchStatusEnum`. Denormalised counters
(`total_jobs`, `printed_count`, `verified_count`, `failed_count`) so progress polling does not
aggregate over every job.

`print_jobs` gains:

| Column | Why |
|---|---|
| `print_batch_id`, `sequence` | membership and frozen print order |
| `lease_expires_at`, `attempt_count` | survive a dead agent |
| `completion_source` | how we know it finished |
| `verified_print_at`, `verification_source`, `verified_by_id` | whether the right card came out |
| `firmware_job_id`, `firmware_job_uuid` | tie our job to the printer's own job table |

`badges` gains `print_file_path`, `print_file_hash`, `print_file_renderer`,
`print_file_generated_at`, `verified_print_at`, `printing_locked_at`.

`printers` gains `condition`, `condition_message`, `condition_reported_at`, `cards_remaining`,
`cards_capacity`, `condition_raw`.

All migrations are `SchemaGuard`-guarded per the repo rule, and were verified idempotent by deleting
their `migrations` rows and re-running `up()` against an already-migrated database.

### Print files

`GenerateBadgePrintFileJob` renders one badge. Idempotent via `inputHash()`, which fingerprints the
renderer plus every field that reaches the card. `badges:generate-print-files --event= --force
--queue --limit=` does a whole event.

`GenerateBadgePrintFileJob::invalidateFor()` marks a badge's file stale by clearing the hash. Called
from `BadgeObserver` (on `custom_id`, `dual_side_print`) and `FursuitObserver` (on `name`,
`species_id`, `image`, `catch_code`, `catch_em_all`). Both skip badges that are printing-locked, and
the job itself refuses to render a locked badge, so the guard holds however it is reached.

It marks stale rather than rendering on the spot. Rendering a PDF has no business happening inside
the request where somebody saved a form, and an attendee adjusting their badge five times in a row
should not queue five renders. The next `badges:generate-print-files` pass picks it up.

**This means a batch must be built from freshly generated files.** Run the generation command before
building a batch; wiring that into the Filament batch action is outstanding work.

### Images

`App\Badges\ImagePreparer` sits between attendee uploads and the renderers.

The composite is already correct: 1024 × 648 px on an 86.7 × 54.86 mm card is exactly 300 dpi, which
is what the ZXP9 prints. The waste was entirely upstream. Both renderers used to call
`imagine->open($signedS3Url)` **and** `getimagesize($signedS3Url)` — two full HTTP downloads of a
multi-megabyte upload per card — then decode at full resolution before scaling to 350 × 455.

`ImagePreparer` streams the object to a local temp file once, inspects it locally, rejects anything
over `MAX_SOURCE_PIXELS` (50 MP) before decoding, scales, and cleans up in a `finally`. The
megapixel guard matters: a JPEG that decompresses to gigabytes is either a mistake or an attack, and
either way must not take the print queue down mid-event.

### Batches

`PrintBatch::build()` is the only way to populate a batch. It sorts, creates the jobs with a frozen
`sequence`, and stamps `printing_locked_at` on every badge in one transaction.

Sort order is descending by attendee then descending by badge number, so cards land on top of each
other and the finished stack reads ascending from the top. The two implementations this replaces
disagreed with each other: the old polling endpoint sorted attendee ascending with badge descending,
while `PrintJob::scopePrioritized` sorted both ascending.

`cancel()` cancels everything not yet printed and **unlocks badges that never produced a card**, so
an attendee is not punished for a run that was abandoned before their card printed. Badges that did
print stay locked.

### Claiming

```php
DB::transaction(function () {
    $batch = PrintBatch::whereKey($id)->lockForUpdate()->first();
    if (! $batch?->status->isClaimable()) return null;
    $job = $batch->printJobs()->where('status', Pending)
        ->orderBy('sequence')->lockForUpdate()->first();
    $job?->claim($machine, 180);
});
```

The row lock is the fix for duplicate prints. The browser client it replaces polled every four
seconds with no in-flight guard and would hand the same card out twice.

`printing:reap-leases` runs every minute. Expired lease → back to `pending`; past three attempts →
failed, and the batch pauses.

### Agent API

`routes/print-agent.php`, prefix `/api/print-agent`, `auth:sanctum` with a token on `Machine`
(`HasApiTokens`). Session auth is unusable from a desktop app.

Every handler resolves `{job}` and `{batch}` through `AgentController::jobForMachine()` /
`batchForMachine()`, which scope to the caller. The endpoints this replaces bound `{job}` straight
off the URL with no ownership check at all.

```
GET  /config                      machine, printers, lease length
POST /printers                    register operator-mapped printers
POST /printers/condition          SNMP-derived condition
GET  /batches                     selectable for this machine
POST /batches/{batch}/start       assign printer, begin
POST /batches/{batch}/pause       operator pause
POST /batches/{batch}/resume
POST /batches/{batch}/cancel      cancel remaining work
GET  /jobs/held                   what this machine currently holds
POST /jobs/claim                  atomic, one card
POST /jobs/{job}/heartbeat        extend lease
POST /jobs/{job}/printing
POST /jobs/{job}/printed          requires completion_source
POST /jobs/{job}/failed           pauses the batch
POST /jobs/{job}/verify           camera or operator verdict only
```

The claim response carries the S3 URL, paper, duplex flag and the expected `custom_id` and fursuit
name, so the agent can verify locally without a second call. Every field is null-safe: a
soft-deleted badge used to 500 the whole polling endpoint and stop every printer on the machine.

## Agent (`print-agent/`)

Python 3.8.10, the last release supporting Windows 7. Pin every dependency to a `cp38` wheel.

```
agent/config.py      %APPDATA%\BadgePrintAgent\config.json, multi-printer
agent/zebra.py       SNMP read + condition classification
agent/vocabulary.py  journal of readings we cannot explain
agent/monitor.py     the may-I-print gate
agent/printing.py    Windows spooler (enumeration so far)
agent/ui/app.py      tkinter
tools/snmp_probe.py  live OID change watcher
tools/journal_report.py  summarise the journal for handover
```

### Configuration

`AgentConfig.printers` is a list of `PrinterBinding`: Windows name, role (`card`/`receipt`), label,
its own SNMP host and community, its own `CameraConfig`. A station can run two card printers plus a
thermal receipt printer, each with an independent worker, so a jam on one does not stop the others.

`CameraConfig` holds one `Zone` rectangle (`card`, over the output bin) and named `Checkpoint`
points, all stored as fractions of frame size so calibration survives a webcam swap. Two point
purposes remain: `card_ink`, several of which may be placed, and `tray_full`, one per printer.

They are calibrated differently and this is the distinction to keep straight. A `tray_full` point
compares against a colour captured while the tray was empty, so it must be calibrated before it
means anything. A `card_ink` point asks whether a spot is bare card stock, which is absolute: there
is no such thing as a differently-coloured blank card, so there is nothing to store and it is never
reported as uncalibrated. The calibration page shows ink points a live `BLANK CARD` / `ink` readout
with the measured value and saturation instead, so an operator can hold a card under the lens and
confirm the point is actually on it.

Config loading drops unknown keys, so an old file still starts the agent after a rename. That is
what retires `chute_blocked`, `ocr_custom_id` and `ocr_name` without migration.

### SNMP

Zebra never published a MIB for the card printer line, so `10642.8` was mapped by probing a live
unit. The find that matters is `10642.8.5`, a rolling window of the last seven jobs carrying job id,
UUID, state (`done_ok`, `cleaning_up`) and physical card location (`not_in_printer`,
`transferring`). That is per-card confirmation straight from firmware, with no driver involved.
Ribbon level comes from the standard Printer MIB at `43.11.1.1.9`.

`classify()` is a pure function over a `Reading` so it can be tested against recorded faults.
**Anything unrecognised classifies as `unknown`, which is a stop.**

Two things worth knowing when working on this:

- net-snmp wraps string values in double quotes. Real captured hardware output classified a healthy
  printer as `unknown` until `clean_value()` was added. Synthetic tests missed it entirely; feeding
  the real baseline walk through the classifier is what caught it.
- The fault vocabulary in `ALARM_CONDITIONS` is partly guessed. Guesses fail safe (unmatched →
  `unknown` → stop) but a guess that accidentally matches something milder could mislabel. Once real
  fault logs arrive, delete the guesses and keep only observed strings.

`vocabulary.ConditionJournal` writes any unexplained reading, with the full walk, to
`condition-journal.jsonl`, deduplicated by reading shape so a ten-minute jam writes one row.
`tools/journal_report.py` summarises it for handover. Journal failures are swallowed: diagnostics
must never be able to stop the printer.

## First run on the Windows box

Do these in order. The first one settles the biggest unknown in the project, so
do not skip ahead to wiring up a printer before it passes.

1. **Python 3.8.10** — the last release that installs on Windows 7 at all. Also
   confirm SP1 has KB2999226 (UCRT) and the VC++ 2015-2022 redistributable, or
   none of the binary wheels will load and every failure below will be a red
   herring.
2. **`pip install -r print-agent/requirements.txt`**, then immediately:
   ```
   py -3.8 -c "import pypdfium2; print(pypdfium2.V_PYPDFIUM2)"
   ```
   `pypdfium2==4.30.0` bundles a pdfium built from a Chromium tree that dropped
   Windows 7 support, so the DLL may refuse to load outright. If it does, drop to
   pypdfium2 2.x/3.x, which bundles a pre-M110 build and needs a small change to
   `agent/render.py`.
3. **Render one real badge PDF** with `render_pdf()` and look at the bitmap
   before involving a printer.
4. **`list_printers()`**, then one card through `print_pages()`. If `pywin32==228`
   misbehaves, 302 is the next thing to try; the pin is chosen for the OS, not
   the Python version.
5. **SNMP against the live printer**, then induce faults one at a time (cover
   open, ribbon out, empty hopper, jam) with `tools/snmp_probe.py` running. That
   turns the guessed strings in `ALARM_CONDITIONS` into observed ones. Until
   then every unmapped fault classifies as `unknown`, which stops the queue: safe
   but noisy.
6. **Camera last**, since printing must never depend on it.

## Remaining work

1. **Prove it on hardware.** Everything above is built and tested against fakes. The sequence in
   "First run on the Windows box" is the real remaining work, and until it passes none of this is
   known to print a card.
2. **Learn the fault vocabulary.** `ALARM_CONDITIONS` in `agent/zebra.py` is still part guesswork.
   Guesses fail safe, but once real fault logs exist the guessed entries should be deleted and
   replaced with observed strings. `tools/journal_report.py` produces the handover.
3. **Packaging.** PyInstaller onto a single exe, built by a Windows runner, so the station does not
   need a Python install.
4. **A receipt worker.** The receipt lane exists server-side and `PrintWorker` handles one printer;
   nothing yet runs a second worker for the thermal printer alongside the card one.
5. **QZ removal, last.** `QZPrintService.vue`, `QzStatusIndicator.vue`, `QzCertController`, the
   `qz.*` routes, the `printers/*` job routes, the `machines.qz_*` columns, `QzConnectionStatusEnum`,
   the `qz-tray` package and `MachineQzStatusTest`. Removing any of it before the agent has printed
   a real card leaves no way to print at all.

## Gotchas

- Migrations must be idempotent. See the repo rule in `CLAUDE.md`.
- `Badge::factory()` randomises `status_fulfillment`; pin it when a test depends on policy outcomes.
- `Event::getActiveEvent()` is simply the latest by `starts_at`, and the factory sets `ends_at` to
  now, so most policy checks fail by default in tests unless the event is extended.
- Apple's system Tk 8.5 cannot render the agent UI on macOS; even a bare five-line Tk window hangs.
  Review the UI on Windows, or install a modern Tk (`brew install python-tk@3.13`).
