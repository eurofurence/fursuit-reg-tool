# Removing `bavix/laravel-wallet`

Status: proposed. Target branch: fresh branch off `main`.

## Why

The wallet is a second, weaker copy of information `badges.status_payment` already holds, and it
is the copy that drifts.

Reconciliation against the prod copy (`fursuit_prod_copy`, 5,283 user wallets):

| | |
|---|---|
| wallets agreeing with badge state (`balance == -sum(unpaid totals)`) | 5,143 |
| wallets drifting | 140 (2.6%) |
| net drift | **+82,297 cents (822.97 EUR) of phantom credit** |
| direction | 139 wallet-credit-heavy, 1 wallet-debt-heavy |

All 139 credit-heavy users have every badge `paid` (expected balance 0) yet hold a positive
balance. The badge state machine stayed correct in every one of these cases.

### Root cause

`ToFinished::handle()` guards the badge transition but not the wallet write:

```php
if ($item->payable->status_payment->canTransitionTo(Paid::class)) {   // idempotent
    $item->payable->status_payment->transitionTo(Paid::class);
}
...
$this->checkout->user->wallet->deposit($this->checkout->total);        // fires on every call
```

Any re-entry (retry, double submit, requeued job) re-credits the user. Confirmed case, user 4567:

```
checkout 1553   total 1100   FINISHED   card   2025-09-03 16:48:32
transactions:   deposit 1100  x7        2025-09-03 16:48:53-54
```

7 × 1100 − 1100 owed = 6600 = the user's balance. At least 69 duplicate-deposit groups exist
dataset-wide (lower bound — that count groups on identical `created_at` to the second, and this
user's seven straddle two seconds).

Removing the wallet removes the unguarded writer. The state machine that survived the same
re-entry becomes the only record.

### Scale of what goes away

| table | rows |
|---|---|
| `wallets` | 15,348 (10,056 of them per-badge wallets, 5,283 user, 9 machine) |
| `transactions` | 23,770 |
| `transfers` | 10,444 |

Plus `config/wallet.php` (194 lines), one composer dependency, and the entire cash-register UI.

## The replacement

One query replaces every balance read:

```php
Badge::whereHas('fursuit', fn ($q) => $q->where('user_id', $user->id))
    ->where('status_payment', Unpaid::$name)
    ->sum('total');
```

Add it to `User` as a single accessor so no call site hand-rolls it:

```php
public function amountDue(): int
{
    return (int) Badge::whereHas('fursuit', fn ($q) => $q->where('user_id', $this->id))
        ->where('status_payment', Unpaid::$name)
        ->sum('total');
}
```

Note the sign flip: wallet balance was negative-for-debt, `amountDue()` is positive-for-debt.
Every consumer currently multiplies by `-1`; that goes away.

## Fiscal position

**DSFinV-K does not read the wallet.** `DSFinVKExportService` sources every generator from
`Checkout` (+ `machine`, `machine.tseClient`). Even cash figures come from
`$machine->checkouts()->where('payment_method','cash')->sum(...)`, not the machine wallet.
The wallet is not in the fiscal path, so removing it does not touch DSFinV-K or TSE output.

> **Retention.** Do **not** drop `wallets`, `transactions`, or `transfers`. German GoBD retention
> runs 10 years, and those tables are the only record of past money movements — including
> `FreeBadgeRepairService` correction credits, which have no equivalent anywhere once `total` is
> rewritten to 0. Stop writing to them, leave them in place. Physical deletion is a separate
> decision for whoever owns the fiscal archive, not part of this change.

## Stages

Each stage is independently shippable and independently revertable.

### Stage 0 — snapshot before touching anything

Export the drift for the record, so the 822.97 EUR is documented while the old system is still
readable:

```sql
SELECT w.holder_id uid, u.name, u.email, w.balance, -x.unpaid expected, (w.balance + x.unpaid) diff
FROM wallets w
JOIN users u ON u.id = w.holder_id
JOIN (
  SELECT u2.id uid,
         COALESCE(SUM(CASE WHEN b.status_payment='unpaid' THEN b.total ELSE 0 END),0) unpaid
  FROM users u2
  LEFT JOIN fursuits f ON f.user_id = u2.id
  LEFT JOIN badges b ON b.fursuit_id = f.id AND b.deleted_at IS NULL
  GROUP BY u2.id
) x ON x.uid = w.holder_id
WHERE w.holder_type = 'App\\Models\\User' AND w.balance <> -x.unpaid
ORDER BY diff DESC;
```

No refunds are owed. Under badge state these 139 users owe nothing and are owed nothing, so the
phantom credit simply ceases to exist at cutover. The CSV is documentation, not a work queue.

### Stage 1 — add `User::amountDue()`

Additive only, nothing else changes. Land it with a test asserting it equals `-balanceInt` for
the 5,143 agreeing users, which is the cheapest possible proof the replacement is faithful.

### Stage 2 — stop writing to the wallet

This is the stage that fixes the bug. After it, drift can no longer grow.

| file | line | change |
|---|---|---|
| `app/Domain/Checkout/Models/Checkout/Transitions/ToFinished.php` | 38 | delete `$this->checkout->user->wallet->deposit(...)` |
| `app/Domain/Checkout/Models/Checkout/Transitions/ToFinished.php` | 34 | delete `$this->checkout->machine->wallet->deposit(...)` |
| `app/Http/Controllers/BadgeController.php` | 173, 188 | delete `forcePay(...)` — badge is already created `Unpaid` with `total` set |
| `app/Http/Controllers/BadgeController.php` | 253-271 | delete the refund / re-`forcePay` pair around the total change; keep the `total`/`subtotal`/`tax` recompute |
| `app/Http/Controllers/BadgeController.php` | 292-302 | delete `refund(...)` on badge/copy deletion; soft delete alone removes it from `amountDue()` |
| `app/Observers/BadgeObserver.php` | 60-70 | delete the refund/`forcePay` dance in `updated()`; keep the `subtotal`/`tax` recompute and `saveQuietly()` |
| `app/Services/FreeBadgeRepairService.php` | 138-150 | delete the `$user->deposit(...)` credit and the `$refunded` accumulator; the `activity()` log below it already records `old_total` |
| `app/Http/Controllers/AuthController.php` | 106 | delete `$user->wallet->balance;` — a bare statement whose only effect is forcing wallet creation |

`BadgeController:162` reads `$total === 0 ? Paid::$name : Unpaid::class` — mixing `::$name` and
`::class`. Spatie accepts both, so it is not a bug, but normalise it to `::$name` while in here.

`FreeBadgeRepairService` returns `$refunded` in its result payload and
`tests/Feature/FreeBadgeRepairServiceTest.php` asserts on it; drop the field from both, or keep
the key reporting the sum of `old_total` if the admin UI displays it.

### Stage 3 — swap the readers

| file | line | from | to |
|---|---|---|---|
| `app/Http/Middleware/HandleInertiaRequests.php` | 59 | `'balance' => $request->user()?->balanceInt` | `'amountDue' => $request->user()?->amountDue()` |
| `resources/js/Components/PaymentInfoWidget.vue` | 5, 9, 11 | `auth.balance`, `balance < 0`, `balance * -1` | `auth.amountDue`, `amountDue > 0`, `amountDue` |
| `app/Http/Controllers/POS/AttendeeController.php` | 64, 91 | `->load('wallet')` (both) | drop |
| `app/Http/Controllers/POS/AttendeeController.php` | 96 | `'transactions' => $user->wallet->transactions()...` | drop the prop |
| `resources/js/Pages/POS/Attendee/Show.vue` | 83 | `Math.max(0, (attendee.wallet?.balance ?? 0) * -1)` | `attendee.amount_due` |
| `resources/js/Pages/POS/Attendee/Show.vue` | 68, 252 | `transactions` prop, `<WalletTransactionsTable>` | delete; the `checkouts` prop already on this page is the real payment history |
| `resources/js/Components/POS/Attendee/WalletTransactionsTable.vue` | — | whole file | delete |
| `resources/js/Components/POS/Attendee/BadgesTable.vue` | 82 | `field="wallet.balance"` | `field="total"` |

`BadgesTable.vue:82` renders the Price column from the badge's own wallet balance. Those agree
with `badges.total` on 9,596 of 9,808 live badges and disagree on 212 (all `paid`) — rows where
the observer's refund/re-pay dance or a repair-service credit moved one and not the other. So
this swap also fixes a display bug on 212 badges.

`app/Http/Controllers/StatisticsController.php:524-527` mentions wallets only in comments over
hardcoded `0` stubs. Leave the stubs, delete the misleading comments.

### Stage 4 — delete the cash register

2025 usage was 4 transactions across 2 machines (2024 was heavy: 680 transactions, up to 247.00
EUR in a drawer). DSFinV-K derives cash from checkouts, so nothing fiscal depends on it.

What is genuinely lost: manual float-in / cash-out movements exist only as machine wallet
transactions and never as checkouts, so drawer-vs-expected reconciliation goes away. Accepted —
the feature is unused.

Delete:

- `app/Http/Controllers/POS/CashRegisterController.php`
- `resources/js/Pages/POS/CashRegister/` (`Show.vue`, `AddMoney.vue`, `RemoveMoney.vue`)
- `routes/pos.php:15-22` — the whole `/wallet` prefix group (5 routes, `pos.wallet.*`)
- `resources/js/Pages/POS/Dashboard.vue:118-122` — the cash register tile referencing `pos.wallet.show`
- `resources/js/composables/usePosKeyboard.js:46` — the `F2: '/pos/wallet'` binding

Grep for `pos.wallet` afterwards; a stale Ziggy route name fails at runtime, not build time.

### Stage 5 — remove the package

| file | change |
|---|---|
| `app/Models/User.php` | drop `implements Customer, WalletFloat` (keep `FilamentUser`), drop `CanPayFloat` from the trait list, drop the 3 `Bavix\` imports |
| `app/Models/Badge/Badge.php` | drop `implements ProductInterface`, drop `HasWalletFloat`, drop the 3 `Bavix\` imports, delete `getAmountProduct()` (63) and `getMetaProduct()` (68) |
| `app/Models/Machine.php` | drop `HasWalletFloat` and its import |
| `config/wallet.php` | delete |
| `composer.json` | `composer remove bavix/laravel-wallet` |

The package is **`bavix/laravel-wallet` ^11.1** (not barryvdh — that is the debugbar/dompdf
author, unrelated).

Its migrations stay in `database/migrations/` untouched, otherwise a fresh install cannot build
the retained tables. They are:

```
2018_11_06_222923_create_transactions_table.php
2018_11_07_192923_create_transfers_table.php
2018_11_15_124230_create_wallets_table.php
2021_11_02_202021_update_wallets_uuid_table.php
2023_12_30_113122_extra_columns_removed.php
2023_12_30_204610_soft_delete.php
2024_01_24_185401_add_extra_column_in_transfer.php
2026_04_26_141130_fix_ef30_wallet_balance.php
```

`2025_08_23_022437_add_performance_indexes_for_pos_and_gallery.php` and
`2025_09_03_003847_add_critical_pos_performance_indexes.php` also index these tables — harmless,
leave them.

If the package's service provider is what registers those migrations rather than the repo owning
the files, copy them into `database/migrations/` **before** running `composer remove`. Verify
with `fd -e php . database/migrations | rg -i 'wallet|transaction|transfer'` — if the list above
comes back, the repo owns them and nothing needs copying.

## Verification

Before Stage 2 and after Stage 5, on a copy of prod:

1. `SELECT SUM(total) FROM badges WHERE status_payment='unpaid' AND deleted_at IS NULL` — must be
   unchanged across the whole migration. This is the number the business cares about.
2. For a sample including user 1042 (Hai, owes 300) and user 4567 (Flokili, phantom 6600):
   `amountDue()` must return 300 and 0 respectively.
3. Run a full POS checkout end-to-end: badge → `Unpaid` → checkout → `ToFinished` → badge `Paid`,
   `amountDue()` drops by the badge total.
4. Fire `ToFinished` twice on the same checkout. Before: balance double-credits. After: no
   change on the second call. This is the regression test worth keeping.
5. `./vendor/bin/sail test` — only `FreeBadgeRepairServiceTest` touches wallets today.
6. `rg -i 'bavix|balanceInt|forcePay|->wallet|pos\.wallet'` over `app/ resources/ routes/ tests/`
   returns nothing.
7. `php artisan dsfin:generate-direct-export` for a known range — byte-identical output before
   and after.

## Rollback

Stages 1, 3, 4, 5 are pure code and revert with git.

Stage 2 is the one-way door: once writes stop, the wallet ledger no longer tracks new badges, and
reverting Stage 2 leaves every badge created in the meantime missing its debit. If Stage 2 has
shipped and you need the wallet back, rebuild balances from badge state rather than reverting —
which is itself the proof that badge state is the better record.

## Open item

`AuthController:106` (`$user->wallet->balance;`) exists to force wallet creation on login. Nothing
replaces it, and nothing needs to — but confirm no Filament resource or admin widget lazily
assumes `$user->wallet` is non-null. A grep of `app/Filament/` for `wallet|balance|forcePay|refund`
returns nothing, so this looks clear.
