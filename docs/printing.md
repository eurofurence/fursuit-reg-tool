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

## Batches

A batch is a set of cards printed as one run. **Batches are immutable**: contents are fixed by
`PrintBatch::build()` and nothing can be added afterwards.

Committing a badge to a batch sets `badges.printing_locked_at`. From that moment the attendee cannot
edit or delete it, because the artwork is already rendered and an edit would put a card in the stack
that no longer matches the order.

A printer works one batch to completion before starting another. Any fault pauses the batch;
nothing drains past it.

Cancelling a batch (possible from the agent) cancels every job not yet printed. Badges that never
produced a card are unlocked and handed back to their owner; badges that did print stay locked.

Ordering is descending by attendee, then descending by badge number, so cards land on top of each
other and the finished stack reads ascending from the top.

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
