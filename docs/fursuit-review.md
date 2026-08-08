# Fursuit review (the approval queue)

How a reviewer's verdict is applied, and the two independent questions it answers. Read this before
touching `FursuitReviewService`, the review queue at `/admin/fursuits/review`, the reason lists, or
anything that decides whether a fursuit is printed or shown.

## A verdict answers two independent questions

A verdict answers **two independent questions**, so do not collapse them:

- `status` (Spatie state) decides whether the **card** may be printed and handed out. Only a
  rejection blocks it, and `BadgePrintQueue` is where that is enforced
  (`withoutUnapprovedFursuits`) - before that, printing looked only at the badge, so a rejected
  submission was printed anyway and the rejection only ever meant an email. A badge that never
  reaches Processing also never reaches PickedUp, so that one filter closes printer and desk.
- `publication_blocked_at` / `publication_block_reason` decide whether the fursuit may be **shown**
  (gallery *and* Catch-Em-All - hence "publication", not "gallery"). Blocking also clears the
  attendee's `published` / `catch_em_all` switches, because `catch_em_all` is read by the badge
  artwork and the catch-code lookup and a printed QR that resolves to nothing is worse than no QR;
  `Fursuit::scopePublicationAllowed()` keeps the surfaces closed if the attendee turns a switch back
  on. Lifting a block restores the switches from the block's own snapshot.

`App\Enum\FursuitReviewOutcomeEnum` = `Approved | Rejected | PublicationBlocked`, and
`App\Services\FursuitReviewService` is the only place a verdict is applied (queue page, record page
and edit form all go through it).

## Rules that have been re-broken before

- **A block on somebody who asked for neither surface is recorded as a plain approval**
  (`silentlyApproves()`), needs no reason, and the reviewer is told by toast. Refusing a request
  nobody made would only confuse the attendee.
- **The undo window is a column, never a queue delay.** Each verdict writes a
  `fursuit_review_decisions` row with `notify_at`; `fursuits:deliver-review-decisions` (scheduled
  every minute) dispatches `DeliverFursuitReviewDecisionJob`, which re-checks that the verdict is
  still current before mailing. A `->delay()` would be ignored by the `sync` driver - which the test
  suite uses - and the mail would go out inside the reviewer's own request. The transitions therefore
  take a `notify` flag the review path sets to false.
- **Undo is a restore, not a transition.** The machine has no approved -> pending edge; the decision
  row carries a `restore` snapshot. Undo is refused once `notified_at` is set.
- **There is no claim lock.** `App\Services\FursuitPresence` (cache, 45s TTL, refreshed by the review
  page's own poll) makes `next` skip records somebody is on and names other viewers, but never
  refuses a verdict. The old Filament lock did refuse them, so a shared link was useless and a dead
  browser froze a record for five minutes. `Fursuit::claim()` is now unused by any screen.
- **Submission history**: `FursuitObserver` writes a `fursuit_submission_revisions` row whenever
  `name`, `species_id` or `image` changes, and the attendee editor no longer deletes the photo it
  replaces - the review page shows the previous image so "they resubmitted the same file" is visible.
  Guard: skip when the originals are all null (the `created` hook's second save fires `updating`
  before Eloquent syncs originals).
- **Reasons are rows, not code.** `review_reasons`, edited in Settings > Review Reasons, one list per
  outcome. Two texts per reason: `keyword` is the chip in the queue (a reviewer scans eleven options)
  and `body` is the paragraph the attendee gets. `FursuitReviewService::DEFAULT_REASONS` is only the
  seed - the migration inserts it while the table is empty and `ReviewReasonSeeder` does the same for
  `migrate:fresh --seed`; editing the constant changes nothing on a seeded installation. Retire by
  deactivating, not deleting, so the slug stays readable in logs.
- **A rejection is only for what we cannot hand out at all.** It costs the attendee their badge
  until they act, so the shipped list is three reasons: `drugs` (legal substances included),
  `hate_speech` (harassment, hate speech and its symbols) and `nudity` (nude or visibly
  "anatomically correct" content). **We do not judge submissions - anything else gets printed.**
  Everything merely wrong for the gallery is a publication block: `artwork`, `ai_generated`,
  `real_animal`, `no_costume` (a person, or close enough to be identifiable as one),
  `identifiable_human` (we cannot verify consent to publish a face) and `fetish`.
- **The fetish / nudity split is the one to understand.** The [RoC](https://help.eurofurence.org/legal/roc/)
  restricts overly revealing and obviously fetish-related items in *public* areas, so those badges
  are still issued and only the gallery stays PG-13 - a publication block. Visibly anatomically
  correct or indecently revealing content is refused outright.
- Five things were rejection reasons at some point and must not come back: *image quality* ("too
  dark", "too blurry" - not our call, the attendee chose the photo), *"not a fursuit"* and *real fur*
  and *someone else in the shot* (publication matters, not print ones), and *prop weapons* - the RoC
  bans **carrying** weapons and has look-alikes and replicas checked in at the Security Office, which
  is a rule about an object somebody brings on site; a suit photographed with a prop sword carries
  nothing. Body paint is the same shape: it needs Security's permission on site, and a photo of it
  breaks nothing.
- **Never justify a rule to the attendee with "a badge is worn in those areas".** The fursuit badge
  is a separate keepsake, not the attendee badge - wearing it is optional and the FAQ says so - so
  that argument is both wrong and confusing. State the standard, not a premise about where it is worn.
- **The undo bar lives on the record it applies to**, never on the next one - context beats reach.
  The way there is the **left arrow**, which *navigates* to the last record this reviewer decided
  (the `back` prop: a URL, present only while that verdict can still be erased and only when it is a
  different record than the one on screen). The arrow deliberately does **not** undo: it used to,
  which erased a verdict on a record the reviewer could not see. Undo stays a button on that record.
  The browser's own Back also works - `Review.vue` reloads on `popstate`, or Inertia would restore
  the pre-verdict page from its history cache and the undo bar would appear to be missing.
- **Nothing requested, no block button.** When the attendee ticked neither surface the block is not
  offered at all and its `g` folds into Approve (`outcomes[].shortcuts` is a list for this reason).
- **The panel reads the gallery variants**, never the print master:
  `FursuitController::previewUrl()` (1080x1920 webp) on the review and record pages, `thumbUrl()`
  (500px) in list rows, both falling back to the master while a render is queued.
  `GenerateFursuitWebpJob` runs on submit and on every photo replacement, so the variants exist
  before a reviewer arrives.
- **A record whose render has not landed is not handed out.** `Fursuit::imageRenderPending()` is
  true while a photo has no `image_webp`; `scopeImageRenderSettled()` keeps those rows out of both
  `nextPending()` and `pendingCount()`, so nobody is asked for a verdict on a picture that is not
  there yet, and the count never promises records the queue will not hand over. Reaching such a
  record by link still works: the review and record pages carry `fursuit.imageProcessing` and show
  a "photo still processing" placeholder instead of pulling the print master into the frame.
  The hold expires after `Fursuit::IMAGE_RENDER_GRACE_MINUTES` (15), because a render can fail for
  good - a file GD will not decode is logged once and never retried, and an imported row never had
  a job at all - and a submission must not be swallowed by one. Past the window the record returns
  to the queue with its master photo, as before.

## Surfaces

`/admin/fursuits/review` is the keyboard-first queue - photo left, verdicts right, reason chips under
their own verdict (A/R/G choose, 1-9 pick a reason, Enter confirms, left arrow goes back to the last
record decided, right arrow skips; undo is a button on that record, on purpose).
`/admin/fursuits/{id}` stays the record page. Attendee-facing wording lives in
`Info/Faq.vue`, Settings > Review Reasons and `FursuitPublicationBlockedNotification`.
