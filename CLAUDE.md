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
- `/admin` — Inertia admin panel for staff (route names `manage.*`, see `docs/admin/rebuild-plan.md`)
- `/admin-legacy` — the Filament panel it is replacing, still fully working until cutover
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
- `app/Filament/` — Admin panel resources, pages, and widgets
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
pricing; `FreeBadgeRepairService` (admin → Maintenance → DB Service) repairs already-wrongly-charged
badges. See `docs/bugfix-01-result.md`, `docs/bugfix-03-fix.md`, and `docs/handoff.md`.

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
