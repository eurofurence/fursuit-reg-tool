# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Environment Setup

The project supports two development environments:

**Nix / devenv (primary)** — `.envrc` uses `direnv` + `use flake`. With direnv allowed, the
toolchain (PHP, Node) is provided automatically and a `sail` alias is exported.

**Yerd (local `.test` domain)** — the app is also served at `https://fursuit-reg-tool.test`
via the `yerd` CLI, with `catch.fursuit-reg-tool.test` for the Catch-Em-All routes. The site
runs **PHP 8.5** against a local MariaDB (`yerd service start mariadb`, database `fursuit`,
user `root`, no password). Yerd's own default PHP applies to new sites, so a fresh link needs
`yerd use <site> 8.5`.

**Laravel Sail (Docker)** — the classic path:

```bash
# Initial setup
cp .env.example .env       # or: mv .env.example .env
composer install
npm install
npm run build

# Start development environment
./vendor/bin/sail up

# Database operations
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed
```

> Note: `.env.example` defaults `DB_CONNECTION=sqlite` (a `database/database.sqlite` file).
> The Sail `docker-compose.yml` provisions MySQL, Redis, and Mailpit; set `DB_CONNECTION=mysql`
> (and the `DB_*` host vars) if you run against the Sail database.

### Development Commands

```bash
# Frontend development
npm run dev           # Start Vite dev server with hot reload
npm run build         # Build production assets

# Laravel Artisan commands
./vendor/bin/sail artisan tinker              # Interactive REPL
./vendor/bin/sail artisan migrate:status      # Check migration status
./vendor/bin/sail artisan queue:work          # Process background jobs (queue=database)
./vendor/bin/sail artisan horizon             # Laravel Horizon for queues
./vendor/bin/sail artisan octane:start        # Laravel Octane (Swoole) app server
./vendor/bin/sail artisan reverb:start        # Laravel Reverb (WebSockets)

# Testing
./vendor/bin/sail test                         # Run all tests with Pest
./vendor/bin/sail test --filter=BadgeTest     # Run specific test class
./vendor/bin/pest tests/Feature/BadgeTest.php # Run specific test file

# Code quality
./vendor/bin/sail pint                         # Laravel Pint (PHP CS Fixer)
```

### Event State Management

```bash
# Useful for development/testing - sets up an event in a given state.
# Accepted states: pre-order | order | event-order | closed  (open = legacy alias)
php artisan event:state order      # Order window open
php artisan event:state closed     # Orders closed
```

### Other Useful Artisan Commands

```bash
php artisan badges:print                  # Print all unprinted badges
php artisan badges:unprint                # Revert badges to unprinted
php artisan fursuit:create-catch-code     # (Re)generate Catch-Em-All codes (--purge-all, --regen-unprinted)
php artisan fcea:refresh-rankings         # Rebuild Catch-Em-All leaderboard (scheduled every 15 min)
php artisan printing:check-stuck-jobs     # Detect stuck print jobs (scheduled every 3 min)
php artisan prepaid:update                # Reconcile prepaid badges against fursuit packages
php artisan refresh:tokens                # Refresh OAuth tokens (scheduled daily)
php artisan registration:submit-data      # Push attendee data to the registration service
php artisan import:old-fursuit-data       # Legacy data import (--dry-run, --event=, --limit=)
php artisan dsfin:generate-direct-export  # DSFinV-K fiscal export (German tax compliance)
php artisan tse:update-state              # Fiskaly TSE state management (also tse:change-admin-pin)
```

## Architecture Overview

### Core System

This is a **Laravel 11 + Inertia.js + Vue 3** application for managing fursuit badge
registration at the Eurofurence convention. It covers the full lifecycle from badge creation
through on-site pickup, with integrated payment processing (wallet + SumUp), German fiscal
compliance (TSE / DSFinV-K via Fiskaly), and a "Catch-Em-All" social game.

### Key Domain Models

**Event System**: Events have order windows (`order_starts_at` to `order_ends_at`) that
determine when badges can be ordered. Event state is computed dynamically (see
`App\Enum\EventStateEnum`) based on the current time vs. the order window — there is no
`state` column.

**Badge Lifecycle**: Badges use Spatie Model States with two parallel state machines
(in `app/Models/Badge/`):

- Payment states (`State_Payment/`): `Unpaid` → `Paid`
- Fulfillment states (`State_Fulfillment/`): `Pending` → `Processing` → `ReadyForPickup` → `PickedUp`
  (a `Printed` state also exists; transitions live in the `Transitions/` subfolders, and POS
  error-correction reverts such as `PickedUp` → `ReadyForPickup` are supported)

**Fursuit Management**: Fursuits require approval before badges can be created
(`app/Models/Fursuit/States/`): `Pending` → `Approved` / `Rejected`, with `Rejected` → `Pending`
and `Rejected` → `Approved` recovery transitions.

**Wallet Integration**: Uses `bavix/laravel-wallet` for payment processing. Badges implement
`ProductInterface` for seamless wallet transactions. The checkout domain (`app/Domain/Checkout/`)
wraps this with its own models, services, and states.

### Application Structure

**Multi-Interface Design**:

- `/` — Public fursuit badge registration interface (Vue/Inertia)
- `/admin` — the Inertia admin panel for staff, and the only one (route names `manage.*`, see
  `docs/admin/rebuild-plan.md`). The Filament panel it replaced is gone; `/admin-legacy` is a
  redirect kept for one release so old bookmarks land on `/admin`
- `/pos` — Point-of-sale system for on-site operations (machine + staff PIN auth)
- `/catch-em-all` — "Catch-Em-All" game interface (mobile-first, PWA)
- `/gallery` — Public fursuit gallery
- `/api` — REST API (e.g. `GET /api/fursuits`) guarded by API auth middleware

**Route files** (`routes/`): `web.php`, `pos.php`, `pos-auth.php` (machine login + QZ Tray
cert/signing), `catch-em-all.php`, `gallery.php`, `api.php`, `channels.php`, `console.php`.

**Key Directories**:

- `app/Models/Badge/` — Badge model with the two state machines
- `app/Models/Fursuit/` — Fursuit management with approval workflow
- `app/Models/FCEA/` — Catch-Em-All catch/log/ranking models
- `app/Badges/` — Badge rendering system (PDF generation); bases in `app/Badges/Bases/`
- `app/Domain/` — Domain-specific logic: `CatchEmAll/`, `Checkout/`, `Printing/`
- `app/Support/Manage/` — the admin table/column/filter/action layer the `/admin` panel is built on
- `app/Http/Controllers/Manage/` — one controller per admin module; routes in `routes/manage/`
- `app/Http/Controllers/` — Grouped by interface: `Admin/`, `POS/` (incl. `Printing/`),
  `FCEA/`, `GALLERY/`, `API/`, plus public controllers
- `app/Jobs/` — Queued jobs (receipt generation, `Printing/` jobs, ranking updates)
- `app/Console/Commands/` — Artisan commands (see above)
- `app/Notifications/` — Badge/fursuit lifecycle notifications and receipts
- `app/Services/`, `app/Enum/`, `app/Providers/` — Supporting services, enums, providers
- `resources/js/Pages/` — Vue components grouped by interface: `Badges/`, `POS/`,
  `CatchEmAll/`, `FCEA/`, `Gallery/`, `Statistics/`, `Auth/`

### State Management Pattern

The system heavily uses **Spatie Model States** for complex entity lifecycles (Badges,
Fursuits, Checkouts). When working with these entities, always consider the current state and
the available transitions rather than mutating state properties directly.

### Event-Driven Architecture

- Badge creation triggers notifications
- State transitions are logged via `spatie/laravel-activitylog`
- Background jobs handle printing, ranking updates, and receipt generation
- Laravel Horizon manages queue processing; the default queue/cache/session driver is `database`
- A scheduler (`routes/console.php`) runs token refresh (daily), FCEA ranking refresh
  (every 15 min), and stuck-print-job checks (every 3 min)

### Badge Generation System

- Badges are rendered as PDFs using custom badge classes in `app/Badges/`
- Each badge type (e.g. `EF28_Badge`, `EF29_Badge`) extends `BadgeBase_V1` (in `Bases/`) and
  defines positioning/fonts; reusable field/layout helpers live in `app/Badges/Components/`
- PDF generation uses `mpdf/mpdf`; images are processed with Intervention/Imagine and stored on S3
- QR codes are generated for the Catch-Em-All game integration

### Public site navigation

- The public chrome is thumb-first: a 56px header that carries brand and account only, a
  pill rail under it on `md+`, and a **fixed bottom tab bar below `md`**. All of it lives in
  `resources/js/Components/SiteNav/`, wired up by `Layouts/Layout.vue`.
- **Destinations are declared once**, in `SiteNav/navItems.js`, and rendered three times
  (rail, tab bar, footer) through `useSiteNav()`. Add a link there, not in a component - the
  old header and footer each had their own list and disagreed about what the site contained.
- The tab bar has four slots plus "More", filled by `primary.slice(0, TAB_SLOTS)`. A hidden
  entry promotes the next one instead of leaving a gap, so never hard-code which four.
- **Active state must be derived from `page.url`, never from `window.location`.** Ziggy's
  `route().current()` is still the matcher, but it reads `window.location`, which Vue cannot
  track - and Inertia reuses the same `Layout` instance across visits, so a template calling
  a `current()` helper never re-renders and the highlight sticks on whatever page was loaded
  from the server (it only looks right on a full reload). `useSiteNav()` therefore builds a
  Ziggy router with `location` set from Inertia's reactive `page.url`. Test nav changes by
  **clicking**, not by loading each URL. Ziggy's `current()` also takes one pattern and reads
  its second argument as route params, so `match` patterns are tested one at a time.
- `/faq`, `/pickup` and `/catch-em-all` are `InfoController` (`info.*`). `/catch-em-all` is an
  info page with a button into the game subdomain; `/fcea` stays a bare redirect for QR codes
  and print. The Catch-Em-All nav entry is gated on the `catchEmAllActive` shared prop, which
  `HandleInertiaRequests::share()` computes from the event it already loaded
  (`Event::isCatchEmAllActive()`) - do not add a second query for it.
- Pages under the layout need no bottom padding of their own; the layout spacer clears the
  tab bar.

### Gallery

- `/gallery` is a folder overview (one card per event, plus an "all fursuits" card);
  the grid lives at `/gallery/all` and `/gallery/event/{event}` (`gallery.all`,
  `gallery.event`). Old `?event=` links redirect into the matching folder. Folder counts
  and covers are cached under `GalleryController::FOLDER_CACHE_KEY`, which `FursuitObserver`
  drops whenever a fursuit changes what a card shows.
- **The gallery webp is derived data, generated on write, never on read.** `FursuitObserver`
  clears `image_webp` the moment `image` changes and queues `GenerateFursuitWebpJob`, which
  encodes to the deterministic path `gallery/fursuits/{original-filename}.webp` and deletes
  the orphan. `Fursuit::imageWebpUrl()` only reads, falling back to the original when no
  variant exists yet.
  Do not restore on-read generation: it put an S3 download plus a GD encode plus a model
  write inside gallery requests, and because it keyed off "column empty" rather than
  "photo changed", a fursuit whose photo was replaced after approval kept serving the
  previous picture forever.
- Backfill / repair: `php artisan fursuits:generate-webp --stale` (add `--sync` to encode in
  process, `--all` to re-render everything).
- Signed storage URLs are cached for 30 minutes by `Fursuit::signedStorageUrl()`; a gallery
  page otherwise signs 20 objects per load.

### Desk corrections and the manager gate

Two POS edits, two different bars, both in `POS\BadgeEditController`:

- **Details** (fursuit name, species, `dual_side_print`, `published`, `catch_em_all`) — any
  cashier, no approval. Reached from the attendee page by selecting **exactly one** badge,
  which then enables "Edit badge" in the commit bar; deliberately not a button on every row.
  Unlike the attendee-facing `BadgeController@update`, this does **not** send the fursuit back
  to Pending review, and it does not clear the print file: `GenerateBadgePrintFileJob` keys off
  a content hash, so a renamed badge re-renders on its next print by itself.
- **Price** — needs a manager. `staff.is_manager` is the flag; `ManagerApprovalService::approve()`
  passes a manager who is already signed in at the till, and otherwise takes a manager PIN or
  a scanned RFID tag in the same field (PIN first, then tag — the two namespaces do not
  overlap). Failed attempts are rate limited per machine.

**A price change cannot edit a live transaction.** The Fiskaly receipt is signed against a
total, so `POST /pos/badges/prices` reprices the badges, then `CheckoutService::rebuild()`
cancels the open checkout (end signature) and opens a fresh one holding the same badges,
redirecting to it. Only the ACTIVE checkout **on the same machine** is rebuilt. Already-paid
badges are refused outright — that is a refund, not a correction. Every override is written to
the activity log with from/to, the approving manager and an optional reason.

`CheckoutService` is where a checkout is built; `CheckoutController@store` is a thin caller.

### Fursuit review (the approval queue)

A verdict answers **two independent questions**, so do not collapse them:

- `status` (Spatie state) decides whether the **card** may be printed and handed out. Only a
  Code of Conduct rejection blocks it, and `BadgePrintQueue` is where that is enforced
  (`withoutUnapprovedFursuits`) — before that, printing looked only at the badge, so a rejected
  submission was printed anyway and the rejection only ever meant an email. A badge that never
  reaches Processing also never reaches PickedUp, so that one filter closes printer and desk.
- `publication_blocked_at` / `publication_block_reason` decide whether the fursuit may be
  **shown** (gallery *and* Catch-Em-All — hence "publication", not "gallery"). Blocking also
  clears the attendee's `published` / `catch_em_all` switches, because `catch_em_all` is read by
  the badge artwork and the catch-code lookup and a printed QR that resolves to nothing is worse
  than no QR; `Fursuit::scopePublicationAllowed()` keeps the surfaces closed if the attendee
  turns a switch back on. Lifting a block restores the switches from the block's own snapshot.

`App\Enum\FursuitReviewOutcomeEnum` = `Approved | Rejected | PublicationBlocked`, and
`App\Services\FursuitReviewService` is the only place a verdict is applied (queue page, record
page and edit form all go through it).

- **A block on somebody who asked for neither surface is recorded as a plain approval**
  (`silentlyApproves()`), needs no reason, and the reviewer is told by toast. Refusing a request
  nobody made would only confuse the attendee.
- **The undo window is a column, never a queue delay.** Each verdict writes a
  `fursuit_review_decisions` row with `notify_at`; `fursuits:deliver-review-decisions` (scheduled
  every minute) dispatches `DeliverFursuitReviewDecisionJob`, which re-checks that the verdict is
  still current before mailing. A `->delay()` would be ignored by the `sync` driver — which the
  test suite uses — and the mail would go out inside the reviewer's own request. The transitions
  therefore take a `notify` flag the review path sets to false.
- **Undo is a restore, not a transition.** The machine has no approved -> pending edge; the
  decision row carries a `restore` snapshot. Undo is refused once `notified_at` is set.
- **There is no claim lock.** `App\Services\FursuitPresence` (cache, 45s TTL, refreshed by the
  review page's own poll) makes `next` skip records somebody is on and names other viewers, but
  never refuses a verdict. The old Filament lock did refuse them, so a shared link was useless and
  a dead browser froze a record for five minutes. `Fursuit::claim()` is now unused by any screen.
- **Submission history**: `FursuitObserver` writes a `fursuit_submission_revisions` row whenever
  `name`, `species_id` or `image` changes, and the attendee editor no longer deletes the photo it
  replaces — the review page shows the previous image so "they resubmitted the same file" is
  visible. Guard: skip when the originals are all null (the `created` hook's second save fires
  `updating` before Eloquent syncs originals).

Surfaces: `/admin/fursuits/review` is the keyboard-first queue (A/R/G, 1-9 for reasons, Enter to
confirm, right arrow to skip; undo is a button on purpose). `/admin/fursuits/{id}` stays the
record page. Attendee-facing wording lives in `Info/Faq.vue` and
`FursuitPublicationBlockedNotification`.

### Printing System

- On-site printing is driven through **QZ Tray** (browser-to-printer bridge); the POS exposes
  certificate and signing endpoints (`pos-auth.php`) for QZ
- Print jobs and printer state are modeled in `app/Domain/Printing/` with their own enums
  (`PrintJobStatusEnum`, `PrinterStatusEnum`, etc.) and queued jobs (`PrintBadgeJob`, `BatchPrintJob`)
- See `PRINTING_SYSTEM.md` and the `PRINTING_SYSTEM_IMPROVEMENTS*.md` docs for design notes

### Fiscal Compliance (German market)

- SumUp card readers handle on-site card payments (`SumUpReader` model)
- A Fiskaly **TSE** (Technical Security System) signs transactions; managed via the `tse:*`
  commands. See `TSE.md`
- DSFinV-K exports are produced by `dsfin:generate-direct-export`. See `DSFinV_K_2_4.pdf`

### Database Design Notes

- Default connection is SQLite (`.env.example`); Sail provisions MySQL
- Events use computed state (no `state` column) based on date comparisons
- Badges have `custom_id` for human-readable identification
- Soft deletes are used throughout for audit trails
- Activity logging tracks all important changes

### Testing Approach

- Uses **Pest PHP** testing framework (`tests/Feature`, `tests/Unit`)
- Feature tests cover critical user journeys (badge flow, checkout, printing, notifications,
  event order state)
- Database factory patterns for test data creation
- Event state can be manipulated via the `event:state` command for testing

### Observability

- **Sentry** (`sentry/sentry-laravel`) for error tracking
- **Clockwork** (`itsgoingd/clockwork`) for local request profiling/debugging

### Development Environment

- **Nix / devenv** (`.envrc` + flake) or **Laravel Sail** for Docker-based development
- **Laravel Octane** (Swoole) is available as the production app server
- **Laravel Reverb** provides WebSockets; the frontend uses `laravel-echo`
- **Vite** for frontend asset compilation with HMR
- **Inertia.js** bridges the Laravel backend with the Vue frontend (`tightenco/ziggy` for routes)
- **PrimeVue** component library + **Tailwind CSS** for UI; Lucide icons

### Domain Gotchas

**Prepaid badges: "can create" vs. "free badges left" are two different things.** Two related
prepaid calculations — do **not** merge their values:

- `App\Policies\BadgePolicy::create()` uses `prepaid_badges − ordered` to decide whether a user
  may create a badge **at all** (the badge may end up **paid**). A prepaid allowance bypasses the
  closed order-window restriction.
- `App\Models\User::getPrepaidBadgesLeft()` = `prepaid_badges − orderedMainBadges` (only main
  badges count; spare copies — `extra_copy_of != null` — are always separately paid and never
  consume the allowance). It answers **how many free badges remain** and drives badge **pricing**
  in `BadgeController@store`.

The **full** `prepaid_badges` entitlement is honored as free. (Until bugfix-03 this method also
deducted an extra `1` after `order_starts_at` — "the included badge is no longer honored" — which
wrongly **charged** the user's last prepaid badge; that `−1` is gone. See
`docs/bugfix-03-fix.md`.)

A user with `getPrepaidBadgesLeft() == 0` can still order an additional **paid** badge while the
order window is open. The public Welcome page (`Welcome.vue`) therefore gates its create/customize
button on the authoritative `canCreate` (`Gate::allows('create', Badge::class)`) passed by
`WelcomeController`, not on `prepaidBadgesLeft`. `PrepaidBadgePriceConsistencyTest` locks in the
pricing; `DbServiceController` (admin → Maintenance → DB Service) repairs already-wrongly-charged
badges. It replaces `App\Services\FreeBadgeRepairService`, which was deleted in `5aa2148` together
with the Filament page it served; the repair now lives in the controller that owns the screen, and
zeroing the badge total *is* the correction since the wallet credit went with the wallet package
(`fa0554e`). See `docs/bugfix-01-result.md`, `docs/bugfix-03-fix.md`, and `docs/handoff.md`.

### Migrations must be idempotent

Migrations run as an ArgoCD PreSync **Job** (`php artisan migrate --force`) against MySQL, and
**MySQL DDL is not transactional** — a migration that fails partway leaves its applied steps in
place but is never recorded, so the next run hits "Duplicate column / key / table" and blocks every
later migration (this caused a dev outage; see `docs/bugfix-02-*.md`).

Every migration must therefore be safe to re-run. Guard each operation with
`App\Support\Migrations\SchemaGuard`:

- `Schema::create('t', …)` → wrap in `if (SchemaGuard::missingTable('t')) { … }` (and use
  `Schema::dropIfExists` in `down()`).
- add/drop column → `SchemaGuard::missingColumn(...)` / `SchemaGuard::hasColumn(...)`.
- add/drop index or unique → `SchemaGuard::hasIndex($table, $nameOrColumns)`.
- add/drop foreign key → `SchemaGuard::hasForeignKeyOn(...)` / `hasForeignKeyTo(...)`.
- `->change()` may be left unguarded (re-applying is safe). Data `UPDATE`s should use `WHERE`
  guards so they converge. Order destructive steps so a new FK is only added after conflicting
  data is cleared.

`tests/Feature/MigrationIdempotencyTest.php` locks in the helper's behaviour.

### Additional Documentation

The repo root contains focused design docs worth consulting: `CATCH.md` (Catch-Em-All game),
`PRINTING_SYSTEM*.md` (printing), `TSE.md` + `zebra.md` (fiscal/printer hardware),
`openapi.yml` (API spec), and `README.md` (setup). Bugfix write-ups live in `docs/`.
