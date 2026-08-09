# CLAUDE.md

Guidance for Claude Code (claude.ai/code) working in this repository. This file carries what every
agent needs; per-subsystem detail lives in `docs/` (see **Subsystem docs** at the end) - read the one
for the area you are touching.

## Development Commands

### Environment Setup

The project supports two development environments:

**Nix / direnv (primary)** - `.envrc` uses `direnv` + `use flake` against remote flakes
(`github:loophp/nix-shell#env-php83` and the `dev-templates` node flake); there is no `flake.nix`
or `devenv.nix` in the repo. With direnv allowed, the toolchain (PHP, Node) is provided
automatically and a `sail` alias is exported.

**Yerd (local `.test` domain)** - the app is also served at `https://fursuit-reg-tool.test`
via the `yerd` CLI, with `catch.fursuit-reg-tool.test` for the Catch-Em-All routes. The site
runs **PHP 8.5** against a local MariaDB (`yerd service start mariadb`, database `fursuit`,
user `root`, no password). Yerd's own default PHP applies to new sites, so a fresh link needs
`yerd use <site> 8.5`. `composer.json` only requires `php ^8.2`, so anything from 8.2 up runs.

**Laravel Sail (Docker)** - the classic path:

```bash
cp .env.example .env               # or: mv .env.example .env
composer install && npm install && npm run build
./vendor/bin/sail up               # start the environment
./vendor/bin/sail artisan migrate  # or: migrate:fresh --seed
```

> Note: `.env.example` defaults `DB_CONNECTION=sqlite` (a `database/database.sqlite` file).
> The Sail `docker-compose.yml` provisions MySQL, Redis, and Mailpit; set `DB_CONNECTION=mysql`
> (and the `DB_*` host vars) if you run against the Sail database.

### Development Commands

```bash
npm run dev                                    # Vite dev server with hot reload
npm run build                                  # Build production assets
./vendor/bin/sail artisan tinker               # Interactive REPL
./vendor/bin/sail artisan migrate:status       # Check migration status
./vendor/bin/sail artisan queue:work           # Process background jobs (queue=database)
./vendor/bin/sail artisan horizon              # Laravel Horizon for queues
./vendor/bin/sail artisan octane:start         # Laravel Octane app server (RoadRunner by default)
./vendor/bin/sail artisan reverb:start         # Laravel Reverb (WebSockets)
./vendor/bin/sail test                         # Run all tests with Pest
./vendor/bin/sail test --filter=BadgeTest      # Run specific test class
./vendor/bin/pest tests/Feature/BadgeTest.php  # Run specific test file
./vendor/bin/sail pint                         # Laravel Pint (PHP CS Fixer)
```

### Other Useful Artisan Commands

```bash
# Event state, for development/testing - sets up an event in a given state.
# Accepted states: pre-order | order | event-order | closed (open = legacy alias)
php artisan event:state order             # Order window open
php artisan event:state closed            # Orders closed

php artisan badges:print                      # Print all unprinted badges
php artisan badges:unprint                    # Revert badges to unprinted
php artisan badges:generate-print-files       # (Re)render the print-ready badge files
php artisan badges:remind-pickup              # Notify attendees with badges waiting at the desk
php artisan printing:check-stuck-jobs         # Detect stuck print jobs (scheduled every 3 min)
php artisan printing:reap-leases              # Requeue jobs on expired agent leases (scheduled every minute)
php artisan print-agent:token                 # Issue/rotate a print agent bearer token
php artisan fursuit:create-catch-code         # (Re)generate Catch-Em-All codes (--purge-all, --regen-unprinted)
php artisan fursuits:generate-webp            # Build the derived gallery webp variants
php artisan fursuits:deliver-review-decisions # Send review verdicts past their undo window (scheduled every minute)
php artisan fcea:refresh-rankings             # Rebuild Catch-Em-All leaderboard (scheduled every 15 min)
php artisan prepaid:update                    # Reconcile prepaid badges against fursuit packages
php artisan refresh:tokens                    # Refresh OAuth tokens (scheduled daily)
php artisan registration:submit-data          # Push attendee data to the registration service
php artisan import:old-fursuit-data           # Legacy data import (--dry-run, --event=, --limit=)
php artisan dsfin:generate-direct-export      # DSFinV-K fiscal export (German tax compliance)
php artisan tse:update-state                  # Fiskaly TSE state management (also tse:change-admin-pin)
```

## Architecture Overview

### Core System

This is a **Laravel 12 + Inertia.js 2 + Vue 3** application for managing fursuit badge
registration at the Eurofurence convention. It covers the full lifecycle from badge creation
through on-site pickup, with integrated payment processing (POS checkout + SumUp), German fiscal
compliance (TSE / DSFinV-K via Fiskaly), and a "Catch-Em-All" social game.

### Key Domain Models

**Event System**: Events have order windows (`order_starts_at` to `order_ends_at`) that
determine when badges can be ordered. Event state is a computed accessor (`Event::state()`
returning `App\Enum\EventStateEnum::OPEN|CLOSED`) based on the current time vs. the order window -
there is no `state` column. The finer states the `event:state` command sets up (`pre-order`,
`order`, `event-order`, `closed`) are just date arrangements, not enum cases.

**Badge Lifecycle**: Badges use Spatie Model States with two parallel state machines
(in `app/Models/Badge/`):

- Payment states (`State_Payment/`): `Unpaid` → `Paid`
- Fulfillment states (`State_Fulfillment/`): `Pending` → `Processing` → `ReadyForPickup` → `PickedUp`
  (a `Printed` state also exists; transitions live in the `Transitions/` subfolders, and POS
  error-correction reverts such as `PickedUp` → `ReadyForPickup` are supported)

**Fursuit Management**: Fursuits require approval before badges can be created
(`app/Models/Fursuit/States/`): `Pending` → `Approved` / `Rejected`, with `Rejected` → `Pending`
and `Rejected` → `Approved` recovery transitions. A verdict also decides publication separately from
printability - see [`docs/fursuit-review.md`](docs/fursuit-review.md).

**Payment**: `bavix/laravel-wallet` is **gone** (removed in `fa0554e`) - no wallet package, no
`ProductInterface`, no balance table. Money owed is derived from badge state: `User::amountDue()`
sums `badges.total` where `status_payment` is `Unpaid`, and `badges.status_payment` is the single
source of truth. The checkout domain (`app/Domain/Checkout/`) owns the POS checkout with its own
models, services and states (`Active` → `Finished` / `Cancelled`); `ToFinished` transitions the
badges to `Paid`. Background: [`docs/wallet-removal-plan.md`](docs/wallet-removal-plan.md) (its
"Status: proposed" header is stale - the removal shipped).

### Application Structure

**Multi-Interface Design**:

- `/` - Public fursuit badge registration interface (Vue/Inertia)
- `/admin` - the Inertia admin panel for staff, and the only one. Route names are `admin.*` (the
  prefix is applied in `bootstrap/app.php`, so `routes/manage.php` and the files under
  `routes/manage/` declare only their own segment); files and controllers still live under
  `Manage/`. `/admin-legacy/{path?}` is a 301 redirect (`routes/web.php`) so old bookmarks land
  on `/admin`
- `/pos` - Point-of-sale system for on-site operations (machine + staff PIN auth)
- `/catch-em-all` - "Catch-Em-All" game interface (mobile-first, PWA)
- `/gallery` - Public fursuit gallery
- `/api` - REST API (e.g. `GET /api/fursuits`) guarded by API auth middleware

**Route files** (`routes/`): `web.php`, `pos.php`, `pos-auth.php` (machine login, printer states),
`catch-em-all.php`, `gallery.php`, `api.php`, `print-agent.php` (bearer-token agent API, `api`
middleware group), `channels.php`, `console.php`, plus `manage.php` (admin panel shell: dashboard,
event scope, shared table endpoint) and the per-module files under `routes/manage/` it loads.

**Key Directories**:

- `app/Models/` - `Badge/` (the two state machines), `Fursuit/` (approval workflow, review
  decisions, submission revisions), `FCEA/` (Catch-Em-All catch/log/ranking models)
- `app/Badges/` - Badge rendering system (PDF generation); bases in `app/Badges/Bases/`
- `app/Domain/` - Domain-specific logic: `CatchEmAll/`, `Checkout/`, `Printing/`
- `app/Support/` - `Manage/` (the admin table/column/filter/action/status layer the `/admin` panel
  is built on), `Migrations/SchemaGuard`, `DeskOpeningHours`, `PickupBooths`
- `app/Http/Controllers/` - Grouped by interface: `Manage/` (one controller per admin module),
  `Admin/`, `POS/` (incl. `Printing/`), `PrintAgent/`, `FCEA/`, `GALLERY/`, `API/`, plus public
  controllers
- `app/Jobs/` - Queued jobs (receipt generation, `Printing/` jobs, ranking updates, webp
  generation, review-decision delivery)
- `app/Console/Commands/` - Artisan commands (see above)
- `app/Notifications/` - Badge/fursuit lifecycle notifications and receipts
- `app/Services/`, `app/Enum/`, `app/Providers/`, `app/Policies/`, `app/Observers/`, `app/Rules/`,
  `app/Events/`, `app/Interfaces/` - supporting layers
- `resources/js/Pages/` - Vue components grouped by interface: `Badges/`, `Manage/`, `POS/`,
  `CatchEmAll/`, `FCEA/`, `Gallery/`, `Info/`, `Auth/`, `Dev/`

### State Management Pattern

The system heavily uses **Spatie Model States** for complex entity lifecycles (Badges, Fursuits,
Checkouts). Always consider the current state and the available transitions rather than mutating
state properties directly.

### Event-Driven Architecture

- Badge creation triggers notifications; state transitions are logged via
  `spatie/laravel-activitylog`
- Background jobs handle printing, ranking updates, and receipt generation. Laravel Horizon manages
  queue processing; the default queue/cache/session driver is `database`
- A scheduler (`routes/console.php`) runs token refresh (daily), FCEA ranking refresh
  (every 15 min), stuck-print-job checks (every 3 min), print-job lease reaping (every minute) and
  fursuit review-decision delivery (every minute)

### Database Design Notes

Badges have `custom_id` for human-readable identification. Soft deletes are used throughout for
audit trails, and activity logging tracks all important changes.

### Testing Approach

- **Pest PHP 3** (`tests/Feature`, `tests/Unit`, fixtures in `tests/Fixtures`), with database
  factories for test data
- Feature tests cover critical user journeys (badge flow, checkout, printing, notifications,
  event order state); event state can be manipulated via the `event:state` command

### Stack and Observability

- **Sentry** (`sentry/sentry-laravel`) for error tracking, **Clockwork**
  (`itsgoingd/clockwork`) for local request profiling/debugging
- **Laravel Octane** is available as the production app server (`config/octane.php` defaults to
  RoadRunner via `OCTANE_SERVER`)
- **Laravel Reverb** provides WebSockets; the frontend uses `laravel-echo`
- **Vite** for frontend asset compilation with HMR
- **Inertia.js** bridges the Laravel backend with the Vue frontend (`tightenco/ziggy` for routes)
- **PrimeVue** component library + **Tailwind CSS** for UI; Lucide icons

## Migrations must be idempotent

Migrations run as an ArgoCD PreSync **Job** (`php artisan migrate --force`) against MySQL, and
**MySQL DDL is not transactional** - a migration that fails partway leaves its applied steps in
place but is never recorded, so the next run hits "Duplicate column / key / table" and blocks every
later migration. This has already caused a dev outage.
Every migration must therefore be safe to re-run. Guard each operation with
`App\Support\Migrations\SchemaGuard`: `missingTable` / `missingColumn` / `hasColumn` /
`hasIndex($table, $nameOrColumns)` / `hasForeignKeyOn` / `hasForeignKeyTo`, and `dropIfExists` in
`down()`. `->change()` may be left unguarded; data `UPDATE`s need `WHERE` guards so they converge.
`tests/Feature/MigrationIdempotencyTest.php` locks in the helper's behaviour.

Full rule, per-operation examples and the outage story: [`docs/migrations.md`](docs/migrations.md).

## Subsystem docs

Read the file for the area you are changing; each records decisions that were re-broken once already.

| Doc | Read it when you touch |
|---|---|
| [`docs/site-navigation.md`](docs/site-navigation.md) | the public header, pill rail, bottom tab bar, footer or `SiteNav/navItems.js` |
| [`docs/gallery.md`](docs/gallery.md) | `/gallery` routes, folder caching, or the derived webp variants |
| [`docs/fursuit-review.md`](docs/fursuit-review.md) | the approval queue, review reasons, publication blocks, undo |
| [`docs/desk-corrections.md`](docs/desk-corrections.md) | POS badge edits, the manager gate, or repricing an open checkout |
| [`docs/prepaid-badges.md`](docs/prepaid-badges.md) | `BadgePolicy::create()`, `getPrepaidBadgesLeft()`, badge pricing |
| [`docs/badge-generation.md`](docs/badge-generation.md) | badge artwork classes in `app/Badges/` |
| [`docs/printing.md`](docs/printing.md) | print batches, jobs, leases, verification, the print agent (build/debug companion: [`docs/printing-implementation.md`](docs/printing-implementation.md)) |
| [`docs/fiscal-compliance.md`](docs/fiscal-compliance.md) | SumUp, Fiskaly TSE signing, DSFinV-K exports |
| [`docs/migrations.md`](docs/migrations.md) | writing any migration |
| [`docs/admin/roles.md`](docs/admin/roles.md) | any route under `routes/manage/`, a policy's `viewAny`/`view`, or a new Settings pane or Tools card - it is the reviewer/admin boundary |
| [`docs/wallet-removal-plan.md`](docs/wallet-removal-plan.md) | payment plumbing - the record of the already-shipped wallet removal (`fa0554e`), not a pending plan |

Repo root also has `CATCH.md` (Catch-Em-All game), `TSE.md` and `zebra.md` (fiscal / printer
hardware), `DSFinV_K_2_4.pdf` (fiscal export spec), `openapi.yml` (API spec) and `README.md` (setup).
`PRINTING_SYSTEM.md` and `PRINTING_SYSTEM_IMPROVEMENTS*.md` describe the retired QZ Tray system - history, not current behaviour.
