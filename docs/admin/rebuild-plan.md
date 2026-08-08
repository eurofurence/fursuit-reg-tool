# Admin Rebuild Plan: Filament -> Inertia + Vue 3 at `/admin`

Companion to [`current-filament-features.md`](./current-filament-features.md), which is the parity
contract. That document is 2 824 lines and it is the only baseline this rebuild has. This file is the
design spec plus the build, verify and cutover plan.

Prior art: the same migration was executed in the sibling repo `ef-streaming`
(`docs/admin/rebuild-plan.md`, 405 lines). Its generic table/column/filter/action layer
(`app/Support/Manage/*.php`, 1 939 lines) and its component set
(`resources/js/Components/Manage/`, 3 494 lines across 28 files) are the main thing worth porting,
and most of it copies over with model swaps only. The numbers behind that claim are in 1.5 and in
the stack decision below.

Decisions taken:

| Decision | Choice |
|---|---|
| Frontend stack | **Stay on Tailwind 3 + PrimeVue 3. Port the ef-streaming Manage components as plain-Tailwind components.** Do not upgrade to Tailwind 4, do not rewrite them onto PrimeVue |
| Visual direction | Dense operator table, light-first, reusing this repo's existing token conventions rather than forking a second palette |
| Cutover | The new panel takes `/admin`; Filament moves to `/admin-legacy` and stays fully working there until the parity suite is green |
| Test depth | Server-side parity tests per module, transcribed from the audit doc. No Playwright in phase 1 |
| Authorization | Introduce real policies. Today there are effectively none for three resources |

Baseline recorded before any change: `php artisan test` has **no Filament coverage worth calling a
baseline**. `tests/Feature/DbServiceMaintenancePageTest.php` is the only test that touches the panel:
4 cases covering one page's admin gate and one Livewire round-trip through
`FreeBadgeRepairService`. There is not one assertion anywhere about a column, a filter, a sort, a
row action or a confirm modal. This is the single biggest difference from the ef-streaming migration,
which started from a 17-case panel suite. Part 4 says what replaces it.

---

## Part 1 - Design spec

### 1.1 Intent

The admin panel serves three different jobs that have nothing in common except the login:

1. **Moderation.** A reviewer works a queue of pending fursuits, one at a time, keyboard-first,
   hundreds of records in a sitting. Speed and a stable next-record path matter more than anything.
2. **Convention operations.** Staff watch printers, batches and cards during a live print run and
   need to know which card nobody has vouched for. This is the surface where a wrong click costs
   physical stock.
3. **Records.** Checkouts, TSE clients, users and events are read-mostly, audited, and occasionally
   fiscally load-bearing.

So: high information density, colour reserved for state, destructive actions never one click away,
and every money figure rendered through one formatter rather than per call site (7.5 of the audit
lists six `->money(` call sites and one of them is wrong).

The current panel's only styling is `public/css/filament-custom.css`, 8 lines that squeeze table
cell padding to 2px. That density is the one piece of the current look worth keeping and the spec
below bakes it in rather than bolting it on.

### 1.2 Layout skeleton

```
┌────────────────────────────────────────────────────────────────────────────┐
│ EF29 (2026) ▾ ✓ Orders open  12 pending reviews 3 left to print 40 printed │  h-10, sticky
├─────┬──────────────────────────────────────────────────────────────────────┤
│ ▣ 1 │  Badges                                    [ Print selected ]        │  h-14 page header
│ ▤ 2 │ ──────────────────────────────────────────────────────────────────── │
│ ▥ 3 │  fulfillment ▾  payment ▾  free ▾  attendee 1..1000    ⌕ search     │  h-11 filter bar
│ ▦   │ ──────────────────────────────────────────────────────────────────── │
│ ▧   │ ☐ IMG  FURSUIT        OWNER      BADGE ID  ATT#  JOBS  FULFIL  PAY  │  h-7 header row
│     │ ☐ ◯   Blue Fox       J. Doe     EF29-014  0142   1     READY   PAID│  h-7 rows
│ ⚙   │ ☐ ◯   Grey Wolf      A. Smith   EF29-015  0143   2(1!) PROC    PAID│
│     │ ──────────────────────────────────────────────────────────────────── │
│     │  1-25 of 1 284                                       ‹ 1 2 3 ›  25 ▾ │
└─────┴──────────────────────────────────────────────────────────────────────┘
  220px sidebar                fluid content, no max-width (matches maxContentWidth('100%'))
```

- **Top strip.** Left: the global event selector, which is this app's defining cross-cutting
  control (2.9). Right of it: whether the selected event's order window is open, and the two counts
  staff act on: fursuits waiting on a review, badges with no card yet, and badges already
  printed. All deep-link: the review count opens the queue itself, the two print counts the
  badge list filtered to each half of the run.
- **Sidebar.** Permanent, 220px, always labelled, no collapse. Groups in a **declared** order, which
  the current panel does not have (audit 2.2: no `->navigationGroups()` call, three `navigationSort`
  collisions, so today's order is partly accidental). Declared order:
  Events & Registration / Sales / POS / Printing / User Management / Tools / Maintenance.
  Printing is split out of POS because it is a distinct job with a distinct on-call audience.
- **Page header.** Title, optional subtitle, right-aligned actions. No breadcrumbs.
- **Content.** Fluid width.
- Detail pages are full pages with real URLs, not modals. `EventResource`, `SpecialCodeResource` and
  `UserResource` use Filament `ManageRecords` pages today, so create and edit happen in modals with
  no URL. Those become `/admin/{module}/{record}/edit` pages so they are linkable and testable.

### 1.3 Tokens

This repo already carries two token systems and the rebuild adds a third namespace rather than
touching either:

- `resources/css/app.css` `:root` - the PrimeVue ramp: `--primary-50..950`, `--surface-0..950`,
  consumed through `tailwind.config.js` as `rgb(var(--surface-200))` and friends.
- `resources/css/pos.css` (new on this branch, 855 lines at the time of writing and still growing) -
  the POS "Cool Ledger" skin, which works by **re-pointing `--surface-*` inside `.pos`** so the
  26 701 lines of unstyled PrimeVue preset re-skin themselves. Nothing in `/admin` may disturb that.

The ef-streaming Manage components use the names `surface-0..3`, `fg-1..3`, `hairline` and
`state-{live,ok,warn,idle,danger,info}`. Only one of those collides here: `surface-0` is already
defined in `tailwind.config.js` as white. So:

- `surface-0..3` are renamed **`mg-surface-0..3`**. Across the whole source directory that name
  appears 53 times in 19 files (`surface-0` 6, `surface-1` 9, `surface-2` 24, `surface-3` 14), but
  16 of those are in files that are not ported (`colorRamp.js`, `CutEditor.vue`, `PlannerTrack.vue`,
  `ScheduleRow.vue`). The rename that actually has to happen is **37 occurrences across the 15
  ported files**, and it is mechanical.
- `fg-1/2/3`, `hairline` and `state-*` are unused names in this repo and are ported verbatim.
  Same split: 275 occurrences in the directory (124 / 54 / 97), of which **196 are in ported files**
  (84 / 39 / 73) and 79 are in the not-ported set.

Added to `tailwind.config.js` under `theme.extend.colors`, in the same
`rgb(var(--x) / <alpha-value>)` form the POS tokens already use, so the opacity modifiers the ported
files use on these names keep working under Tailwind 3. They are not confined to `tones.js` and they
span more than two values: `/10`, `/12`, `/25`, `/30`, `/35`, `/40`, `/50`, `/60` and `/95`
(`surface-1/95`, `state-live/50`, `hairline/60`, `fg-3/25` and so on).

**The values are re-authored, not copied.** ef-streaming declares these tokens in
`resources/css/app.css:57-70` as `@theme` entries pointing at `--surface-*` etc., whose values are
`oklch(...)` (`app.css:84-92` dark, `:113-121` light). `rgb(var(--x) / <alpha-value>)` cannot consume
an `oklch()` string, so each value is converted once to space-separated RGB channels, exactly like
the existing `--pos-*` tokens (`--pos-canvas: 233 237 244`). Converting 14 colours twice, once per
theme block, is the whole of it:

```
--mg-surface-0   app background
--mg-surface-1   rail, top strip
--mg-surface-2   cards, table header, filter bar
--mg-surface-3   row hover, popovers
--fg-1 --fg-2 --fg-3      text, labels, placeholders
--hairline                1px separators
--state-live --state-ok --state-warn --state-idle --state-danger --state-info
```

Values live in a new `resources/css/manage.css`, imported from `app.js` alongside `pos.css`. Same
pattern as the POS skin: a different look is a different value block.

**The token values go on `:root`, not on `.manage`.** `pos.css:12-17` already records why, and the
lesson transfers exactly: PrimeVue teleports overlays (`Dialog`, `Menu`, `Toast`) to `<body>`, which
is outside the layout subtree. `ActionButton.vue`'s confirm dialog becomes a PrimeVue `Dialog` (1.5),
so tokens scoped under `.manage` would leave every `/admin` confirm modal and every toast rendering
against undefined custom properties. `.manage` claims only the canvas; a `.manage-surface` class is
put on the teleported overlay roots the way `.pos` does it, and `manage.css` carries a light and a
dark value block on `:root` / `.dark` like `app.css` does.

**Status colour is decided server-side, once.** `App\Support\Manage\Status` returns
`['label' => …, 'tone' => …, 'icon' => …]` and the client looks the tone up in `tones.js`. This is
how the audit's colour drift gets fixed rather than ported. Today the same `PrintJobStatusEnum`
renders in two vocabularies and two colour APIs: `PrintJobResource` prints raw `->value` through the
deprecated `BadgeColumn` while `PrintJobsRelationManager` prints `->label()` through
`TextColumn->badge()`, so `queued` reads as `queued` on one screen and `Claimed` on another
(audit 7.9, 7.10). One mapping table, one vocabulary.

| Domain state | Tone | Glyph |
|---|---|---|
| paid, approved, printed, verified, idle printer, completed batch, active machine | `state-ok` | `●` |
| pending, processing, queued, retrying, paused, ribbon low, unverified | `state-warn` | `◐` |
| unpaid, cancelled, archived, deregistered, offline | `state-idle` | `○` |
| rejected, failed, error, media jam, cover open, out of cards | `state-danger` | `▲` |

### 1.4 Typography and density

- UI text 13px/18px, labels 11px uppercase with 0.06em tracking, page titles 18px semibold.
- Table rows **28px**, header 24px, cell padding `px-3 py-0.5`. This is the deliberate successor to
  `filament-custom.css`'s 2px cell padding. A 40px comfortable mode is not phase 1.
- All numerics `font-variant-numeric: tabular-nums`, right-aligned. Attendee ids, badge custom ids,
  RFID contents, TSE serials, setup codes and PINs in the mono stack.
- Money: one server-side formatter, always from integer cents, always `€`. See change 1 in 2.10.
- Dates keep the current format strings verbatim (audit 7.6): `M j, Y`, `M j, Y H:i`, `M j, H:i`,
  `d.m.Y H:i`, plus a relative `since()` equivalent for last-seen columns. Formatted on the server;
  `dayjs` is already installed and handles only relative display.

### 1.5 Component inventory

Under `resources/js/Components/Manage/`. Source line counts are from
`/Users/martin/Projects/Eurofurence/ef-streaming/resources/js/Components/Manage/`.

| Component | Verdict | Source LOC | Note |
|---|---|---|---|
| `DataTable.vue` | PORT | 342 | Supports `text/number/badge/image/bool/icon/color/copyable/toggle/datetime`. Every column type this panel needs already exists |
| `FilterBar.vue` | PORT | 159 | select / ternary / boolean / search, all bound to the query string |
| `ManageIcon.vue` | PORT | 134 | lucide wrapper, and the one portable file with an external-library import (`lucide-vue-next`). This repo has it at `^0.536.0` against ef-streaming's `^0.542.0`, so the named icon exports in the map are checked on port, not assumed. Icon name map extended with this app's set |
| `FormField.vue` | PORT | 102 | |
| `FileUploadField.vue` | PORT | 95 | private-S3 upload endpoint, preview, replace |
| `Pagination.vue` | PORT | 73 | page links plus per-page select |
| `ToastHost.vue` | PORT | 72 | |
| `CodeBlock.vue` | PORT | 63 | mono, copy, download |
| `useTableQuery.js` | PORT | 74 | |
| `useToasts.js` | PORT | 56 | |
| `FormSection.vue` | PORT | 55 | collapsible variant covers the five collapsed Filament sections |
| `CopyableText.vue` | PORT | 54 | replaces Filament `->copyable()` on `custom_id`, RFID content, login link |
| `CheckboxList.vue` | PORT | 49 | |
| `tones.js` | PORT | 42 | tone -> class map, after the `mg-surface-*` rename |
| `FormActions.vue` | PORT | 29 | |
| `StatCard.vue` | PORT | 27 | |
| `StatusBadge.vue` | PORT | 25 | consumes the `{label, tone, icon}` triple |
| `PageHeader.vue` | PORT | 24 | |
| **PORT subtotal** | | **1 475** | 18 files, copied with model swaps only |
| `ActionButton.vue` | ADAPT | 173 | The only file in the set that imports a shadcn primitive (`@/Components/ui/dialog`). Swap for PrimeVue `Dialog`, roughly 15 lines |
| `ManageSidebar.vue` | ADAPT | 82 | Structure ports; the group/item list is this app's |
| `ManageStatusStrip.vue` | ADAPT | 95 | Structure ports; the segments become event selector, orders-open, pending reviews, left to print, printed |
| `ManageLayout.vue` | ADAPT | 38 | In `Layouts/`, not `Components/Manage/` |
| `EventSelector.vue` | NEW | - | The global event dropdown. 2.9 |
| `RelationTable.vue` | NEW | - | Embedded child table for the four relation-manager equivalents (checkout items, RFID tags, batch cards, fursuit activity) |
| `MoneyCell` (inside `DataTable`) | NEW | - | Not a component; a `type: 'money'` arm added to `DataTable.vue`, so cents formatting cannot be forgotten per call site |
| `ChartDoughnut.vue`, `ChartBar.vue` | NEW | - | `chart.js` 4.5 is already a dependency (used by POS statistics), so the two dashboard widgets reuse it rather than adding one |
| `PinField.vue` | NEW | - | 6-digit PIN and 6-char setup-code inputs with the generate action, mono, no autocomplete |
| `CutEditor.vue` | not ported | 664 | streaming only |
| `PlannerTrack.vue` | not ported | 320 | streaming only |
| `colorRamp.js` | not ported | 223 | streaming only |
| `plannerTime.js` | not ported | 139 | streaming only |
| `ChartArea.vue` | not ported | 169 | streaming only; this app's charts are doughnut and bar |
| `ScheduleRow.vue` | not ported | 122 | streaming only |
| `ShowStatusControl.vue` | not ported | 32 | streaming only |

Server side, from `/Users/martin/Projects/Eurofurence/ef-streaming/app/Support/Manage/`:

| File | Verdict | Source LOC | Note |
|---|---|---|---|
| `Table.php` | PORT | 324 | query -> envelope: filters, search, sort, pagination, hidden columns, row/bulk/page actions |
| `Column.php` | PORT | 231 | plus one new factory, `Column::money()` |
| `Filter.php` | PORT | 192 | select / ternary / boolean, defaults, indicators |
| `Action.php` | PORT | 132 | link/post/put/delete, icon, tone, confirm copy, fields, `disabled(reason)` |
| `Toast.php` | PORT | 95 | flash-forward toast, tone/title/body |
| **PORT subtotal** | | **974** | |
| `Status.php` | ADAPT | 148 | `Status::make()` and `Status::toggle()` port; the seven streaming domain mappers are replaced by this app's (badge payment, badge fulfillment, fursuit, checkout, printer, print job, print batch, TSE client, machine archived) |
| `Navigation.php` | ADAPT | 131 | Structure ports; groups, items and badge counts are this app's |
| `Overview.php` | not ported | 342 | streaming edge/capacity/viewer cards |
| `Settings.php` | not ported | 344 | streaming settings registry; this app has no equivalent |

**Total copied with model swaps only: 2 449 lines** (974 PHP + 1 475 JS). Adapted:
a further 667 lines whose structure survives (173 + 82 + 95 + 38 + 148 + 131).

Nothing new is added to `package.json`. `lucide-vue-next`, `dayjs`, `chart.js`, `primevue` and
`primeicons` are already installed.

### The stack decision, and the number behind it

The choice was posed as (a) upgrade to Tailwind 4 and vendor the shadcn primitives so the streaming
layer copies verbatim, or (b) rewrite the generic components onto PrimeVue and stay on Tailwind 3.
Neither is right, because the premise that the streaming Manage layer is shadcn-based is false.

**What the files actually import.** `rg -U -o "from '[^']+'"` across all 28 files in
`ef-streaming/resources/js/Components/Manage/` returns exactly five non-relative sources:
`vue` (18), `@inertiajs/vue3` (8), `lucide-vue-next` (1, in `ManageIcon.vue`), `hls.js` (1, in
`CutEditor.vue`, not ported), and **one** line:

```
ActionButton.vue:  import { Dialog, DialogContent, DialogDescription, DialogFooter,
                            DialogHeader, DialogTitle } from '@/Components/ui/dialog';
```

That is it. Of the 21 portable files, 19 import nothing but `vue`, `@inertiajs/vue3` and their own
siblings; `ManageIcon.vue` imports `lucide-vue-next`, which this repo already has; and
`ActionButton.vue` is the single file that reaches for a UI primitive library. They are plain Vue
plus Tailwind utility classes whose colour names resolve through CSS custom properties.

`ef-streaming` does carry `reka-ui ^2.5.0` and `radix-vue ^1.9.17` in its `package.json`, but neither
is reachable from any file marked PORT — the only path to a primitive is the `ActionButton.vue` line
above, which is marked ADAPT.

Nor do the portable files use Tailwind-4-only syntax. Scanning all 21 for `size-N`, `bg-linear-*`,
`outline-hidden`, `shadow-xs`, `rounded-xs`, `blur-xs`, `inset-ring`, `field-sizing-*` and bare
`ring` returns zero hits. Their only Tailwind 4 dependency is the `@theme` colour-token namespace —
ef-streaming has no `tailwind.config.js` at all and declares its tokens in
`resources/css/app.css:3-71` — and that is precisely what the `mg-*` block in 1.3 replaces.

**What Tailwind 4 would actually cost here.** `resources/js/app.js` runs PrimeVue with
`unstyled: true, pt: Lara`. Every PrimeVue widget on the public site, in the POS and in the
Catch-Em-All PWA gets its entire appearance from `resources/js/presets/` - 184 files, 26 701 lines
of Tailwind 3 class strings. Counted inside those files: 402 `ring-*` (v4 changes the default ring
width from 3px to 1px), 383 bare `border` (v4 changes the default border colour from `gray-200` to
`currentColor`), 324 `outline-none` (renamed `outline-hidden`), 18 `overflow-ellipsis` (removed),
4 `bg-opacity-*` (removed), and 2 286 of the 2 291 lines in `resources/js` that carry a `dark:`
variant, which today relies on `darkMode: 'class'` and under v4 needs an explicit
`@custom-variant dark`. Outside the presets: 37 `bg-gradient-*` that v4 renames to `bg-linear-*`,
10 `flex-shrink-*`, 5 `bg-opacity-*` occurrences on 4 lines (`Pages/Badges/BadgeForm.vue:389`,
`Pages/FCEA/Dashboard.vue:113`, `Pages/Gallery/GalleryIndex.vue:428` and `:448`) and the remaining
5 `dark:` lines. Counted as occurrences rather than lines, `resources/` holds 2 533 `dark:` variants,
essentially all of them in the presets. The whole colour config, including the 12 `pos-*` tokens,
`borderRadius.pos` and the three `minHeight.pos-*` values, moves from `tailwind.config.js` into
`@theme`.

That is not a hypothetical risk in this repo. The POS is mid-rework on this branch right now, and
the figures move day to day: at the time of writing `git diff --shortstat main -- resources/js` is
31 files, 966 insertions, 2 280 deletions, with 63 dirty paths in the working tree. The rework's
central mechanism is the new `resources/css/pos.css`, 855 lines and growing, which re-points
`--surface-*` **inside `.pos`** so those 26 701 preset lines re-skin themselves. A Tailwind 4 upgrade
lands directly on top of that, on the interface that runs the till at a live convention.

Option (b) is also wrong, in the other direction. Rewriting the generic set onto PrimeVue means
rewriting `DataTable` 342, `ActionButton` 173, `FilterBar` 159, `FormField` 102, `FileUploadField`
95, `Pagination` 73, `ToastHost` 72, `FormSection` 55, `CheckboxList` 49, `FormActions` 29,
`StatCard` 27, `StatusBadge` 25 - **1 201 lines rewritten**, and PrimeVue's `DataTable` brings a
client-side column/filter model that fights the server-declared envelope in 2.3.

**Recommendation: stay on Tailwind 3, port the components as they are.** (`package.json` declares
`tailwindcss ^3.2.1`; the installed resolution is **3.4.19**, so 3.4 utilities such as `size-*` and
`has-*` are available even though nothing in scope needs them. PrimeVue is declared `^3.52.0`,
installed 3.53.1.) The number this rests on is **1 of 21**: exactly one portable file needs rework to
run here, `ActionButton.vue`, and the rework is swapping a dialog import for PrimeVue's `Dialog`,
about 15 lines. Against that, Tailwind 4 puts 26 701 lines of live PrimeVue preset back on the table
during a POS rewrite. Add the 37 `mg-surface-*` renames from 1.3 and the port is done.

Tailwind 4 remains a good idea for this repo on its own schedule, after the POS rework lands and as
its own PR with its own visual regression pass. It is not a prerequisite for `/admin` and must not
be coupled to it.

---

## Part 2 - Architecture

### 2.1 Routing

New file `routes/manage.php`, registered from `bootstrap/app.php`, prefix `/admin`, names
`manage.*`, middleware `['web', 'auth', 'can:access-manage', ManageEventScope::class]`.

The panel takes `/admin` from the start; Filament's panel moves to `->path('admin-legacy')` and
serves the whole migration from there. The **names** stay `manage.*` for now, because `admin.*` is
still occupied by `admin.badge-pdf.view` and `admin.badge-pdf.download` in `routes/web.php`. Part 5
renames them once Filament and those two routes are gone. The group is registered after
`routes/web.php` so the `admin.badge-pdf.*` routes, which share the `/admin` prefix, keep matching
first.

```
GET    /admin                                        manage.dashboard
POST   /admin/event                                  manage.event.select
POST   /admin/uploads                                manage.uploads.store
POST   /admin/tables/{table}/columns                 manage.tables.columns

GET    /admin/settings/events                        manage.settings.events.index
GET    /admin/settings/events/create                 manage.settings.events.create
POST   /admin/settings/events                        manage.settings.events.store
GET    /admin/settings/events/{event}/edit           manage.settings.events.edit
PUT    /admin/settings/events/{event}                manage.settings.events.update
DELETE /admin/settings/events/{event}                manage.settings.events.destroy
DELETE /admin/settings/events/bulk                   manage.settings.events.bulk.destroy

GET    /admin/badges                                 manage.badges.index
GET    /admin/badges/{badge}/edit                    manage.badges.edit
PUT    /admin/badges/{badge}                         manage.badges.update
DELETE /admin/badges/{badge}                         manage.badges.destroy
POST   /admin/badges/{badge}/print                   manage.badges.print
POST   /admin/badges/bulk/print                      manage.badges.bulk.print

GET    /admin/fursuits                               manage.fursuits.index
GET    /admin/fursuits/{fursuit}                     manage.fursuits.show
GET    /admin/fursuits/{fursuit}/edit                manage.fursuits.edit
PUT    /admin/fursuits/{fursuit}                     manage.fursuits.update
DELETE /admin/fursuits/{fursuit}                     manage.fursuits.destroy
POST   /admin/fursuits/{fursuit}/claim               manage.fursuits.claim
DELETE /admin/fursuits/{fursuit}/claim               manage.fursuits.unclaim
POST   /admin/fursuits/{fursuit}/approve             manage.fursuits.approve
POST   /admin/fursuits/{fursuit}/approve-rejected    manage.fursuits.approve-rejected
POST   /admin/fursuits/{fursuit}/reject              manage.fursuits.reject
POST   /admin/fursuits/{fursuit}/notify              manage.fursuits.notify
GET    /admin/fursuits/{fursuit}/next                manage.fursuits.next

GET    /admin/special-codes                          manage.special-codes.index
GET    /admin/special-codes/create                   manage.special-codes.create
POST   /admin/special-codes                          manage.special-codes.store
GET    /admin/special-codes/{code}/edit              manage.special-codes.edit
PUT    /admin/special-codes/{code}                   manage.special-codes.update
DELETE /admin/special-codes/{code}                   manage.special-codes.destroy
DELETE /admin/special-codes/bulk                     manage.special-codes.bulk.destroy

GET    /admin/checkouts                              manage.checkouts.index
GET    /admin/checkouts/{checkout}                   manage.checkouts.show
GET    /admin/checkouts/{checkout}/receipt           manage.checkouts.receipt
POST   /admin/checkouts/{checkout}/print             manage.checkouts.print

GET    /admin/machines                               manage.machines.index
GET    /admin/machines/create                        manage.machines.create
POST   /admin/machines                               manage.machines.store
GET    /admin/machines/{machine}/edit                manage.machines.edit
PUT    /admin/machines/{machine}                     manage.machines.update
POST   /admin/machines/{machine}/archive             manage.machines.archive
DELETE /admin/machines/{machine}/archive             manage.machines.unarchive
POST   /admin/machines/bulk/archive                  manage.machines.bulk.archive
DELETE /admin/machines/bulk/archive                  manage.machines.bulk.unarchive
POST   /admin/machines/{machine}/login-link          manage.machines.login-link

GET    /admin/printers                               manage.printers.index
GET    /admin/printers/create                        manage.printers.create
POST   /admin/printers                               manage.printers.store
GET    /admin/printers/{printer}/edit                manage.printers.edit
PUT    /admin/printers/{printer}                     manage.printers.update
DELETE /admin/printers/{printer}                     manage.printers.destroy
POST   /admin/printers/{printer}/active              manage.printers.active
POST   /admin/printers/{printer}/clear-error         manage.printers.clear-error
DELETE /admin/printers/bulk                          manage.printers.bulk.destroy

GET    /admin/print-jobs                             manage.print-jobs.index
GET    /admin/print-jobs/{job}                       manage.print-jobs.show
GET    /admin/print-jobs/{job}/edit                  manage.print-jobs.edit
PUT    /admin/print-jobs/{job}                       manage.print-jobs.update
POST   /admin/print-jobs/{job}/retry                 manage.print-jobs.retry
DELETE /admin/print-jobs/{job}                       manage.print-jobs.destroy
DELETE /admin/print-jobs/bulk                        manage.print-jobs.bulk.destroy

GET    /admin/print-batches                          manage.print-batches.index
GET    /admin/print-batches/{batch}                  manage.print-batches.show
POST   /admin/print-batches/{batch}/pause            manage.print-batches.pause
POST   /admin/print-batches/{batch}/resume           manage.print-batches.resume
POST   /admin/print-batches/{batch}/cancel           manage.print-batches.cancel
POST   /admin/print-batches/{batch}/jobs/{job}/verify manage.print-batches.jobs.verify

GET    /admin/staff                                  manage.staff.index
GET    /admin/staff/create                           manage.staff.create
POST   /admin/staff                                  manage.staff.store
GET    /admin/staff/{staff}/edit                     manage.staff.edit
PUT    /admin/staff/{staff}                          manage.staff.update
DELETE /admin/staff/{staff}                          manage.staff.destroy
DELETE /admin/staff/bulk                             manage.staff.bulk.destroy
POST   /admin/staff/{staff}/setup-code               manage.staff.setup-code
POST   /admin/staff/{staff}/rfid-tags                manage.staff.rfid-tags.store
PUT    /admin/staff/{staff}/rfid-tags/{tag}          manage.staff.rfid-tags.update
DELETE /admin/staff/{staff}/rfid-tags/{tag}          manage.staff.rfid-tags.destroy
DELETE /admin/staff/{staff}/rfid-tags/bulk           manage.staff.rfid-tags.bulk.destroy

GET    /admin/sumup-readers                          manage.sumup-readers.index
GET    /admin/sumup-readers/create                   manage.sumup-readers.create
POST   /admin/sumup-readers                          manage.sumup-readers.store
GET    /admin/sumup-readers/{reader}/edit            manage.sumup-readers.edit
PUT    /admin/sumup-readers/{reader}                 manage.sumup-readers.update
POST   /admin/sumup-readers/{reader}/reveal          manage.sumup-readers.reveal
DELETE /admin/sumup-readers/{reader}                 manage.sumup-readers.destroy
DELETE /admin/sumup-readers/bulk                     manage.sumup-readers.bulk.destroy

GET    /admin/tse-clients                            manage.tse-clients.index
GET    /admin/tse-clients/{client}/edit              manage.tse-clients.edit
PUT    /admin/tse-clients/{client}                   manage.tse-clients.update

GET    /admin/users                                  manage.users.index
GET    /admin/users/create                           manage.users.create
POST   /admin/users                                  manage.users.store
GET    /admin/users/{user}/edit                      manage.users.edit
PUT    /admin/users/{user}                           manage.users.update
DELETE /admin/users/{user}                           manage.users.destroy
DELETE /admin/users/bulk                             manage.users.bulk.destroy

GET    /admin/tools/pdf                              manage.tools.pdf
POST   /admin/tools/pdf/badge-list                   manage.tools.pdf.badge-list
POST   /admin/tools/pdf/box-labels                   manage.tools.pdf.box-labels
GET    /admin/tools/badge-preview                    manage.tools.badge-preview
POST   /admin/tools/badge-preview                    manage.tools.badge-preview.lookup
GET    /admin/tools/badge-preview/{customId}/pdf     manage.tools.badge-preview.pdf.view
GET    /admin/tools/badge-preview/{customId}/pdf/download  manage.tools.badge-preview.pdf.download

GET    /admin/maintenance/db-service                 manage.maintenance.db-service
POST   /admin/maintenance/db-service/preview         manage.maintenance.db-service.preview
POST   /admin/maintenance/db-service/apply           manage.maintenance.db-service.apply
```

Every mutation is a POST/PUT/DELETE that redirects back with a flash. No JSON endpoints and no
`fetch()`: data reaches the client through Inertia props. Uploads use Inertia's multipart support and
still return a redirect.

Two routes that exist today and change guard rather than shape: `admin.badge-pdf.view` and
`admin.badge-pdf.download` in `routes/web.php:42-49` are behind `auth` only, so any logged-in
attendee can pull any badge PDF by custom id (audit landmine 60). Both move under `/admin/tools` and
behind `can:access-manage`, and both survive as separate routes: `BadgePreview::viewPdf()` and
`downloadPdf()` are two distinct actions (audit 5.2), inline versus attachment, and collapsing them
into one endpoint loses the download.

### 2.2 Authorization

Today the entire authorization model is two boolean columns. `User::canAccessPanel()` returns
`is_admin || is_reviewer` and that is the only panel-level gate. Per-resource, Filament treats a
model with no policy as allowed, which is why `CheckoutResource`, `PrintBatchResource` and
`SpecialCodeResource` are wide open to reviewers: an `is_reviewer`-only account can pause, resume
and cancel a live print run, and can create Catch-Em-All codes that award points, while needing
`is_admin` merely to look at a printer (audit landmine 51).

The rebuild introduces:

**Gates** in `AuthServiceProvider`:

- `access-manage` = `$user->is_admin || $user->is_reviewer`. Direct successor to `canAccessPanel()`.
- `manage-admin` = `$user->is_admin`. The successor to `DbService::canAccess()`, reused wherever
  admin-only is meant.

**New policies**, all registered explicitly in `AuthServiceProvider` because three of the models live
under `App\Domain\**` where Laravel auto-discovery looks in directories that do not exist:

| Policy | Model | Rule |
|---|---|---|
| `CheckoutPolicy` | `App\Domain\Checkout\Models\Checkout\Checkout` | `viewAny`/`view` = `access-manage`; `printReceipt` = `is_admin`; `create`/`update`/`delete` false, matching today's hard `canCreate/canEdit/canDelete => false` |
| `PrintBatchPolicy` | `App\Domain\Printing\Models\PrintBatch` | `viewAny`/`view` = `access-manage`; `pause`/`resume`/`cancel`/`verify` = `is_admin`. This is the biggest authorization change in the rebuild and it is deliberate |
| `SpecialCodePolicy` | `App\Domain\CatchEmAll\Models\SpecialCode` | every ability = `is_admin` |
| `RfidTagPolicy` | `App\Models\RfidTag` | every ability = `is_admin`; today it is protected only by living inside an admin-only page |
| `ActivityPolicy` | `Spatie\Activitylog\Models\Activity` | `viewAny`/`view` = `access-manage`; `create`/`update`/`delete` **false**. See change 12 |

**Amended policies:**

- `BadgePolicy::update` currently contains `request()->routeIs('filament.*', 'livewire.*')`. Moving
  the admin off Filament route names silently flips every admin from "can edit any badge" to "owner
  rules only" (audit landmine 52). The route check is replaced by
  `$user->is_admin || $user->can('access-manage')`, evaluated without touching the request.
- `EventPolicy` is **not** given `restore` and `forceDelete`. Landmine 53 is a Filament-specific hole:
  `App\Models\Event` has no `SoftDeletes` (audit 7.7 - hard delete from a default-copy confirm modal
  and via bulk delete), so the two abilities describe operations that cannot happen, and no restore or
  force-delete route is registered for events. The hole closes because Filament, which called those
  methods, is gone. Adding the methods would be dead code.
- `FursuitPolicy::create()` stays `false`. It is not a bug to fix during a rewrite (landmine 38);
  the create route simply is not registered.

**No `/admin/login`.** Guests are redirected into the existing Identity SSO flow at
`/auth/login`, which is what the Filament panel already does since it declares no `->login()`.
A signed-in user without the gate gets 403.

`HandleInertiaRequests` gains `auth.can_access_manage` and `auth.is_admin` for `manage.*` routes so
the sidebar can hide what the user cannot reach, mirroring what Filament's policies do to the nav
today.

### 2.3 List pages: one prop contract

Every index page ships the same envelope, produced by `App\Support\Manage\Table` from a query plus a
column and filter declaration:

```php
[
  'rows'    => [...],                  // already formatted for display
  'columns' => [
      ['key' => 'status_fulfillment', 'label' => 'Fulfillment', 'type' => 'badge',
       'sortable' => false, 'toggleable' => false, 'hiddenByDefault' => false],
      ['key' => 'total', 'label' => 'Total', 'type' => 'money',
       'align' => 'right', 'sortable' => false, 'toggleable' => true,
       'hiddenByDefault' => true],
  ],
  'filters' => [
      ['key' => 'status', 'type' => 'select', 'label' => 'Status',
       'options' => [...], 'multiple' => false, 'value' => 'pending', 'default' => 'pending'],
  ],
  'sort'    => ['key' => 'sort_attendee_id', 'dir' => 'asc'],
  'search'  => '',
  'meta'    => ['page' => 1, 'perPage' => 25, 'total' => 1284],
  'rowActions'  => [...],   // per row, already visibility-filtered and policy-filtered
  'bulkActions' => [...],
  'pageActions' => [...],
]
```

Column `type` values: `text`, `number`, `money`, `badge`, `image`, `bool`, `icon`, `datetime`,
`copyable`, `toggle`, `color`. All except `money` already exist in `DataTable.vue`.

Things that are easy to lose in a rewrite and must be explicit in the declaration, each with a test
in Part 4:

- `FursuitResource`'s status filter **defaults to `pending`**. The fursuit list has never shown the
  full set on first load and losing that reads as missing data.
- `MachineResource`'s `archived` ternary defaults to blank = `notArchived()`. Nothing scopes archived
  machines at query level, so dropping the filter silently exposes them (landmine 43).
- `PrintJobResource`'s `?printer=` is a resource-wide `getEloquentQuery()` scope, not a table filter,
  and it changes the page title too. It becomes an ordinary filter with an indicator.
- 23 columns are `toggleable()` today and **17 of them are
  `toggleable(isToggledHiddenByDefault: true)`**: `BadgeResource` 5, `EventResource` 3,
  `PrintBatchResource` 3, `UserResource` 2, `StaffResource` 2, `PrintJobsRelationManager` 2. All 17
  flags are transcribed into the declaration and the user's choice persists in the session keyed by
  table name, through `POST /admin/tables/{table}/columns`.
- `BadgeResource` uses `->selectCurrentPageOnly()` so bulk print can never cross a page. That is a
  deliberate operational cap, not an accident, and it is kept.
- `->paginated(false)` on Printers, Machines, Staff and checkout items becomes `perPage: 200` with
  pagination visible, so an unbounded staff table cannot become an unbounded page render.

Search: `Table::applySearch` searches exactly the fields Filament marked `searchable()`, per module,
transcribed from the audit. Two resources call `->searchable(false)` at table level today
(Printers, Machines), which makes their columns' own `searchable()` unreachable; those get a working
search box, since hiding it was not a decision anyone recorded.

### 2.4 Polling

Inertia's `usePoll` replaces `->poll()`. The current panel polls three tables and every dashboard
widget at 5s (audit 7.1):

```js
usePoll(5000,  { only: ['rows', 'meta'] })   // Badges index      (parity: 5s)
usePoll(5000,  { only: ['rows', 'meta'] })   // Print jobs index   (parity: 5s)
usePoll(10000, { only: ['rows', 'meta'] })   // Print batches index (parity: 10s)
usePoll(10000, { only: ['rows', 'meta'] })   // Batch detail card list  (NEW, see change 22)
usePoll(15000, { only: ['rows', 'meta'] })   // Printers index          (NEW, see change 22)
usePoll(15000, { only: ['stats'] })          // dashboard widgets  (was 5s, see change 23)
usePoll(15000, { only: ['strip'] })          // top strip counts
```

Rules: only ever reload the data props, never `columns`, `filters` or `rowActions`; pause while a
form is dirty or a dialog is open; pause when the tab is hidden. This app already runs Reverb and
`laravel-echo`, and the POS subscribes to printer state; where a channel already exists, subscribe
and trigger one reload instead of adding a second interval.

### 2.5 Actions, confirms and toasts

Actions are modelled server-side so the client stays dumb and the tests can assert visibility:

```php
Action::post('pause', 'Pause', route('manage.print-batches.pause', $batch))
    ->icon('pause')
    ->tone('warn')
    ->fields([
        ['key' => 'reason', 'type' => 'text', 'label' => 'Why is it being paused?',
         'required' => true, 'maxLength' => 1000,
         'help' => 'Shown to whoever is standing at the printer.'],
    ])
    ->confirm('Pause', null, 'Confirm');
```

Every modal heading, description and submit label in the audit is reproduced verbatim, including
Filament's own defaults where a resource never overrode them. That means, for a delete:
heading `Delete :label`, description `Are you sure you would like to do this?`, submit `Delete`,
cancel `Cancel`; for a bare `requiresConfirmation()`: heading = the action label, description
`Are you sure you would like to do this?`, submit `Confirm`. The specific overridden copy that must
survive word for word:

- `Archive Machine` / `Are you sure you want to archive this machine? It will be hidden from normal view.` / `Yes, archive it`
- `Restore Machine` / `Are you sure you want to restore this machine? It will be visible again.` / `Yes, restore it`
- `Archive Machines` / `... They will be hidden from normal view and unable to log in to the POS system.` / `Yes, archive them`
- `Restore Machines` / `... They will be visible again and able to log in to the POS system.` / `Yes, restore them`
- `Cancel this batch` / `Cards already printed stay printed. Everything still queued is cancelled, and attendees whose card never printed get their badge back to edit.`
- `Confirm this card` / `Only do this with the printed card in front of you. This records that a human checked it.`
- Resume, no heading override, description `Only resume once the fault at the printer has actually been dealt with.`
- `Print Receipt` / `This will add the receipt to the print queue.`
- `Print Selected Badges` / `This will print all selected badges to the specified printer.`
- `Approve Rejected Fursuit` / `This will send an apology email to the user and approve the fursuit.` / `Yes, approve it`

`disabledReason` carries a tooltip for an action that is visible but not available, which the current
panel has no concept of: it hides actions instead. Used for the batch controls and for badge print
when the badge has no printable state.

Toasts ride Inertia's flash bag through `App\Support\Manage\Toast`, which flashes forward into the
session because every mutation redirects. All 22 notification title/body pairs in audit 7.2 are
reproduced verbatim, and the surfaces that are silent today are listed as deliberate additions in
change 24 rather than smuggled in as parity.

Bulk actions POST an `ids[]` array. Guarded bulk operations are all-or-nothing: if any selected
record fails the policy, nothing is changed and a danger toast says why.

### 2.6 Forms

Server-declared sections, client-rendered fields, validation through Form Requests so the same rules
serve both panels during the parallel phase.

Per module the audit gives the exact field list, labels, helper text, defaults and disabled state;
those are transcribed. The rules that carry real logic:

- **Badges.** `fursuit_id`, `custom_id`, `species_name`, `owner_name`, `subtotal`, `tax`,
  `is_free_badge`, `extra_copy`, `dual_side_print`, `apply_late_fee` and the three timestamps stay
  read-only. `total` becomes read-only too (change 3). `status_fulfillment` and `status_payment`
  become transition pickers, not free selects (change 8).
- **Fursuits.** `status` stops being a free-text `TextInput` and becomes a transition picker
  (change 9). `event_id` stops being a numeric `TextInput` and becomes a relation select.
  `image` uploads to **s3**, not the default disk (change 5).
- **Events.** Same seven date fields at the same granularity, `mass_printed_at` still required.
  `cost` stays in euros, not cents, and is labelled as such.
- **Special codes.** `code` keeps `size:5`, `unique:special_codes,code` and the custom rule whose
  failure message is `This code is already used in Fursuits.`. `class_name` becomes `live` so
  `constructor_data` and `catch_url` re-evaluate (change 15).
- **Staff.** `pin_code` validation passes the record id into `SecurePinRule` so saving an unchanged
  staff row stops failing (change 16). Blank `setup_code` dehydrates to `null`, not `''`, so the
  unique index stops colliding (change 17). The Generate button no longer writes to the database
  before save (change 18).
- **Users.** `valid_registration` is removed from the form and the table. The column does not exist
  on `users` any more and saving the form throws SQL 1054 today (change 4).
- **Print jobs.** `status` becomes a transition picker (change 10).
- **TSE clients.** `remote_id`, `serial_number` and `state` become read-only, and the local
  `createnew` fabricator is removed (changes 13 and 14).

Timezone: unchanged. There is no `->timezone()` anywhere in `app/Filament` and everything renders in
the app timezone; the rebuild formats on the server and sends both an ISO string and a preformatted
display string.

### 2.7 Uploads to private S3

`POST /admin/uploads` accepts one file plus a `purpose`. Purpose determines disk, directory,
visibility, accepted mime types and max size. Purposes in phase 1: `fursuit_image` only. The disk is
**`s3`, visibility private**, matching every read site in the panel.

This is a fix, not a port. `FursuitResource`'s `FileUpload::make('image')` has **no `->disk()` call**,
so it writes to the default filesystem disk while the table column, the infolist entry and the
`DbService` review table all read from `s3` (audit 7.4). `config/filesystems.php` currently defaults
to `s3`, so the two happen to coincide today, and `config/filament.php` separately sets
`default_filesystem_disk` from `FILAMENT_FILESYSTEM_DISK` with fallback `public`. Check the
deployment env for that variable before deleting `config/filament.php`.

Reads go through `Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15))`, the same
mechanism `FreeBadgeRepairService::imageUrl()` already uses, with `asset('images/placeholder.png')`
as the fallback. That file does not exist in `public/images/` today and every broken-image
placeholder in the panel points at it (landmine 50); the rebuild ships the file.

### 2.8 Navigation and badge counts

One `App\Support\Manage\Navigation` service returns the rail structure with counts, computed in one
place and shared as a prop on every `/admin` response, policy-filtered so a reviewer does not see
groups they cannot open.

| Group | Items | Badge |
|---|---|---|
| Events & Registration | Events, Badges, Fursuits, Special Codes | Badges: badge count for the selected event. Fursuits: **pending** count for the selected event, tone `warn` when > 0 |
| Sales | Checkouts | - |
| POS | Machines, Staff, SumUp Readers, TSE Clients | - |
| Printing | Printers, Print Jobs, Print Batches | Printers: count requiring attention (`PrinterConditionEnum::isStop()`). Print Batches: batches with a printed-but-unverified job |
| User Management | Users | - |
| Tools | PDF Generator, Badge Preview | - |
| Maintenance | DB Service | admin only |

Two count changes are deliberate. The fursuit chip today shows the **total** fursuit count coloured
by the **pending** count, two different numbers behind one chip (audit 4.3); it becomes the pending
count, which is the number a reviewer acts on. The badge chip stays a total. The print-batch chip
keeps counting batches, matching the code rather than its docblock, which claims cards.

Badge queries are cheap counts, cached 5s, since the strip polls.

### 2.9 The global event filter

This is the cross-cutting concern with no ef-streaming equivalent and the part of the rebuild most
likely to be got wrong by porting it faithfully.

**What exists today** (audit 2.1 and 7.3): `App\Http\Middleware\FilamentEventSelector`, 32 lines,
registered only inside the panel's `->middleware([])` array, so it stops running the moment the panel
goes. It writes `session('filament_selected_event_id')` on every `/admin-legacy` request. A blade select in
the topbar navigates the whole page with `?selected_event_id=`. `App\Filament\Traits\HasEventFilter`
reads it. Two resources and three widgets are scoped by it. `App\Filament\Components\EventSelector`
renders the same view and is referenced by nothing.

**The bug.** `handle()` forgets the key when the request carries `selected_event_id=all`, and then,
in the same call, unconditionally re-seeds it with the newest event when the key is missing. So:

- The "all events" option cannot work. It is also not rendered in the blade, so it cannot be reached.
- `applyEventFilter()`'s "no id, return unfiltered" branch is dead.
- Every `getNavigationBadge()` guard that returns `null` on a falsy id is dead.
- `getSelectedEventId(): ?int` returns whatever the query string put in the session, unvalidated. A
  non-numeric value would `TypeError` on return.
- `PdfGenerator` reads a **different key**, `filament.admin.selected_event_id`, that nothing writes,
  so the PDF Generator has never respected the header selection and always uses the newest event.
  Its `No event selected in the header.` notification is unreachable.

**What replaces it.**

`App\Support\Manage\EventScope`, a request-scoped service, plus
`App\Http\Middleware\ManageEventScope` on the `/admin` group:

```php
// session keys
'manage.event_id'      // int|null. null means "all events".
'manage.event_chosen'  // bool. true once the operator has made an explicit choice.
```

- The middleware seeds a default **only when `manage.event_chosen` is absent**. That single flag is
  the whole fix: forgetting the id and "having chosen all events" are now different states, so the
  forget-then-reseed collision cannot happen.
- The selection is written by `POST /admin/event` with `event_id` validated
  `nullable|integer|exists:events,id`, not by a query-string side effect on an arbitrary GET. An
  unknown id is a validation error, not a poisoned session.
- `EventScope::id()` returns `?int`, `EventScope::event()` returns `?Event`, and
  `EventScope::apply(Builder $query, ?string $relationship = null)` is the direct successor to
  `applyEventFilter()`, with its "no id" branch now **reachable and meaningful**: it returns the
  query unscoped, which is what "all events" means.
- The current selection is a **shared Inertia prop** on every `/admin` response:
  `['id' => …, 'name' => …, 'year' => …, 'orders_open' => bool, 'options' => [{id, name, year, orders_open}]]`.
  The selector renders from the prop, so the "orders open" marker is visible for **every** option,
  not only the already-selected one (landmine 68).
- There is exactly **one** reader. `PdfGenerator`'s private `getSelectedEvent()` and its wrong key
  are deleted; the tools page takes the scope from the same service as everything else.

**Where the scope applies.** Today it is applied inconsistently and nobody wrote down why
(landmine 66). The declared list:

| Surface | Scoped |
|---|---|
| Badges list | yes, through `fursuit.event_id`, as today |
| Fursuits list | yes, as today |
| Fursuit moderation queue (`next`, and the auto-advance after approve/reject) | **yes. New.** Today the queue is `Fursuit::where('status','pending')->first()`, unordered and unscoped, so it hands a reviewer fursuits from past events |
| Special Codes list | **yes. New.** Every row has an `event_id`; nothing filtered on it |
| Dashboard widgets | yes, as today |
| PDF Generator | **yes. New.** It was never scoped because of the key mismatch |
| DB Service | no. It operates on `Event::getActiveEvent()` and says so on screen |
| Checkouts, Machines, Printers, Print Jobs, Print Batches, Staff, SumUp Readers, TSE Clients, Users, Events | no, as today |

Scope stays in the session rather than the URL, matching today's behaviour, which means admin links
are still not deep-linkable across a scope change. That is a known limitation and it is recorded
here rather than silently inherited; moving it into the URL is a follow-up, not part of parity.

### 2.10 Deliberate behaviour changes

Not parity gaps. Decisions. Each needs a line in the cutover PR description. Numbers in brackets
refer to the audit's landmine table.

**Money.**

1. **One money renderer, cents in, euros out.** The badge `Total` column has no `divideBy: 100`
   today, so every badge total renders 100x too high; it is the only one of six `->money(` sites
   without it [1]. The `Financial Details` section of the checkout view renders `subtotal`, `tax`
   and `total` as raw cents while the same resource's table column divides by 100, so one fiscal
   record is shown two contradictory ways on one screen [2]. Both are fixed by
   `Column::money()` plus a single server-side `Money::euros(int $cents)` helper. No call site
   chooses.
2. **`Column::money()` and the form field share one formatter,** so the read format and the write
   format cannot disagree.
3. **The badge `total` form field becomes read-only.** It renders `number_format($state/100, 2)` on
   read with no inverse on write, so saving an unchanged badge edit form writes `"3.00"` into a cents
   column and silently turns a 300-cent badge into a 3-cent badge [3]. An editable total is not worth
   the trap; badge totals are set by the checkout pipeline. A data check for already-corrupted totals
   ships with phase 4 as a read-only report on the DB Service page. **Corrected while building
   phase 4:** the DB Service page is phase 9, so "phase 4" and "on the DB Service page" cannot both
   hold. Phase 4 wins, because the report exists to make the read-only `total` field safe to ship
   and shipping it later leaves the corrupted rows unfindable. It landed as its own read-only page,
   `GET /admin/badges/corrupted-totals`, reachable from a `Total check` page action on the badge
   list that only `manage-admin` was offered, and linked again from DB Service in phase 9.
   **Removed after the fact:** the report, its route, its page action and the DB Service link are
   gone. The read-only `total` field remains, so no write path can damage a money column; the
   report was a one-off cleanup aid for rows the old Filament form had already damaged and is no
   longer carried in the panel.

**Broken surfaces that are not ported as working features.**

4. **The `valid_registration` toggle and column are removed from Users.** The column was dropped from
   `users` by `2025_08_03_195303_remove_old_columns_from_users_table` and moved to `event_users`.
   Saving the user form throws SQL 1054 today, so Create and Edit are both broken [26].
5. **Fursuit image upload targets `s3`.** Today the upload has no `->disk()` while every read site
   reads `s3` (audit 7.4).
6. **The badge Create page is not ported.** `fursuit_id` is disabled, disabled fields do not
   dehydrate, and `badges.fursuit_id` is `NOT NULL` with an FK, so creating a badge from admin has
   always thrown an integrity error [25]. Badges come from the ordering flow.
7. **Null-safe status rendering everywhere.** Four separate closures 500 the whole table on a null
   value today: `$record->status->value` on printers with no null-safe operator [28], the `int $state`
   type hints on print-job `priority` and `retry_count` [29], and the `string $state` hints on
   special-code `class_name` [30] and `event_id` [31]. Server-side formatting with an explicit
   fallback replaces all four.

**State machines.**

8. **Badge status becomes a transition, not a write.** The two selects write raw state strings
   through the default save, skipping `custom_id` allocation, `printed_at` / `ready_for_pickup_at` /
   `picked_up_at` stamping, notifications and log semantics, and can put a badge in a state the
   machine would reject [20]. The rebuild offers only the transitions
   `BadgeFulfillmentStatusState::config()` allows from the current state, including the POS error
   correction `PickedUp -> ReadyForPickup`, and calls `transitionTo()`.
9. **Fursuit status becomes a transition, not a text input.** Today it is a `TextInput` with
   `maxLength(255)` writing straight through the cast, so no `PendingToApproved` /
   `PendingToRejected` runs, no `approved_at` / `rejected_at` bookkeeping happens, no activity entry
   is written and no user is notified [21]. `approved_at` and `rejected_at` stop being hand-editable
   and can no longer contradict `status`.
10. **Print job status becomes a transition.** The edit form bypasses `PrintJob::transitionTo()`, so
    setting a job to Printed from admin leaves the badge stuck in `Processing` and the batch counters
    wrong [22].
11. **Deleting print jobs recalculates the batch counters.** `DeleteBulkAction` desyncs
    `total_jobs` / `printed_count` / `verified_count` / `failed_count` permanently today, and every
    progress badge in the printing slice reads those counters [23].

**Audit trail and authorization.**

12. **The fursuit activity log becomes read-only.** Today create, edit, delete and bulk delete are
    all enabled on `ActivitiesRelationManager`, `causer` is not set on manual creates, and a form
    round-trip double-encodes `properties` into a collection-cast column [56]. An audit trail the
    audited party can edit is not an audit trail. It becomes a read-only list.
13. **`createnew` is removed from TSE Clients.** It fabricates a client locally with a random UUID as
    both `remote_id` and `serial_number` and `state` hardcoded `REGISTERED`, never talking to
    Fiskaly, with no confirmation, notification or audit entry [7]. Any checkout later signed against
    it inherits a serial that does not exist upstream. The real lifecycle is `tse:update-state` and
    `tse:change-admin-pin`.
14. **TSE `remote_id`, `serial_number` and `state` become read-only.** Editing them silently rewrites
    the identity of the security module past checkouts were signed under, and German KassenSichV
    requires that serial to stay traceable [8].
15. **The machine login link is issued on demand and expires.** `URL::signedRoute` with no expiry,
    rendered as copyable plaintext, is the most sensitive thing in the panel [9]. It becomes
    `POST /admin/machines/{machine}/login-link` returning a `temporarySignedRoute` valid for 15
    minutes, gated on `is_admin`, with an activity-log entry naming who minted it.
16. **The SumUp `paring_code` is masked by default.** It is a pairing secret rendered as a plain
    column and a plain text input today [10]. It becomes a `reveal` action gated on `is_admin` and
    logged. The column-name typo stays, since it is baked into the migration and POS code paths.
17. **SumUp `remote_id` becomes genuinely non-writable.** `readOnly()` is a client-side guard; the
    field round-trips through the request and `$guarded = []` on the model, so a crafted POST rewrites
    the reader binding [12]. It is dropped from the request payload entirely.
18. **Print batch controls require `is_admin`.** Reviewers can pause, resume and cancel a live print
    run today purely because no `PrintBatchPolicy` exists [51]. Reading the list stays open to every
    `access-manage` holder.
19. **Checkouts and special codes get policies.** Same reason [51].
20. **`admin.badge-pdf.*` moves behind `can:access-manage`.** Any logged-in attendee can pull any
    badge PDF by custom id today [60].

**Staff and PIN handling.**

21. **`SecurePinRule` receives the record id.** Opening an existing staff member and pressing Save
    without changing anything fails validation today with
    `This PIN is not secure enough. Please choose a different PIN.`, because the uniqueness check
    includes the record being edited [34]. This makes staff editing impossible once a PIN is set.
22. **Blank setup codes store `null`, not `''`.** `strtoupper($state ?? '')` turns null into an empty
    string, and `staff.setup_code` carries a UNIQUE index, so the second staff member saved without a
    code blows up with SQL 1062 [35].
23. **The Generate button no longer writes before save.** On edit it calls
    `$record->generateSetupCode()`, which does `$this->update([...])` immediately, so generating and
    then navigating away leaves a code in the database the operator never committed and destroys the
    previous one [36]. The new code is generated in the form state and persisted on save.
24. **Staff PIN handling stays plaintext and is documented as such.** The admin table masks
    `pin_code` to `Set` / `Not Set`, which reads as if it were hashed; it is not, and POS login does
    a plaintext comparison [11]. Changing that is a POS change, not an admin change, and is out of
    scope here. The `/admin` field says so in its helper text so nobody assumes otherwise.

**Printing and operations.**

25. **`printBadgeWithPrinter()` is deleted, not ported.** Zero callers repo-wide, and it diverges
    from the batch pipeline: no `PrintBatch`, no frozen ordering, no printing lock, PDFs written to
    the default disk instead of s3 [41]. Porting it creates a second, incompatible print path.
    `printBadge()` keeps its single caller and loses its unused `$mass` parameter.
26. **Printers and the batch card list poll.** The one screen that tells you the hardware is jammed
    has no poll today, and staff watching a live run see a frozen card list until they reload
    (audit 7.1). Printers at 15s, batch detail at 10s.
27. **The printer condition columns are surfaced.** `condition`, `condition_message`,
    `cards_remaining`, `cards_capacity` and `condition_reported_at` have existed since
    `2026_08_05_100300` and appear nowhere in admin, and `PrinterConditionEnum::remedy()` strings
    are shown only in the POS. They become a column and a detail panel, and a `clear-error` action
    is added on top of the existing `Printer::clearPrinterError()`, which the panel never called.
28. **Dashboard widgets drop to a 15s poll.** All three inherit 5s today and an open dashboard
    re-runs four counts and a `GROUP BY` over the whole badges table every 5 seconds per tab
    (audit 7.1).
29. **The dashboard's raw-string status queries become state queries.** `StatsOverview` counts
    pending fursuits with `where('status', 'pending')` against a Spatie state column; the rebuild
    uses `whereState`, matching the rest of the app.

**Copy, portability, and dead surfaces.**

30. **`CAST(x AS UNSIGNED)` is replaced.** It appears three times (the `sort_attendee_id` sort and
    both halves of the attendee-range filter) and is MySQL-only, while `.env.example` defaults to
    SQLite, so badge sorting and range filtering break on the default dev database and any test that
    touches them fails [16]. Replaced by a portable expression, and the sort direction stops being
    string-interpolated into `orderByRaw` [17].
31. **Both PDF filenames are slugged.** The badge-list filename interpolates
    `$selectedEvent->name` straight into a `Content-Disposition` header while the box-label variant
    uses `Str::slug()` [15].
32. **Box labels stop claiming three per page.** The option label, the page copy and a code comment
    all say three; the code renders exactly one on a 210x94mm page, and the blade separately
    hardcodes 84x200mm [45]. The copy is corrected to one label per page and the geometry moves to
    one source of truth. The `pdf-generator.blade.php` copy claiming "all free badges" and "3
    columns" is likewise corrected to match the 12-column default [46].
33. **Badges outside every configured range are reported, not silently dropped.** The default
    `0-999,…,4000-4999` silently omits anything numbered 5000 or above, and the "no badges" warning
    only fires when every range is empty [47].
34. **The badge preview default badge class is unified on `EF30_Badge`.** The blade says
    `?? 'EF28_Badge'` while `BadgePdfController` defaults to `EF30_Badge`, so the preview can label a
    badge EF28 and hand you an EF30 PDF [48]. Opening the PDF also actually opens a new tab, which
    `target="_blank"` on a Livewire redirect does not do today [49].
35. **The checkout status filter is rebuilt on state names.** Its options are keyed by FQCN while the
    column stores Spatie `$name` strings, so it matches zero rows and looks like it works [6].
36. **The receipt link stops pointing into the POS route group.** `route('pos.checkout.receipt')` is
    behind `pos-auth:machine` plus `pos-auth:machine-user`, so an admin without an active POS session
    is bounced rather than shown the receipt [13]. `/admin/checkouts/{checkout}/receipt` serves it
    under the manage guard.
37. **Receipt printing is queued, not `dispatchSync`.** mPDF renders inside the web request today, so
    an mPDF or Fiskaly failure is a 500 rather than a toast, and the action can be fired repeatedly
    to spam duplicate receipts for one fiscal record with no log entry [14]. It becomes a queued job,
    idempotent per checkout, with an activity entry. `'type' => 'receipt'` becomes
    `PrintJobTypeEnum::Receipt` [4], and the duplicated action body, byte-identical across
    `CheckoutResource` and `ViewCheckout`, becomes one controller method.
38. **`tse_signature` is replaced by the columns that exist.** The field reads a column that the
    migration never created, so it is permanently blank [5]. The view shows
    `tse_start_signature` and `tse_end_signature`, plus `tse_serial_number` and
    `tse_transaction_number`, which are fiscally load-bearing and invisible today.
39. **Special-code `constructor_data` becomes editable.** Its `disabled()` matcher compares against
    the literal `'EXAMPLE'`, which is not one of the options, so the only configurable knob for the
    action class can never be edited [32], and `class_name` is not `live()` so nothing re-evaluates
    while the modal is open [33]. The `catch_url` preview updates as you type.
40. **The fursuit reject reasons become a keyed list.** They are a PHP list today, so the persisted
    select value is an integer index `0`-`7`, clearing the select throws "Undefined array key", and
    reordering the array silently rewires the prefill [37]. Keyed slugs, one place.
41. **`ViewFursuit` no longer auto-claims on page load.** `public $defaultAction = 'Claim'` means
    Filament mounts the Claim action on every page load, so merely opening a pending fursuit claims
    it without any gesture (audit 4.3.1). Claiming becomes explicit. `unclaim()` also gains the
    ownership check its zero-parameter signature quietly skips.
42. **The fursuit next-record walk is deterministic.** `toNextFursuit()` tries three times and then
    redirects to the last candidate anyway, which may still be claimed (audit 4.3.1). The queue
    becomes an ordered, event-scoped query that skips claimed records, with an explicit empty state.
43. **Approve and Reject stop failing silently.** Both log an error and return with no operator
    feedback when the record is not claimed (audit 4.3.1). They flash a danger toast.
44. **Dead code is not ported:** `App\Filament\Components\EventSelector`,
    `resources/views/pdfs/badge-list.blade.php` (165 lines), the `RejectedToPending` transition class
    that is never wired into `config()` [42], `MachineResource::getEloquentQuery()`'s no-op override
    and `Machine::withArchived()`'s no-op scope [43], the framework
    `Filament\Widgets\StatsOverviewWidget` registration that renders an empty strip [44], and the
    unregistered `Filament\Pages\Dashboard` widget layout.
45. **Silent actions get feedback.** `BadgeResource`'s print and bulk print, `MachineResource`'s
    archive, restore and login link, and `TseClientResource`'s create give no feedback at all today
    (audit 7.2). They get toasts. This is new behaviour, listed here so it is not mistaken for
    parity.
46. **Filament's global search is not replaced.** No resource declares `$recordTitleAttribute`, so
    global search returns nothing anywhere in the panel today (audit 7.9). Per-table search is what
    exists and what is ported. A cross-module rail search is a follow-up.
47. **`is_reviewer` gets a `bool` cast** on `User`. It is uncast today while `is_admin` is cast, which
    is a trap for any `=== true` check [58].

**Write paths the Filament forms left open.** Found while building phase 1. Each is a rule the old
form did not have, on a column the database or a consumer already required.

48. **Users validates `remote_id` and `email` for uniqueness.** Both carry a UNIQUE index from
    `0001_01_01_000000_create_users_table` and the Filament form validated neither, so a duplicate
    surfaced as SQL 1062 instead of a field error. Nobody ever hit it, because the form could not get
    past the missing `valid_registration` column first (change 4). Both rules ignore the record being
    edited, so saving an unchanged user is not blocked by its own values.
49. **Special-code `class_name` is validated against the options the form offers.** The Select
    declares exactly one option and validated nothing against it, while
    `SpecialCode::createActionInstance()` does `new $className(...)` on whatever is stored. `Rule::in`
    on the option keys closes the gap between what the form offers and what the redeem path
    instantiates.
50. **A special code saved with no class writes `''`, not null.** `special_codes.class_name` is
    NOT NULL (migration `2025_08_26_141448`) while the field is not required, so an unselected class
    has to write something. `''` renders as the same empty cell as the null rows already in the
    database; writing null would make saving the form a database error.
51. **Special-code `constructor_data` must be a JSON object, not merely valid JSON.** The rule was
    `nullable|json`, which accepts any JSON document, so `[1,2,3]` or `5` stored happily. The value
    reaches `AbstractSpecialCodeAction::__construct`, whose third argument is typed `?object`, and
    `GameController` catches `\Exception` only; a `TypeError` is an `\Error`, so it escapes the
    handler and 500s an attendee's scan rather than degrading to `Error processing special code`.
    The field only became editable with change 39, so this is the first time anything could write a
    non-object into it.

**Phase 1 rendering and ordering.**

52. **The special-code `Data` column renders canonical JSON.** The audit records "raw output of an
    `object`-cast column", which through Filament's string cast prints a `stdClass` as the literal
    `Object`. The column is `constructor_data` re-encoded with `json_encode`, so what the operator
    reads is the value that is actually stored. The null-safety half of this is change 7; the
    encoding is this entry.
53. **The special-code event Select is ordered `starts_at desc`.** Filament built its options from
    `Event::all()`, so they came out in insertion order. It matches the global event selector, which
    the checklist already specifies as `starts_at desc`, rather than leaving one event list in the
    panel ordered differently from every other.

**Phases 2, 3 and 4.** Found while building Events, Badge Preview, Fursuits and Badges. Same rule as
the rest of this section: each is a decision, not a parity gap, and each needs a line in the cutover
PR description.

54. **The Events form's four invisible `Group` labels become real section headings.** `Event Dates`,
    `Order Management`, `Financial Tracking` and `Gallery Settings` are declared on `Group`
    components, which render no label at all, so the schema author's grouping intent exists in the
    file and nowhere on screen (audit 108). Thirteen ungrouped fields read worse than the grouping
    somebody already wrote down, and the alternative is deleting the only structure the resource
    records. `name` and `badge_class` sit outside every `Group` and get a fifth heading, `Event`.
55. **`EventRequest` validates `name` length and `badge_class` membership.** Same shape as changes 48
    to 51, on a module those entries did not cover. `events.name` is a `varchar(255)`, so an overlong
    name was an SQL error rather than a field error; `badge_class` is resolved to a renderer class
    downstream and the Select validated nothing against its own options, exactly the gap change 49
    closes for special codes.
56. **Colour is reserved for state.** Design spec 1.3: a coloured chip in the new panel means the row
    is in a state worth noticing. The badge list's `Species` column carries `->color('gray')` today,
    which is decoration, and it does not ship. No other column loses a colour.
57. **Icons resolve through the panel's lucide set.** Design spec 1.5: `ManageIcon` renders lucide,
    so heroicon names are mapped to their nearest lucide equivalent. One visible substitution in
    these four modules: the badge list's `Extra Copy` mark is `file-text` rather than
    `document-plus`. Shape and meaning are unchanged.
58. **The fursuit list declares `defaultSort('id')`.** `FursuitResource` declares none, so its rows
    come back in whatever order the driver chooses and a paginated list with no `ORDER BY` can
    repeat or skip a row between pages. Stating the order the database already tends to return
    changes nothing an operator sees and makes paging deterministic.
59. **The fursuit activity log gains a `Logged at` column and an explicit `id desc` sort.** Audit 134:
    `ActivitiesRelationManager` has no `defaultSort` and no timestamp column at all, so the audit
    trail renders oldest-first with no way to tell when anything happened. Ordered by key rather than
    by timestamp because the log is append-only, so the two agree, and several entries routinely land
    inside the same second. The timestamp column is sortable on request. This rides with change 12,
    which makes the same list read-only.
60. **The fursuit view page names who holds the claim, and offers an `Edit` link.** `ViewFursuit`
    shows no claim indicator, so two reviewers working the same queue find out they collided only
    when one of them fails to approve. The page reports `Claimed by you` / `Claimed by another
    reviewer` / `Not claimed`. The `Edit` link is the second half: `EditAction` is commented out on
    the table and `ViewFursuit` has no edit button, so `/admin/fursuits/{id}/edit` is reachable today
    only by typing the URL, while plan 2.1 registers the route. `FursuitPolicy::update` still decides
    who sees it.
61. **A claim that somebody else already holds says so.** Filament silently redirected to the next
    fursuit. The reviewer is told `Already claimed` / `Another reviewer is working on this fursuit.`
    and stays where they are. Same reasoning as change 43, on the third of the three moderation
    gestures.
62. **An event that still owns fursuits cannot be deleted.** Events stay hard deletes (audit 7.7),
    but `fursuits.event_id` and `badges.fursuit_id` are both `ON DELETE CASCADE`, so deleting a past
    convention removes every fursuit and every badge of it *physically*: `SoftDeletes` never runs,
    `FursuitObserver` never runs, no `deleted_at` is written, no activity entry is logged, and the
    paid badges backing DSFinV-K and TSE reconciliation are gone with no restore path, since
    `EventPolicy` deliberately has no `restore` or `forceDelete` (plan 2.2). Single and bulk delete
    both refuse such an event with a danger toast naming the reason. An event with no fursuits
    deletes exactly as before.
63. **The attendee badge editor authorizes `updateAsOwner`, not `update`.** Change 2.2 makes the
    panel override on `BadgePolicy::update` request-independent, which is right for the panel and
    wrong for `Route::resource('badges')`: the same ability guards the public self-service editor,
    where `routeIs('filament.*')` used to be false and every operator therefore fell through to the
    owner rules. Without the split, a panel user editing somebody else's badge through the attendee
    form walks past the extra-copy, print-lock, event-ended and "still Pending" guards, resets the
    fursuit to pending review and recalculates the total on a card already rendered into a print
    batch. The owner rules move to `BadgePolicy::updateAsOwner()`; `update()` is the override plus
    those rules, so the panel is unchanged, and `App\Http\Controllers\BadgeController` asks the
    owner question directly.

**Phases 5 and 6.** Found while building Machines, Printers, Staff, RFID tags, SumUp readers and
Print jobs. Same rule as the rest of this section: each is a decision, not a parity gap, and each
needs a line in the cutover PR description.

64. **The print-job status picker offers operator edges only.** Change 10 made the field a
    transition; deriving the picker straight from `PrintJobStatusEnum::canTransitionTo()` then
    handed an operator three edges that belong to the print agent. Reaching `Queued` or `Printing`
    *is* a machine taking the card: `PrintJob::claim()` writes a `processing_machine_id` and a
    lease, and a bare `transitionTo()` writes neither, so the job is invisible to
    `PrintBatch::claimNextJob()` and `PrintJob::claimNextUnbatched()` (both filter
    `status = Pending`) and to `scopeLeaseExpired()` (it requires a lease). The card never prints
    and `completeIfFinished()` counts it as outstanding forever. `Retrying` is the same trap one
    step on: it is unclaimable, and `PrintBatch::requeueFailedJobs()` only picks up `Failed`, so
    resuming the batch does not rescue it either. The three drop out of the picker. In their place
    a failed card can be sent back to `Pending`, which runs `PrintJob::requeue()` - the domain
    method that exists for exactly that and which nothing in admin called - and a claimed card sent
    back to `Pending` runs `releaseLease()`, so the machine, the lease and the printer's hold all
    go with it. `Cancelled` is the only edge left on the bare `transitionTo()`, because it owns
    nothing.
65. **Deleting a print job settles its batch, releases the badge, and is refused while a machine
    holds the job.** Change 11 recalculated the counters; that is not enough on the last
    outstanding job of a run. `completeIfFinished()` has exactly one caller in `app/`,
    `PrintJob::markPrinted()`, so deleting or cancelling the last failed card leaves every progress
    badge reading 100% on a batch that never reaches `Completed`. Deletes and the status write both
    settle the batch now, and `PrintBatchStatusEnum` gains `Paused -> Completed`, which
    `completeIfFinished()` can only reach with nothing outstanding. Two more holes on the same
    path: `printing_locked_at` is cleared in exactly one place, `PrintBatch::cancel()`, so deleting
    a badge's only print job left `BadgePolicy::updateAsOwner()` refusing the owner's own edit on a
    badge with no card and nothing queued - the lock is released when no job of that badge survives
    uncancelled. And a job in `Queued` or `Printing` is not deletable at all, because the agent is
    mid-card and its own `printed` callback is route-model-bound: deleting the row 404s it, so the
    badge is never promoted and the printer never releases the job. `PrinterController::destroy`
    already refuses the mirror of this.
66. **The staff PIN never reaches the edit payload either.** Change 24 keeps the PIN plaintext and
    says so; the list transformer already computes `Set` / `Not Set` server-side so the credential
    stays out of that payload. The edit payload is the same payload - kept in the page props, in
    the DOM and in Inertia's history state for as long as the tab is open - and unlike the SumUp
    pairing code (change 16) it had no `reveal` gesture and no activity entry in front of it. The
    form is handed a fixed sentinel, `StaffController::PIN_UNCHANGED`, which `StaffRequest` drops
    before validation. It is not six digits, so no real PIN can collide with it; an untouched form
    still saves, and emptying the field still clears the PIN the way the helper text says. The
    round trip change 21 was about no longer needs the value, because `SecurePinRule` receives the
    record id.
67. **The ternary and select filters in these modules carry named placeholders.** Filament's
    `TernaryFilter` defaults read `-` / `Yes` / `No` and `SelectFilter` defaults to `All`, which
    say nothing about what is being filtered once several filters sit in one bar. `MachineResource`
    already overrode its own (`Active machines` / `Archived machines` / `All machines`, kept
    verbatim); the rest follow that shape: staff `All staff` / `Active` / `Inactive`, RFID tags
    `All tags` / `Active` / `Inactive`, print jobs `All statuses` / `All types` / `All printers`,
    and `Printable ID` / `Printable Type` as the input placeholders of the two free-value filters.
    The filter indicators themselves are unchanged.
68. **Every form in these modules gains a wrapping section heading.** `Machine`, `Printer`,
    `Staff` and `Sum Up Reader`. Same reasoning as change 54 and the same idiom plan 2.6 makes the
    panel default: a server-declared section is how a form is grouped here, and a flat schema with
    no heading reads as an unfinished page next to every other module.
69. **The print-job form is grouped into four named sections.** `Destination`, `Printable`,
    `Status` and `Diagnostics`, each with a description, plus helper texts on `print_batch_id`,
    `status` and the read-only batch and sequence fields. Audit 4.9 records a flat schema. Fourteen
    ungrouped fields, three of which move real hardware, is where a heading earns its place.
70. **Print jobs gain a sixth filter, `verified`.** A ternary: `Verified and not` /
    `Verified only` / `Unverified only`. The status strip shipped in phase 0 deep-links here with
    `filter[status]=printed&filter[verified]=0` to reach the cards nobody has vouched for (plan 1.2
    and 2.8); without the filter that link silently shows every printed card instead.
71. **Every list in these modules declares `defaultSort('id')`.** Same reasoning as change 58, on
    the four resources that declare none: a paginated list with no `ORDER BY` can repeat or skip a
    row between pages. Print jobs keep `PrintJobResource`'s own `id desc`.
72. **New copy that has no Filament counterpart.** The printer `is_active` helper
    (`An inactive printer is not offered for new print jobs.`), the two SumUp form helpers
    (`Set by SumUp. Not editable here.` and
    `The stored code is never loaded into this form. Use Reveal to read it, or type a new one to replace it.`
    with the placeholder `Leave blank to keep the current code`), the SumUp reveal confirm and the
    printer clear-error confirm. The reveal and clear-error actions follow from changes 16 and 27;
    the strings are new, and they are pinned here so they are not read as parity.
73. **Four smaller decisions in the same two phases.** `ListPrinters` returns no header actions, so
    the printer list has no way to add one; a `New printer` page action ships, because the create
    page and its route already exist and the form's own `TypeError` is fixed by change 7. A printer
    that still has print jobs cannot be deleted, single or bulk, because `print_jobs.printer_id` is
    `cascadeOnDelete` and nothing would recalculate the batches (audit landmines 23 and 80). A badge
    print job requires a `print_batch_id`, and the create form collects the
    `printable_type` / `printable_id` pair, both NOT NULL and neither asked for by the Filament form
    (audit 89). And the print-job list gains a toggleable `Batch` column, because `print_batch_id`
    and `sequence` are surfaced nowhere today.

**Phase 9: the two tools, the repair and the dashboard.**

74. **`pdfs/badge-list-range.blade.php` stops losing and stops throwing.** The one blade phase 9
    edits, in the two places where the badge-list PDF was not a faithful report of the data. First,
    the view chunked a range into columns of `rows_per_column` and then `array_slice()`d the chunks
    down to `columns`, so with the shipped defaults (50 x 12) a 1000-wide range printed its first
    600 numbers and dropped the other 400 under a header that had already counted all of them.
    Nothing reported it: the badges were inside a declared range, so change 33's
    `X-Badges-Out-Of-Range` counted zero. `PdfGeneratorController::paginateSections()` now splits a
    section into as many pages as it needs before the view sees it, heading the later ones
    `{range} (continued)` so the count a page prints is the count of what is on it, and the view
    grows its column count to fit rather than truncating. Second, `4 - strlen($firstPart)` went
    negative for any attendee id longer than four characters and `str_repeat()` throws on a negative
    count, so one five-digit id 500'd the whole request and returned no PDF at all. `attendee_id`
    comes from the registration service and `ToProcessing` concatenates it into `custom_id`
    verbatim, so any event past 9,999 registrations has them - and change 33 is what first routed
    them into the view, because before it they fell out of `groupBadges()` and were never rendered.
    The count is clamped at zero. While there, the id is escaped: only the alignment padding is
    markup, and the id is external input rendered through `{!! !!}`. `App\Filament\Pages\PdfGenerator`
    renders the same view and is not edited, so it inherits both fixes until cutover: it has no
    `paginateSections()` in front of it, so an oversized range comes out as more and narrower
    columns there rather than as a page of dropped numbers. Cramped and complete beats clean and
    wrong on a list whose whole job is to say which badges are in which box.
75. **The free-badge repair never touches a badge that has already been paid.** The deleted
    `FreeBadgeRepairService` selected the badges to convert on `is_free_badge = false` alone, which
    admits a badge already in the `Paid` state - money taken at the POS, with a `checkout_items` row
    against it. It then wrote `total = subtotal = tax = 0`, `status_payment = Paid` and
    `paid_at = now()`. That was defensible while the service credited the owner's wallet; it is not
    now. The credit went with `bavix/laravel-wallet` in `fa0554e`, `User::amountDue()` only sums
    unpaid badges, and so zeroing an already-paid badge returns nothing to anybody: the confirm
    dialog promises a refund of money that does not move, the checkout still records the original
    amount, and the original `paid_at` is overwritten by `now()` and is not among the properties the
    activity entry carries, so it is unrecoverable. `analyseUser()` selects only badges still owing
    their fee. The entitlement is still honoured where honouring it costs nothing to reverse: an
    owner holding one paid badge and one unpaid one gets the unpaid one converted.
76. **The PDF Generator form is one column, like every other form in the panel.** Audit 5.1 puts
    `title` / `subtitle` in a `Grid::make(2)` and `rows_per_column` / `columns` / `font_size` in a
    `Grid::make(3)`. Neither grouping ships. This is not a phase-9 decision but the panel-wide one
    `FormField` and `FormSection` were built on in phase 0: a form is a single column of
    label/control rows, so an operator's eye reads straight down and each helper sits under the
    thing it explains. `FormSection`'s `columns` prop exists for blocks of short read-only values
    (the batch and checkout infolists) and is not used for inputs anywhere. The three layout numbers
    keep `narrow`, which caps a short control's width, so the visual density the grid was for
    survives. Recorded here because it is a dropped grouping, the opposite of changes 54, 68 and 69.
77. **New copy in phase 9 that has no Filament counterpart.** Same reasoning as change 72: the
    strings are new, so they are pinned here rather than read as parity. The PDF Generator's
    read-only `Event` field and its helper (`Select an event in the header. A badge list is always
    one event.`), which follows from change 2.9 wiring the page to `EventScope` but is a field the
    Filament form did not have. The DB Service page subtitle (`Data repairs, run by hand and
    logged`) and its idle paragraph (`Nothing has run. The check reads the database and shows what
    it would change before anything is written.`), on a page whose Filament original had a title and
    an empty frame. The DB Service zero-row review line, which renders the `Nothing to fix`
    notification body a second time as page text, so a review reached by reload - the toast is
    long gone by then - still says why the table is missing. And the dashboard subtitle, which names
    the scope the page is showing (`{event} ({year})` or `All events`); it follows from 2.9 for the
    same reason and is the only place the dashboard states which selection its numbers are for.
78. **Four smaller decisions in phase 9.** The PDF Generator drops Filament's
    `fursuit.user.eventUsers` eager load: the document is built from `custom_id` alone and nothing
    downstream reads a user, an event user or even a fursuit, so it loaded three relations per badge
    for nothing and no rendered byte changes. The box-label path drops
    `mb_convert_encoding($v, 'UTF-8', 'UTF-8')` on the title and subtitle, which is a no-op, not
    sanitization (same encoding in and out) and the blade escapes both anyway; the document-level
    `mb_check_encoding` guard that did do something stays. `PdfGeneratorRequest` caps
    `badge_ranges` at 1000 characters and `title` / `subtitle` at 255, alongside the three layout
    ceilings: the range list is parsed comma part by comma part and the two strings are interpolated
    into a filename, and none of the three had any bound at all. And `DashboardController::previousEvent()`
    returns null when the current event has no `starts_at`, which the three widgets did not check;
    `where('starts_at', '<', null)` already matched nothing, so the guard states the intent rather
    than changing the answer.

---

## Part 3 - Build order

Each phase is one PR, ends green, and leaves `/admin-legacy` fully working. Phases are ordered by
operational risk, lowest first. The convention-critical slices, printing, checkouts and TSE, come
last, so that by the time they are touched the table builder, the action layer, the toast layer and
the test harness have all been exercised by six earlier modules.

**Phase 0 - Foundations.** `resources/css/manage.css` with its values on `:root` / `.dark` and a
`.manage-surface` class for teleported PrimeVue overlays (1.3), and the `mg-*` token block appended
after the `pos-*` block in `tailwind.config.js`; `ManageLayout` plus sidebar, top strip and toast
host; `routes/manage.php` with
the dashboard route only; the `access-manage` and `manage-admin` gates; `App\Support\Manage\{Table,
Column, Filter, Action, Status, Toast, Navigation}` ported; `DataTable`, `FilterBar`, `Pagination`,
`StatusBadge`, `ActionButton`, `FormSection`, `FormField`, `FormActions`, `CopyableText`, `ToastHost`
ported; the upload endpoint; the column-visibility endpoint; `EventScope` plus the
`ManageEventScope` middleware and the `EventSelector.vue` component with the bug fix from 2.9;
`tests/Feature/Manage/AccessTest.php` and `EventScopeTest.php`.

**Phase 1 - Users and Special Codes.** The two smallest modules, both broken today, neither on any
convention-critical path. Users: 8 columns minus `valid_registration`, full CRUD as pages rather than
modals. Special Codes: 4 columns with null-safe formatting, the `code` uniqueness rules including the
cross-check against `fursuits.catch_code`, a live `class_name`, an editable `constructor_data` and a
live `catch_url` preview. `SpecialCodePolicy`. Delivers the proof that the envelope, the forms and
the toasts work end to end.

**Phase 2 - Events, plus Badge Preview.** Events: 9 columns, no filters, the seven date fields at
their current granularity, hard delete with the default confirm copy. Badge Preview: a lookup form,
a details panel and view/download that open in a new tab against the re-guarded PDF routes. Low
volume, low blast radius, and it exercises the read-only tool page shape that phases 8 and 9 reuse.

**Phase 3 - Fursuits.** The moderation workflow, which is the highest-traffic surface in the panel
and the one where the current implementation carries the most surprising behaviour. List with the
`pending` default filter; view page with the infolist content; explicit claim and unclaim; approve,
reject with the keyed reason list, approve-rejected and send-notification; a deterministic
event-scoped next-record walk; the read-only activity list; transition-based status editing.
`ActivityPolicy`.

**Phase 4 - Badges.** The biggest single resource, 583 lines today. 14 columns including the money
fix and the portable attendee-id sort; 4 filters including the attendee range; 5 form sections with
`total` read-only and both statuses as transition pickers; `selectCurrentPageOnly` semantics
preserved on the bulk selection. **The print row action and the bulk print action are not in this
phase**; they ship in phase 7 with the rest of the print pipeline. Shipped the corrupted-total
report from change 3, since removed (see 2.10 #3).

**Phase 5 - POS identity: Machines, Staff, RFID tags, SumUp Readers.** Machines with archive and
restore, single and bulk, and the on-demand expiring login link. Staff with the `SecurePinRule` fix,
the null setup code fix, the non-writing Generate button and the nested RFID tag table, which carries
its own bulk delete as well as the per-tag one. SumUp readers with the masked pairing code, a real
`reveal` action and both deletes, single and bulk. `RfidTagPolicy`. Nothing here runs during a
print run, but it is the control plane for POS login, so it lands before printing.

**Phase 6 - Printers and Print Jobs.** Printers: 9 columns plus the condition columns from change 27,
an inline `is_active` toggle that goes through a real endpoint, the `clear-error` action, a 15s poll,
null-safe status. Print Jobs: 11 columns, 5 filters including `printer` as an ordinary filter,
transition-based status editing, retry, and counter-safe deletes.

**Phase 7 - Print Batches, and the badge print pipeline.** Batch list with the three run controls and
their verbatim confirm copy, the batch detail page with a polling card list and the `verify` action,
and `PrintBatchPolicy` gating all four on `is_admin`. The badge `print` and bulk `printBadges`
actions from phase 4 land here, against `BadgePrintQueue`, so the whole print path is reviewed in one
PR. Freeze this PR outside event windows.

**Phase 8 - Checkouts and TSE Clients.** Checkouts: 9 columns with the money fix and the working
status filter, 4 filters, the read-only detail page with the real TSE columns, the queued receipt
print and the re-homed receipt link, the read-only items table. TSE: 3 columns, read-only identity
fields, no local fabricator. `CheckoutPolicy`.

**Phase 9 - PDF Generator, DB Service, dashboard.** PDF Generator wired to the one event scope, with
slugged filenames, corrected copy and out-of-range reporting. DB Service with the preview and apply
flow, the same confirm copy and the same three notifications, gated on `manage-admin`. Dashboard:
the four stats, the doughnut and the bar chart on `chart.js`, at a 15s poll.

**Phase 10 - Parity gate and cutover.** Part 5. **Done** - see the note at the head of Part 5.

---

## Part 4 - Test plan

### 4.1 There is no baseline, and what replaces it

The ef-streaming migration started from `tests/Feature/Filament/AdminPanelTest.php`, 17 cases, and
could measure itself against it. **This app has no equivalent.**
`tests/Feature/DbServiceMaintenancePageTest.php` is the only test that touches the panel: 4 cases
covering the `DbService` admin gate, its navigation visibility and one Livewire round-trip through
`FreeBadgeRepairService`. There is not one assertion in the repository about an admin column, filter,
sort order, default filter value, row action, bulk action, confirm modal or notification string.

What replaces the missing baseline is **the audit document itself**. `current-filament-features.md`
is 2 824 lines and records, per module, every column key, label, type, sortable flag, searchable
flag, toggleable flag and hidden-by-default flag; every filter with its options, default and query;
every action with its label, icon, visibility predicate and verbatim modal copy; and every
notification title and body. Those tables are transcribed directly into assertions. A dropped column
fails a test because the expected column list is a literal array copied out of the audit, not a
description of one.

That is the mechanism that makes "feature parity" checkable here rather than asserted by hand, and
it is the only reason this rebuild is safe to attempt without a baseline suite.

### 4.2 Server-side parity tests

Pest, `tests/Feature/Manage/{Module}Test.php`, one per module, plus `AccessTest`, `NavigationTest`
and `EventScopeTest`. Modules: `Events`, `Badges`, `Fursuits`, `SpecialCodes`, `Checkouts`,
`Machines`, `Printers`, `PrintJobs`, `PrintBatches`, `Staff`, `SumUpReaders`, `TseClients`, `Users`,
`Tools`, `DbService`, `Dashboard`.

Each module test covers:

1. **Access.** Guest redirects to `/auth/login`; an authenticated user with neither flag gets 403;
   `is_reviewer` gets 200 or 403 per the policy table in 2.2; `is_admin` gets 200. This is also
   where the three new policies are locked in: a reviewer must get 403 on batch pause, on checkout
   receipt printing and on every special-code write.
2. **Index contract.** Component name plus the column and filter lists, transcribed from the audit:

   ```php
   $this->actingAs($this->admin)->get(route('manage.badges.index'))
       ->assertInertia(fn (Assert $page) => $page
           ->component('Manage/Badges/Index')
           ->where('columns.*.key', [
               'fursuit.image','fursuit.name','fursuit.species.name','fursuit.user.name',
               'custom_id','sort_attendee_id','print_jobs_count','status_fulfillment',
               'status_payment','extra_copy','total','created_at','printed_at','picked_up_at',
           ])
           ->where('columns.10.type', 'money')
           ->where('sort', ['key' => 'sort_attendee_id', 'dir' => 'asc'])
       );
   ```

   And for the default-on filters that are easiest to lose:

   ```php
   ->where('filters.0.key', 'status')
   ->where('filters.0.value', 'pending')      // Fursuits: default filter is pending
   ->where('filters.0.value', null)           // Machines: blank ternary means notArchived()
   ```

3. **Filters, sort, search, pagination.** One case per filter proving it changes the row set,
   including that the fursuit `pending` default and the machine `notArchived()` blank branch apply
   with no query string at all. Default sort direction per module. Search matches exactly the fields
   the audit marks `searchable()`. A case proving the attendee-id sort and the attendee-range filter
   work **on SQLite**, which they do not today.
4. **Every action.** Happy path (state changed, correct toast flashed, correct redirect), plus
   authorization (403 without the ability), plus each guard:
   - a batch that is not `Printing` cannot be paused, and the danger toast reads
     `Cannot cancel a batch that is {label}` for cancel
   - `verify` is offered only for a job that is `Printed` with `verified_print_at` null
   - cancelling a batch unlocks exactly the badges with no printed job, and recalculates counters
   - deleting print jobs recalculates the parent batch counters
   - bulk archive and bulk restore are all-or-nothing on a policy failure
   - the machine login link is a `temporarySignedRoute` and its activity entry exists
5. **Forms and state machines.** Validation rules; create and update writing exactly the expected
   columns; and, specifically, that the status fields **transition** rather than write:
   - approving a fursuit writes `approved_at`, nulls `rejected_at`, logs `Fursuit approved` and
     notifies, under the `event.ends_at` guard the transition already carries
   - rejecting writes the reason into the activity properties and sends
     `FursuitRejectedNotification` with the same string
   - a badge cannot be moved to a state `config()` does not allow from its current one
   - a print job set to Printed from `/admin` promotes the badge and recalculates the batch
6. **Money.** One case per money surface asserting cents in, euro string out, including that the
   badge total column no longer renders 100x, and that no `/admin` write path can put a euro string
   into a cents column.
7. **Uploads.** `Storage::fake('s3')`, asserting disk, directory, private visibility, that the model
   stores the path and that the accessor returns a signed URL.
8. **Event scope.** Its own file, and the one place the middleware bug is locked out:
   - with no session state, the newest event is selected
   - selecting `all` persists and **survives the next request**, which is exactly what fails today
   - `POST /admin/event` with an unknown id is a 422, not a poisoned session
   - the badge, fursuit, special-code, PDF-generator and dashboard queries all narrow when a specific
     event is selected and all widen when `all` is selected
   - the fursuit moderation queue never returns a fursuit from another event

Existing coverage is preserved rather than replaced: `DbServiceMaintenancePageTest`'s four cases are
re-expressed against `/admin/maintenance/db-service` in phase 9. There is no Filament version left
to keep until phase 10: that file, its page and `App\Services\FreeBadgeRepairService` were all
deleted in `5aa2148`, so `tests/Feature/Manage/DbServiceTest.php` is the only coverage of the repair
path that exists.

Factories: this repo has factories for badges, fursuits, events and users. `PrintBatch`, `PrintJob`,
`Printer`, `Machine`, `Staff`, `RfidTag`, `SumUpReader`, `TseClient`, `Checkout` and `SpecialCode`
need them, built as each phase lands.

Any migration written during this work is guarded with
`App\Support\Migrations\SchemaGuard` per the project rule; `MigrationIdempotencyTest` already locks
in the helper's behaviour. Only two phases are expected to need one: the `is_reviewer` cast is a
model change, not a migration, and the placeholder image is a file.

Rough size: 30 to 50 assertions per module, roughly 550 total across 16 files. Runtime target under
40s on SQLite. `./vendor/bin/pint` clean is part of every phase.

### 4.3 The parity checklist

`docs/admin/parity-checklist.md`, generated by transcribing the audit's tables into one tick box per
column, filter, action, notification and form field, grouped by module and annotated with the phase
that delivers it. It is the artefact the reviewer ticks through in each phase PR and again in the
cutover PR. Every line resolves to either a passing test in `tests/Feature/Manage/` or a numbered
entry in 2.10. Nothing else is an acceptable outcome for a line.

### 4.4 The gate

Cutover requires all of:

- [ ] Every row in `current-filament-features.md` sections 4, 5, 6 and 7 maps to a passing test or a
      numbered entry in 2.10.
- [ ] `php artisan test` green. `DbServiceMaintenancePageTest` was deleted in `5aa2148` and is no
      longer part of this gate; its four cases live in `tests/Feature/Manage/DbServiceTest.php`.
- [ ] `./vendor/bin/pint` clean.
- [ ] A reviewer walkthrough: 20 pending fursuits approved or rejected end to end from `/admin`,
      with the notifications landing in Mailpit.
- [ ] An operator walkthrough against a seeded database: select badges, build a batch, watch it
      print, pause on a fault, resume, verify cards, cancel the remainder, confirm the badges
      unlocked and the counters agree.
- [ ] A fiscal spot check: three checkouts compared field by field between `/admin-legacy` and `/admin`,
      including that the money figures now agree with each other and with the receipt.

Playwright is deliberately out of scope for phase 1. The behaviours it would cover here (polling,
column toggles, confirm dialogs) are the same ones already exercised in `ef-streaming`, and this
repo has no browser test harness to extend. Revisit after cutover.

---

## Part 5 - Cutover and Filament removal

**Done.** All fifteen steps below have landed. They are left as written, as the record of what was
intended; the paragraphs here note the three places the outcome differs from the instruction.
Filament is out of `composer.json`, out of `vendor/`, and out of the
application: no provider, resource, page, widget, relation manager, blade view, stylesheet or
contract remains. `/admin` is the Inertia panel and the only panel, the route names are `admin.*`,
and `/admin-legacy/{path?}` is a 301 to `/admin` kept for one release (step 1). See the **Cutover
status** block at the top of [`parity-checklist.md`](./parity-checklist.md) for what was verified.

What survives a `rg -i filament` over the source tree is prose, not code: comments and Pest test
names that say what the old panel did — "the bulk delete carries Filament default copy", "the
Filament resource had no view page". That wording is the parity record, and it is deliberately kept.
The one exception was three comments making a false *present-tense* claim (`resources/css/pos.css`
lines 5 and 54, `resources/css/manage.css` line 81, all "the public site and Filament keep …"),
which step 13 called out and which are now reworded.

Two smaller deltas. Step 11's Livewire removal needed no separate decision: Livewire came in only as
a Filament dependency and left with it, and nothing outside `app/Filament/` had ever referenced it.
And the `filament.access` permission string, which the cutover brief said to keep if production
stores it, is not present anywhere in the codebase and never was — this app gates the panel on the
`access-manage` gate over `is_admin` / `is_reviewer`, so there is nothing to keep.

Phase 10, one PR, only once the gate above is fully green, and never inside an event window.

1. Drop the `/admin-legacy` mount. `Route::redirect('/admin-legacy/{path?}', '/admin', 301)
   ->where('path', '.*')` for one release so bookmarked deep links land somewhere, then remove it.
   Nothing has to move to free `/admin`: the panel has served it since phase 0.
2. Delete `app/Providers/Filament/AdminPanelProvider.php` (72 lines) and remove it from the providers
   list.
3. Delete `app/Filament/` entirely. By the audit's own line counts that is 13 resources
   (`EventResource` 161, `BadgeResource` 583, `FursuitResource` 196, `SpecialCodeResource` 137,
   `CheckoutResource` 294, `MachineResource` 146, `PrinterResource` 133, `PrintBatchResource` 296,
   `PrintJobResource` 259, `StaffResource` 128, `SumUpReaderResource` 77, `TseClientResource` 83,
   `UserResource` 93), their page classes, four relation managers
   (`ItemsRelationManager` 120, `PrintJobsRelationManager` 127, `RfidTagsRelationManager` 81,
   `ActivitiesRelationManager` 70), three pages (`PdfGenerator` 483, `BadgePreview` 105,
   `DbService` 113), three widgets (`StatsOverview` 74, `BadgeStatusChart` 87,
   `EventComparisonChart` 98), `Traits/HasEventFilter` 38 and `Components/EventSelector` 21.
4. Move, do not delete, the two portable pieces: `app/Filament/Traits/HasEventFilter.php` is already
   superseded by `App\Support\Manage\EventScope` in phase 0, and
   `app/Http/Middleware/FilamentEventSelector.php` is superseded by `ManageEventScope`. Delete both
   originals here, once nothing references them.
5. Delete `resources/views/filament/` (`components/event-selector.blade.php` 34,
   `pages/pdf-generator.blade.php` 32, `pages/badge-preview.blade.php` 51,
   `pages/db-service.blade.php` 125). **Keep `resources/views/pdfs/`** except the dead
   `badge-list.blade.php` (165 lines): `badge-list-css`, `badge-list-header`, `badge-list-range` and
   `box-labels` are the PDF Generator's actual templates and move with it. Keep
   `resources/views/receipts/sale.blade.php`, which belongs to `CreateReceiptFromCheckoutJob`.
6. Delete `public/css/filament-custom.css` and `resources/css/filament-custom.css` (8 lines each).
   Their 2px density is already carried by the 28px row spec in 1.4.
7. Delete `config/filament.php`. **Check `FILAMENT_FILESYSTEM_DISK` in every deployment environment
   first** (audit 7.4): if it is set, something depends on the default disk and that dependency has
   to be re-pointed before the file goes.
8. Strip the two Filament interfaces from the state bases:
   `app/Models/Badge/State_Payment/BadgePaymentStatusState.php` and
   `app/Models/Badge/State_Fulfillment/BadgeFulfillmentStatusState.php` implement
   `Filament\Support\Contracts\HasColor` and `HasIcon`. **Remove the interfaces, keep the
   `getColor()` and `getIcon()` methods** on every concrete state: they are the colour and icon
   contract `Status.php` consumes. `BadgeFulfillmentStatusState` also carries the `config()`
   transition map, which has nothing to do with Filament and must not be touched.
9. Remove `Filament\Models\Contracts\FilamentUser` and `canAccessPanel()` from
   `app/Models/User.php:74-77`. The rule now lives in the `access-manage` gate.
10. `composer remove filament/filament flowframe/laravel-trend`. `flowframe/laravel-trend` is a
    Filament-ecosystem charting helper with zero references anywhere in `app/` or `resources/`.
11. Livewire. Grep before removing: it is pulled in for the admin panel on the web side, but check
    the POS and any published config first. If nothing outside `app/Filament` references it, remove
    it with Filament and delete the published config.
12. ~~Delete `tests/Feature/DbServiceMaintenancePageTest.php` in this same PR, after confirming its
    four cases are covered by `tests/Feature/Manage/DbServiceTest.php`.~~ Already done: the file was
    deleted in `5aa2148`, ahead of this phase, and `DbServiceTest` carries the four cases.
13. Fix the stale comment in `routes/web.php:42` (`// Admin badge PDF routes (used by Filament)`) and
    the two stale comments in `resources/css/pos.css` lines 5 and 53 that refer to Filament keeping
    its own look.
14. Rename the route names `manage.*` to `admin.*`. They were held back only because
    `admin.badge-pdf.view` and `admin.badge-pdf.download` owned the prefix; by the time this step
    runs the `/admin` group in `routes/web.php` is gone, replaced by
    `admin.tools.badge-preview.pdf.*` under `can:access-manage` (2.1). Change the `->name('manage.')`
    prefix in `bootstrap/app.php`, then sweep `route('manage.` and `routeIs('manage.*')` across
    `app/`, `resources/js/` and `tests/`. The `App\Support\Manage` namespace, the
    `resources/js/Components/Manage` directory, `manage.css` and the `manage.event_id` session keys
    are internal names and stay as they are; only route names move.
15. Update `CLAUDE.md`: `/admin` is the Inertia admin area, `/admin-legacy` is gone, Filament leaves
    the stack description, and the `App\Support\Manage` layer is named as the place table and action
    behaviour lives.

Rollback during the parallel phase is trivial, because nothing is removed until phase 10. After
phase 10 it is a revert of that single PR, so keep the removal commit strictly mechanical: no
behaviour changes, no renames beyond the ones listed, no opportunistic cleanup.

---

## Risks

| Risk | Mitigation |
|---|---|
| No baseline suite, so a silently dropped column or filter default is invisible | The column and filter list assertions in 4.2 are literal arrays transcribed from the audit; the parity checklist in 4.3 is ticked per phase, not once at the end |
| Cutover lands near a convention | Phases 7, 8 and 10 are frozen during event windows. The parallel panel means there is never schedule pressure to ship them |
| A behaviour change in 2.10 turns out to be load-bearing for someone | Each is numbered, each appears in its phase PR description, and `/admin-legacy` still works, so the old behaviour is one URL away until phase 10 |
| The state-machine changes (8, 9, 10) block an operator who relied on writing a raw status to get out of a stuck record | Ship an explicit admin-only "force state" action alongside them, logged to the activity log, rather than leaving a silent free-text field |
| The `PrintBatchPolicy` change locks out a reviewer who runs print batches in practice | Confirm with the operators who actually run print batches **before** phase 7 lands, not after. If reviewers do run them, the policy becomes an explicit `print.manage` flag rather than reverting to no check |
| Tailwind 4 pressure returns mid-project | The decision and its numbers are recorded above. A Tailwind 4 upgrade is its own PR, after the POS rework, with its own visual pass over the 26 701 preset lines |
| The POS rework on this branch conflicts with `/admin` work in `tailwind.config.js` | No existing token is touched semantically: `/admin` only appends to `theme.extend.colors` under the `mg-` prefix and adds one CSS file. Textually it is not free, though - `tailwind.config.js` is dirty on `printing-rework` right now with a 23-line `pos-*` block, and the `mg-*` block appends at the same closing brace of `theme.extend.colors`. Expect one trivial conflict there, and land the `mg-*` block after the POS block rather than merging into it |
| Money fixes reveal already-corrupted badge totals | Change 3 shipped a read-only report in phase 4 before anything was written; the report has since been removed (see 2.10 #3). The write path stays read-only, so no new corruption is possible |
| Private-S3 upload or signed-URL regression | `Storage::fake('s3')` per purpose, plus the fursuit image is the only upload in scope, so the surface is one field |
| Polling load multiplies across 16 tables | `only:` partial reloads, pause on hidden tab and dirty form, cached badge counts, and the widget interval dropped from 5s to 15s |

---

## Effort estimate

Working days, one developer.

| Phase | Scope | Days |
|---|---|---|
| 0 | Foundations: tokens, layout, `App\Support\Manage` port, 10 components, gates, event scope, uploads | 4 |
| 1 | Users, Special Codes | 2 |
| 2 | Events, Badge Preview | 2 |
| 3 | Fursuits: list, view, approval workflow, activity, transitions | 4 |
| 4 | Badges: 14 columns, 4 filters, 5 form sections, money fixes, portable sort | 4 |
| 5 | Machines, Staff, RFID tags, SumUp Readers | 3 |
| 6 | Printers, Print Jobs | 3 |
| 7 | Print Batches, cards, badge print pipeline | 4 |
| 8 | Checkouts, TSE Clients | 3 |
| 9 | PDF Generator, DB Service, dashboard | 4 |
| 10 | Parity gate, walkthroughs, Filament removal | 2 |
| | **Total** | **35** |

The estimate rests on:

- **2 449 lines arrive by copy.** 974 PHP from `app/Support/Manage/` and 1 475 JS from 18 components,
  changed only for model names. A further 667 lines (`Status.php`, `Navigation.php`,
  `ActionButton.vue`, `ManageSidebar.vue`, `ManageStatusStrip.vue`, `ManageLayout.vue`) keep their
  structure and change their content. Phase 0 is 4 days rather than 8 because of this.
- **The audit is treated as correct and complete.** No phase budgets time to re-read
  `app/Filament/**`. If a phase finds the audit wrong on a material point, that is a new day, not a
  rounding error.
- **The equivalent work in `ef-streaming` is the calibration.** Seven modules (Emotes, Recordings,
  Roles, Servers, Shows, Sources, Users) plus a dashboard and a settings page produced 3 734 lines of
  controllers, 2 992 lines of pages and 4 049 lines of tests; the Filament set they replaced was 7
  resources and 2 pages. This app has 13 resources, 4 relation managers, 3 custom pages and 3
  widgets, roughly 1.6x, and carries far more corrective work per module because of section 9 of the
  audit.
- **Tailwind stays at 3 and PrimeVue at 3.** A Tailwind 4 upgrade is not in these numbers and
  would not be a small addition to them.
- **One developer, no parallel phases.** Phases share the table builder and the action layer, so
  splitting them across people costs more in merge conflicts than it buys.
- **A seeded database with realistic volumes is available** for the phase 4 and phase 7 walkthroughs.
  Badge sorting and batch ordering are not testable at ten rows.
- Excluded: scheduling the operator walkthrough, any design iteration beyond the spec in Part 1, a
  Playwright harness, and the follow-ups explicitly deferred in 2.10 (cross-module search, moving the
  event scope into the URL).
