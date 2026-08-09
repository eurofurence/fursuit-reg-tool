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

The **full** `prepaid_badges` entitlement is honored as free. (Until bugfix-03 this method also
deducted an extra `1` after `order_starts_at` - "the included badge is no longer honored" - which
wrongly **charged** the user's last prepaid badge; that `−1` is gone. See `docs/bugfix-03-fix.md`.)

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
