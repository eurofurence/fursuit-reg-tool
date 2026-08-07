# Fursuit Registration Tool

The badge registration and fulfillment system for **Eurofurence** fursuiters — from ordering a
personalized badge through on-site printing and pickup, with integrated payments, German fiscal
compliance, and the "Catch-Em-All" social game.

[![Laravel Tests](https://github.com/eurofurence/fursuit-reg-tool/actions/workflows/laravel.yml/badge.svg)](https://github.com/eurofurence/fursuit-reg-tool/actions/workflows/laravel.yml)
[![Docker Build and Publish](https://github.com/eurofurence/fursuit-reg-tool/actions/workflows/docker.yml/badge.svg)](https://github.com/eurofurence/fursuit-reg-tool/actions/workflows/docker.yml)

Built with **Laravel 11**, **Inertia.js + Vue 3**, **Filament**, and **PrimeVue**.

---

## What it does

The system manages the full badge lifecycle and is split into several interfaces:

| Path            | Audience    | Purpose                                                             |
| --------------- | ----------- | ------------------------------------------------------------------- |
| `/`             | Attendees   | Public badge registration & customization (Vue/Inertia)             |
| `/admin`        | Staff       | Inertia admin panel (badges, fursuits, events, payments, printing)  |
| `/admin-legacy` | Staff       | The Filament panel it replaces, live until cutover                  |
| `/pos`          | On-site ops | Point-of-sale: checkout, cashier, printing, machine auth            |
| `/catch-em-all` | Attendees   | Mobile-first "Catch-Em-All" game (PWA)                              |
| `/gallery`      | Public      | Fursuit gallery                                                     |
| `/api`          | Services    | REST API (e.g. `GET /api/fursuits`)                                 |

**Highlights**

- **Badge lifecycle** via Spatie model states — payment (`Unpaid → Paid`) and fulfillment
  (`Pending → Processing → ReadyForPickup → PickedUp`).
- **Fursuit approval** workflow before badges can be created.
- **Payments** through the `bavix/laravel-wallet` integration and SumUp card readers.
- **Fiscal compliance** for the German market (Fiskaly TSE signing, DSFinV-K export).
- **Printing** driven through QZ Tray, with print-job and printer state management.
- **Background processing** with Laravel Horizon; real-time updates via Laravel Reverb.

---

## Tech stack

- **Backend:** Laravel 11, PHP 8.2+ (CI and images run 8.4), Laravel Octane (Swoole) in production
- **Frontend:** Inertia.js, Vue 3, PrimeVue, Tailwind CSS, Vite
- **Admin:** Filament 3
- **Data:** MySQL, Redis, Laravel Horizon (queues)
- **Notable packages:** spatie/laravel-model-states, spatie/laravel-activitylog, bavix/laravel-wallet,
  laravel/reverb, laravel/sanctum, laravel/socialite, mpdf/mpdf

---

## Getting started (local)

**Prerequisites:** PHP 8.2+, Composer, Node.js 20+, and Docker (with the Compose plugin).
Local development uses [Laravel Sail](https://laravel.com/docs/sail).

```sh
# 1. Configure the environment
cp .env.example .env

# 2. Install dependencies
composer install
npm install

# 3. Build front-end assets
npm run build

# 4. Start the stack (app, MySQL, Redis, Mailpit)
./vendor/bin/sail up -d

# 5. Generate the app key and run migrations
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

The app is then available at **http://localhost**.

> Nix/devenv users: an `.envrc` flake is provided — `direnv allow` sets up the toolchain and a
> `sail` alias automatically.

### Useful commands

```sh
npm run dev                              # Vite dev server with hot reload
./vendor/bin/sail artisan tinker         # REPL
./vendor/bin/sail artisan horizon        # Process queues
./vendor/bin/sail artisan event:state order   # Seed an event in a given state (dev/testing)
```

`event:state` accepts `pre-order`, `order`, `event-order`, or `closed` — handy for exercising the
ordering flow locally.

---

## Testing & code quality

```sh
./vendor/bin/pest                  # Run the test suite (Pest)
./vendor/bin/pest --filter=Badge   # Run a subset
./vendor/bin/pint                  # Format code (Laravel Pint)
```

CI runs the Pest suite on every push and pull request (`.github/workflows/laravel.yml`), plus
CodeQL security analysis.

---

## Deployment

Deployment is fully automated and container-based.

1. **Build & publish** — on every push to `main` (and on releases), a GitHub Actions workflow
   (`.github/workflows/docker.yml`) builds the production image and pushes it to Docker Hub under
   the `eurofurence/` namespace as **`eurofurence/fursuit-reg-tool`**. The image is built from the
   multi-stage [`Dockerfile`](Dockerfile) (PHP 8.4 + a Node/Vite asset build) and serves the app
   with **Laravel Octane** (`octane:start`).

2. **Release** — the cluster runs **Kubernetes** with **ArgoCD** syncing a Helm chart. Database
   migrations run as an ArgoCD **PreSync hook Job** (`php artisan migrate --force`) before the new
   version goes live; queues run via Horizon and WebSockets via Reverb.

3. **Environments** — images are tagged per environment (e.g. `:dev` for the development cluster,
   semver tags for releases). Update the relevant Job/Deployment manifest to the desired tag to
   roll out.

> **Migrations must be idempotent.** They run unattended in the PreSync Job against MySQL, whose DDL
> is not transactional — a partial failure can otherwise block every later migration. Guard schema
> operations with `App\Support\Migrations\SchemaGuard`. See [`CLAUDE.md`](CLAUDE.md) and the
> write-ups under [`docs/`](docs/).

Application configuration is supplied entirely through environment variables (see `.env.example`).

---

## Further documentation

- [`CLAUDE.md`](CLAUDE.md) — architecture overview and conventions
- [`CATCH.md`](CATCH.md) — Catch-Em-All game design
- [`PRINTING_SYSTEM.md`](PRINTING_SYSTEM.md) — printing subsystem
- [`TSE.md`](TSE.md) — fiscal / TSE integration
- [`openapi.yml`](openapi.yml) — API specification

---

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
