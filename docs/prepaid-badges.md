# Prepaid badges

Two prepaid calculations that look interchangeable and are not. Read this before touching
`BadgePolicy::create()`, `User::getPrepaidBadgesLeft()` or badge pricing in `BadgeController@store`.

## "Can create" vs. "free badges left"

**Prepaid badges: "can create" vs. "free badges left" are two different things.** Two related
prepaid calculations - do **not** merge their values:

- `App\Policies\BadgePolicy::create()` uses `prepaid_badges − ordered` to decide whether a user may
  create a badge **at all** (the badge may end up **paid**). A prepaid allowance bypasses the closed
  order-window restriction.
- `App\Models\User::getPrepaidBadgesLeft()` = `prepaid_badges − orderedMainBadges` (only main badges
  count; spare copies - `extra_copy_of != null` - are always separately paid and never consume the
  allowance). It answers **how many free badges remain** and drives badge **pricing** in
  `BadgeController@store`.

## The free badge deadline

`prepaid_badges` is a **sum of two different entitlements**, written by `AuthController` from the
registration system: `fursuit` package count (the one badge that comes with the package) plus
`fursuitadd` count (extra copies bought on top). Only the first of them expires.

`getPrepaidBadgesLeft()` deducts `1` once `events.free_badge_deadline` has passed. Extra copies were
paid for in the registration system and stay free whenever they are claimed; the included badge is
free only if the badge is submitted by the date the Welcome page and the FAQ quote.

**This is not the `−1` that bugfix-03 removed, and re-removing it re-opens a live money bug.** The
old one keyed off `order_starts_at`, the moment general ordering *opens*, so it charged the included
badge for the entire window it was meant to be free in - that is what made it a bug. This one keys
off `free_badge_deadline`, the column added for exactly this question in
`2026_08_08_160000_add_free_badge_deadline_to_events_table`.

That column shipped unenforced: for a year it was only ever rendered on the Welcome page and the
FAQ, so the deadline attendees were shown did nothing. On EF30 that meant 678 badges ordered after
the cutoff went out free before it was caught, mid-convention. `FreeBadgeDeadlineTest` locks the
rule in.

A null deadline means the event never set one, and the entitlement is honored in full.

The deadline decides **price only**. `BadgePolicy::create()` still lets a prepaid holder order
outside the order window - they are simply charged for it now.

A user with `getPrepaidBadgesLeft() == 0` can still order an additional **paid** badge while the
order window is open. The public Welcome page (`Welcome.vue`) therefore gates its create/customize
button on the authoritative `canCreate` (`Gate::allows('create', Badge::class)`) passed by
`WelcomeController`, not on `prepaidBadgesLeft`. `PrepaidBadgePriceConsistencyTest` locks in the
pricing; `DbServiceController` (admin → Maintenance → DB Service) repairs already-wrongly-charged
badges. It replaces `App\Services\FreeBadgeRepairService`, which was deleted in `5aa2148` together
with the old admin page it served; the repair now lives in the controller that owns the screen, and
zeroing the badge total *is* the correction since the wallet credit went with the wallet package
(`fa0554e`). See `docs/bugfix-01-result.md`, `docs/bugfix-03-fix.md`, and `docs/handoff.md`.

Reconciliation against fursuit packages is `php artisan prepaid:update`.

Note: the `docs/bugfix-01-result.md`, `docs/bugfix-03-fix.md` and `docs/handoff.md` write-ups cited
above are not in this repository (they have never been committed). The rules above are the surviving
record of what they said.
