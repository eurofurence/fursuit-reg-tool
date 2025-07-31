# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Environment Setup
```bash
# Initial setup
mv .env.example .env
composer install
npm install
npm run build

# Start development environment
./vendor/bin/sail up

# Database operations
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed
```

### Development Commands
```bash
# Frontend development
npm run dev           # Start Vite dev server with hot reload
npm run build         # Build production assets

# Laravel Artisan commands
./vendor/bin/sail artisan tinker              # Interactive REPL
./vendor/bin/sail artisan migrate:status      # Check migration status
./vendor/bin/sail artisan queue:work          # Process background jobs
./vendor/bin/sail artisan horizon             # Laravel Horizon for queues

# Testing
./vendor/bin/sail test                         # Run all tests with Pest
./vendor/bin/sail test --filter=BadgeTest     # Run specific test class
./vendor/bin/pest tests/Feature/BadgeTest.php # Run specific test file

# Code quality
./vendor/bin/sail pint                         # Laravel Pint (PHP CS Fixer)
```

### Event State Management
```bash
# Useful for development/testing - sets up events in different states
php artisan event:state open    # Creates event with order window open
php artisan event:state closed  # Creates event with orders closed
```

## Architecture Overview

### Core System
This is a **Laravel 11 + Inertia.js + Vue 3** application for managing fursuit badge registration at Eurofurence convention. The system handles the complete lifecycle from badge creation to pickup, with integrated payment processing and a "Catch-Em-All" social game.

### Key Domain Models

**Event System**: Events have order windows (`order_starts_at` to `order_ends_at`) that determine when badges can be ordered. Event state is computed dynamically as OPEN/CLOSED based on current time vs. order window.

**Badge Lifecycle**: Badges use Spatie Model States with two parallel state machines:
- Payment States: `Unpaid` → `Paid`
- Fulfillment States: `Pending` → `Printed` → `ReadyForPickup` → `PickedUp`

**Fursuit Management**: Fursuits require approval before badges can be created. States: `Pending` → `Approved/Rejected`

**Wallet Integration**: Uses `bavix/laravel-wallet` for payment processing. Badges implement `ProductInterface` for seamless wallet transactions.

### Application Structure

**Multi-Interface Design**:
- `/` - Public fursuit badge registration interface (Vue/Inertia)
- `/admin` - Filament admin panel for staff
- `/pos` - Point-of-sale system for on-site operations
- `/fcea` - "Catch-Em-All" game interface

**Key Directories**:
- `app/Models/Badge/` - Badge model with state machines
- `app/Models/Fursuit/` - Fursuit management with approval workflow
- `app/Badges/` - Badge rendering system (PDF generation)
- `app/Domain/` - Domain-specific logic (Checkout, Printing)
- `app/Filament/` - Admin panel resources
- `resources/js/Pages/` - Vue components for each interface

### State Management Pattern
The system heavily uses **Spatie Model States** for complex entity lifecycles. When working with badges or fursuits, always consider the current state and available transitions rather than direct property updates.

### Event-Driven Architecture
- Badge creation triggers notifications
- State transitions are logged via `spatie/laravel-activitylog`
- Background jobs handle printing and receipt generation
- Laravel Horizon manages queue processing

### Badge Generation System
- Badges are rendered as PDFs using custom badge classes in `app/Badges/`
- Each badge type extends `BadgeBase_V1` and defines positioning/fonts
- Badge images are stored in S3 and processed for print quality
- QR codes are generated for the Catch-Em-All game integration

### Database Design Notes
- Events use computed state (no `state` column) based on date comparisons
- Badges have `custom_id` for human-readable identification
- Soft deletes are used throughout for audit trails
- Activity logging tracks all important changes

### Testing Approach
- Uses **Pest PHP** testing framework
- Feature tests cover critical user journeys
- Database factory patterns for test data creation
- Event state can be manipulated via `event:state` command for testing

### Development Environment
- **Laravel Sail** for Docker-based development
- **Vite** for frontend asset compilation with HMR
- **Inertia.js** bridges Laravel backend with Vue frontend
- **PrimeVue** component library for UI consistency