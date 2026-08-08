# Badge printing

Replaces the QZ Tray browser bridge. Laravel owns the queue and renders the artwork; a native
Windows agent drives the Zebra ZXP Series 9 on the convention LAN and proves each card came out.

## Why it was rebuilt

The old system assumed the printer driver told the truth. The ZXP driver reports "online"
unconditionally and none of its status codes work, so the browser client guessed: it force-marked
jobs printed on a ten second timer, mapped a spooler-deleted job to success, and polled with no
in-flight guard so the same card printed twice. When the printer jammed or ran out of ribbon the job
was silently dropped and recorded as printed. Badges were lost.

The rule that follows from that: **nothing is printed until proven printed, and nothing unknown is
assumed safe.**

## Ownership

```
POS browser  <--- HTTPS ---  Laravel  <--- HTTPS (agent-initiated) ---  agent  --- SNMP/LAN ---  ZXP9
                                                                          |
                                                                          +--- Windows spooler ---> ZXP9
```

Laravel is on the public internet, the printer is on a private LAN. **Laravel never talks to the
printer.** The agent reads the hardware and uploads a summarised condition.

## Print files

All rendering is server side. `GenerateBadgePrintFileJob` renders a badge to PDF and records
`print_file_path`, `print_file_hash`, `print_file_renderer` and `print_file_generated_at` on the
badge. `badges:generate-print-files --event=` does a whole event in one pass.

`print_file_hash` fingerprints every input that affects the artwork. When a badge or its fursuit
changes the hash is cleared, so the next generation pass re-renders it and a batch can never be
built from outdated artwork. Regeneration is refused once the badge is locked into a batch.

**Images are downscaled before they reach the PDF.** Attendee uploads are arbitrarily large and
embedding a 5 MB original produced PDFs that choked the printer. The card is CR80 (85.6 × 54 mm) and
the ZXP9 prints at 300 dpi, so anything beyond roughly 1013 × 640 px is invisible on the card and
costs nothing but trouble. See `App\Badges\ImagePreparer`.

## Starting a run

**What goes into a run.** The badge list's tick boxes cannot cross a page, which is deliberate for
every bulk action in `/admin`. The print run is the one exception: with the page fully ticked, the
bulk bar offers **Select all N matching the filter**, and the action then posts `all: true` plus the
list's own query string instead of a list of ids. `BadgeController::matchingIds()` re-narrows through
the same `Table` that drew the page, so what is queued is by construction what was on screen. The
event scope rides in the session and cannot be widened by the forwarded query.

A run started that way is capped at 2000 badges and the toast says so when the cap bites, because a
run silently trimmed to a cap reports as the whole filter and the remainder is only discovered
missing at the desk. Only actions declaring `Action::selectAll()` are offered past the page: the bulk
fulfillment write bypasses the state machine and stays page-only.

Pressing Print does not print, and does not render. The request opens an empty `Draft` batch and
dispatches `PrepareBadgePrintBatchJob` on the `badge-render` queue; everything expensive happens
there. The operator gets the batch back immediately and watches it turn from preparing to ready.

The job does three things in order, and the order is the point:

1. Moves the badges to `Processing`, in one transaction. That allocates `custom_id`, which is
   printed on the card, so it has to happen before any rendering.
2. Renders anything whose artwork is missing or stale, outside any transaction. Row locks are not
   held across minutes of image work.
3. Commits the jobs, locks the badges, names the run after its attendee range and marks it `Ready`,
   in one transaction.

**A preparation that fails undoes itself.** The badges it moved go back to `Pending` (keeping their
`custom_id`), and the batch is cancelled carrying the reason, so a failed run is visible instead of
silent. This holds when the worker is killed outright: the job's `failed()` hook does the same
cleanup, working from the list of badges the job recorded before it started rendering, so it can
never return a badge that some other run had already put into `Processing`. A badge is left alone
only if a card is genuinely on its way for it - an outstanding job - not merely because it printed
at some point in the past.

This is the fix for a real outage. Rendering used to happen inline, in the request: a bulk print of
unrendered badges spent seconds per card on an S3 read, a GD decode and an mpdf render, and blew
PHP's 30 second limit partway through. What it left behind was worse than the error - every badge in
the selection sat in `Processing` with a `custom_id`, no batch and no jobs, reading as "sent to the
printer" with no card ever coming.

A `Draft` batch is inert: `scopeSelectable()` and `isClaimable()` both ignore it, so no agent can
claim a card out of a run that is still being prepared.

**Nothing prints without the `badge-render` supervisor** in `config/horizon.php`. It is what turns a
Draft batch into a run an agent can claim.

## Batches

A batch is a set of cards printed as one run. **Batches are immutable**: contents are fixed by
`PrintBatch::commitBadges()`, once, and nothing can be added afterwards.

Committing a badge to a batch sets `badges.printing_locked_at`. From that moment the attendee cannot
edit or delete it, because the artwork is already rendered and an edit would put a card in the stack
that no longer matches the order.

A printer works one batch to completion before starting another. Any fault pauses the batch;
nothing drains past it.

Cancelling a batch (possible from the agent) cancels every job not yet printed. Badges that never
produced a card are unlocked and handed back to their owner; badges that did print stay locked.

Ordering is descending by attendee, then descending by badge number, so cards land on top of each
other and the finished stack reads ascending from the top.

## Who started the run, and what they are told

A batch records two owners, because two different tables sign work in:

- `created_by_id` -> `users`, the admin accounts behind the `/admin` panel.
- `created_by_staff_id` -> `staff`, the desk clerk the POS authenticates on the `machine-user`
  guard. `auth()->id()` is null inside the POS, so every POS-queued batch used to have no owner at
  all.

The POS turns that attribution into feedback for the clerk who is standing at the counter with the
attendee:

- **My Print Jobs** (`/pos/my-prints`, `F7`) lists only that clerk's runs, with each card linking
  through to the attendee page. The full print queue (`F4`) is still every job on every printer,
  which is the print operator's view.
- The dashboard shows a notification per run of theirs that has stopped moving: completed, paused
  behind a failed card, or cancelled. Clicking it dismisses it and opens the attendee waiting for
  that card. Running and ready batches say nothing, because "still going" is not news.
- Dismissal stores `desk_dismissed_status`, the status that was acknowledged, rather than a
  timestamp. A run dismissed while paused therefore speaks again once it completes, which a
  boolean could not express.

`DeskPrintNotifications` builds both payloads; `MyPrintsController` serves the page and the
dismissals. A clerk can only dismiss their own runs: the notification is the news somebody else may
still be waiting on.

## Job lifecycle

```
pending -> queued -> printing -> printed
   ^         |          |
   |         v          v
   +----- (lease expiry) ---- failed -> retrying -> queued
```

**Leases.** An agent claims a job for a bounded window and renews while the card prints. If the
agent or the Windows host dies, `printing:reap-leases` returns the job to the queue rather than
leaving it stranded. Past three attempts it fails and pauses the batch.

**Claims are atomic.** `PrintBatch::claimNextJob()` locks the row, so two agents can never be handed
the same card.

**Reset to pending.** The bulk action on `/admin/print-jobs` returns a selection of unfinished jobs
to `Pending` in place, keeping sequence and batch, and clearing whatever the previous state left
behind: the lease and the machine on a claimed or mid-card job, the error text and attempt count on
a failed one. It is the repair for a run that stopped moving - a dead agent whose lease has not
expired yet, or a batch of cards that failed for a reason the operator has since fixed - where
`Retry` is the wrong shape because it makes a *new* job per card. Printed and cancelled jobs cannot
be reset, and one of them in a selection resets nothing at all. It does not resume a paused batch;
the toast says so.

**Completion needs evidence.** `Printed` is unreachable without a `completion_source`:

| Source | Meaning |
|---|---|
| `firmware` | The printer's own job table reported `done_ok`. Strongest. |
| `spooler_only` | Windows accepted it and nothing contradicted it. Not proof a card exists. |
| `operator` | A human said so. |

**Verification is separate.** Whether a job finished and whether the right card came out are
different questions answered by different calls. `verified_print_at` and `verification_source`
(`camera` or `operator`) are set by their own endpoint. A job can be printed and unverified.

No camera images are ever uploaded to the server. Only the verdict. (The optional Telegram channel
posts photos to a chat, which is a separate, opt-in channel; see below.)

**The desk check-off** is the third way a card gets verified, and the only one that proves the card
is in the building. `/pos/verification` (F8) is a numpad, a running list and an undo: somebody reads
the number off every card in the crate and types it, and each entry stamps `verified_print_at`
through `PrintJob::markVerified()` with `verification_source = operator`, exactly as the agent's own
operator verdict does. What is left over at the end of the crate - printed, never checked off - is
what never came out of the printer, and is found in `/admin` by filtering the badge list on
**Print Verified = Not verified** plus the fulfillment status and the attendee range that crate
covers.

Two rules on that screen, both learned from cards going out twice:

- A bare number is copy 1. `1234` checks off `1234-1`; a second copy has to be typed as `1234-2`,
  because nothing on the screen can tell which copy is in the operator's hand.
- The stamp is never cleared by a later reprint. It records that the card was seen once. The undo
  button is the only thing that clears it, for the number typed off the wrong card.

The counters read "printed" off the fulfillment state, never off `badges.printed_at`. Only the
`ToPrinted` transition stamps that column and the print pipeline does not go through it - a finished
job calls `promoteBadgeToReadyForPickup()` - so on live data the timestamp is null for every card the
agent printed. The screen counted zero cards against a full crate until that was fixed. The list
below the counters is deliberately wider than them: it shows every card checked off at this event,
including ones outside the desk's crate, because anything the field accepts has to appear there.

The screen is also shaped nothing like the dashboard, which is one keystroke away and is also a
number field over a keypad. An attendee number typed into the wrong one would check a card off
without anybody noticing, so this one wears a banner, puts the list where the dashboard puts the
keypad, and shrinks the keypad to a touchscreen fallback.

The auto-lock is suspended on that route alone. Working a crate is minutes of handling cards with no
keyboard or mouse in between, and locking there discards the list of what was already checked; the
page polls the server every minute to hold the session instead. The screen shows no attendee data
and no till, so the lock protects nothing there.

## Printer condition

The agent polls SNMP and reduces the reading to a `PrinterConditionEnum` case, which the POS shows
staff. Stops: `ribbon_out`, `film_out`, `cards_out`, `card_jam`, `cover_open`, `reject_bin_full`,
`service_required`, `offline`, `unknown`. Warnings: `ribbon_low`, `film_low`, `cards_low`.

**`unknown` is a stop.** Zebra never published a MIB for the card printer line, so the fault
vocabulary is learned from observation and will be incomplete. Anything unrecognised pauses the
queue and is written to a journal for later mapping. See `print-agent/docs/snmp/README.md`.

## The agent

One card at a time: claim, print, verify, repeat. Never trusts the printer's own claim of success.

**Camera verification** is optional and can be toggled while running. It answers two questions, and
deliberately not a third.

1. **Did anything land?** One **zone** is drawn over the output bin. The frame is compared against
   how the bin looked immediately before the card was sent. A jam, an empty hopper or a print the
   firmware invented produces no change at all.
2. **Did it come out printed?** **`card_ink` points** are dropped on spots the artwork always
   covers. A point reads blank when it is bright *and* colourless: bare card stock is the only thing
   in the bin that is both, since a pale patch of artwork is coloured and the unlit bin is dark. A
   majority of points decides, so one point drifting off the card as the stack rises cannot condemn
   a good card. This is the check that catches the ribbon or transfer film running out, which is the
   failure that lost badges.

**A blank card stops the batch.** It is a distinct verdict from "unverified": unverified means the
camera could not speak for the card, which is how the system ran before it existed; blank means a
consumable is exhausted and every card after it would be blank too. The job is failed rather than
reported printed, so it reprints once somebody has changed the consumable.

**What the camera deliberately does not do is identify which card it is.** Artwork hashing and OCR
of the badge number were both built, tested on the real rig and removed. Every badge in a batch
shares one template, the smoked bin mirrors the cards back at the lens, and two spare copies of one
badge (`1068-1` and `1068-2`) are pixel-identical artwork by definition, so no image comparison can
separate them even in principle. The question they answered is one the agent does not have: it
prints strictly one job at a time, and `print_file_hash` refuses stale artwork server side before a
job is ever claimed.

**`tray_full` points** are separate and work the opposite way round: they watch for colour change to
detect a full tray, which previously overflowed and jammed the printer. Operators can stick green
tape at the point to make detection easier. Matching is on hue and saturation rather than
brightness, so switching the room lights on or off does not trigger it. Ink points are the one
purpose an operator may place several of; everything else is one point per purpose per printer.

**Tray full finishes the current card, then stops claiming.** Abandoning a card mid-transfer on a
retransfer printer risks a jam.

**On a printer error:**

- Camera on: reprint automatically, but only cards the camera never confirmed leaving the chute.
  Verified cards are left alone.
- Camera off: show the operator a dialog naming the **card number**, asking whether to reprint it,
  so they can physically check the stack before deciding.

Alerts go out over Pushover locally, since the agent is the only thing that can see the hardware.

**Telegram channel** (optional). Posts a photo of every card to a chat, with Pause and Resume
buttons under it. This exists because the blank-card test is a threshold chosen from one frame on
one rig: it catches the failure it was written for and nothing else, whereas a human glancing at a
photo catches a colour cast, a half-transferred card, or simply the wrong artwork. The buttons also
mean stopping a run no longer requires standing at the machine or opening a remote session.

A station prints roughly one card a minute, comfortably inside Telegram's limit of about twenty
messages a minute to one chat, so every card is photographed rather than sampled. Sending happens on
its own thread with a bounded queue that drops the oldest rather than blocking, because nothing
about a chat message is worth pausing the printer for. Button presses arrive by long-polling
`getUpdates`, so no public URL is needed behind convention NAT. Configured in Setup: create the bot
with @BotFather, add it to the channel as an administrator, paste the numeric chat ID.

Everything except the verify call is local: the agent keeps its queue, cached PDFs and pending
confirmations in SQLite so a network drop does not stop printing.

## Card stock and alerts

Blank cards are finite and the printer only says so once it is empty, which strands a run
mid-batch. The agent counts them down instead.

- The count lives in the agent's local store (`card_stock_remaining`), not the config file: it
  changes with every card, and a settings file rewritten a thousand times a day is one that
  eventually gets truncated by a power cut.
- `None` and `0` mean different things. `None` is "nobody is counting" and warns about nothing;
  `0` is "counted, and empty".
- Set and refill are on the Console, next to the session readout. Refill adds a stack
  (`card_stock.refill_size`, default 100) on top of what is left rather than replacing it.
- Warnings start at `card_stock.low_threshold` (default 10) and are keyed per remaining card, so
  the last few each warn rather than one alert at ten followed by silence down to zero.

**Pushover is only for the run stopping, or being about to.** Every alert carries
`stops_printing`; `AlertRelay` sends the urgent ones to Pushover and all of them to Telegram.
A phone that buzzes for one blank card in a run of four hundred gets silenced, and is then
silent for the jam too. Non-urgent today: a blank card, a receipt that did not print, and a
server that refused a print result (the card exists, only the bookkeeping failed).

Both Pushover and the card-stock thresholds are configured on the agent's Setup tab. Pushover
used to be editable only by hand in the config file, which meant in practice it was never on.

## Where the code lives

Print jobs and printer state are modelled in `app/Domain/Printing/` (`Models/`, `Services/`,
`Exceptions/`) with their own enums in `app/Enum/` (`PrintJobStatusEnum`, `PrintJobTypeEnum`,
`PrintBatchStatusEnum`, `PrinterStatusEnum`, `PrinterStatusSeverityEnum`, `PrinterConditionEnum`,
`PrintCompletionSourceEnum`, `PrintVerificationSourceEnum`). Queued jobs are in `app/Jobs/Printing/`:
`GenerateBadgePrintFileJob`, `PrintBadgeJob`, `BatchPrintJob`.

POS-side controllers are under `app/Http/Controllers/POS/Printing/`, with the clerk's own print list
in `app/Http/Controllers/POS/MyPrintsController.php` and its payloads in
`app/Domain/Printing/Services/DeskPrintNotifications.php`.
`php artisan printing:check-stuck-jobs` (scheduled every 3 min) detects stuck jobs;
`printing:reap-leases` returns jobs stranded by a dead agent.

`docs/printing-implementation.md` is the build/debug companion to this file.

## QZ Tray is gone

On-site printing used to be driven through **QZ Tray**, a browser-to-printer bridge, with the POS
exposing certificate and signing endpoints in `routes/pos-auth.php` for it. That is removed:
`QZPrintService.vue`, `QzStatusIndicator.vue`, `QzCertController`, the `qz-tray` package and
`MachineQzStatusTest` are all deleted, and `pos-auth.php` now carries machine login and printer-state
endpoints only. The design notes from that era are still in the repo root as `PRINTING_SYSTEM.md`,
`PRINTING_SYSTEM_IMPROVEMENTS.md` and `PRINTING_SYSTEM_IMPROVEMENTS_LARAVEL.md`; read them as history,
not as a description of the current system. Zebra hardware notes are in `zebra.md`.
