# Current Filament Admin: Feature Inventory

## 1. Purpose and how to use it

Snapshot of everything `/admin` does today. This is the parity contract for the rebuild: every
row in this document must map either to a passing test against the replacement, or to an explicit
listed decision to drop it. Nothing here is a design proposal. Where the current behaviour is
broken, it is documented as broken, and the decision to keep or fix it is a separate, deliberate
one.

Source of truth: `app/Filament/**`, `app/Providers/Filament/AdminPanelProvider.php`,
`app/Http/Middleware/FilamentEventSelector.php`, `resources/views/filament/**`,
`resources/views/pdfs/**`, `public/css/filament-custom.css`.

Every string quoted in backticks is verbatim from source. Filament's own default modal copy is
reproduced as the framework actually renders it, resolved against
`vendor/filament/actions/resources/lang/en/delete.php` and
`vendor/filament/actions/resources/lang/en/modal.php`:

| Action kind | Modal heading | Modal description | Submit button | Cancel button |
|---|---|---|---|---|
| `DeleteAction` (row / header) | `Delete :label` - `:label` is the resource's model label | `Are you sure you would like to do this?` | `Delete` | `Cancel` |
| `DeleteBulkAction` | `Delete selected :label` | `Are you sure you would like to do this?` | `Delete` | `Cancel` |
| Any action with a bare `requiresConfirmation()` and no `modalHeading()` | the action's own label | `Are you sure you would like to do this?` | `Confirm` | `Cancel` |
| `DeleteAction` success toast | title `Deleted` | - | - | - |
| `DeleteBulkAction` success toast | title `Deleted` | - | - | - |

`DeleteBulkAction`'s trigger label (the dropdown entry, not the modal heading) is `Delete selected`.
Wherever this document says "Filament default delete copy", it means exactly the rows above. No
resource in this codebase overrides any of them.

## 2. Panel shell

`app/Providers/Filament/AdminPanelProvider.php` (72 lines).

| Aspect | Current value |
|---|---|
| Panel id | `admin`, marked `->default()` - `Filament::getPanel()` with no id resolves to it, and `filament.admin.*` is the route-name prefix |
| Path | `/admin` |
| Colours | `['primary' => Color::Blue]` (line 33). No other colour slot overridden; danger/gray/info/success/warning stay Filament defaults |
| Layout | `->maxContentWidth('100%')` (line 35). No `sidebarCollapsibleOnDesktop()`, no `topNavigation()`, no `brandName()`, no `brandLogo()`, no `favicon()`, no `font()`, no `darkMode()` call (dark-mode toggle is Filament's default = enabled), no `spa()`, no `databaseNotifications()` |
| Nav groups | **Not declared.** There is no `->navigationGroups([...])` call, so sidebar group order is Filament's discovery/registration order, not a written-down order |
| Login | **No `->login()`.** The panel has no login screen of its own. `Filament\Http\Middleware\Authenticate` is the only auth middleware and it redirects unauthenticated visitors to the app's `login` route (`routes/web.php:7` → `/auth/login`, Identity/Socialite SSO via `AuthController`). Guard is the default `web` guard; no Filament guard override anywhere |
| Discovery | `->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')`, `->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')`, `->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')` |
| Explicit pages | `->pages([Filament\Pages\Dashboard::class])` |
| Explicit widgets | `->widgets([Filament\Widgets\StatsOverviewWidget::class])` (line 42) - Filament's own **base** class, not `App\Filament\Widgets\StatsOverview`. It is concrete with an empty `getStats()`, so it renders an empty stats strip on the dashboard |

**Middleware stack** (`->middleware([...])`, line 45, in order):

| # | Class |
|---|---|
| 1 | `Illuminate\Cookie\Middleware\EncryptCookies` |
| 2 | `Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse` |
| 3 | `Illuminate\Session\Middleware\StartSession` |
| 4 | `Illuminate\Session\Middleware\AuthenticateSession` |
| 5 | `Illuminate\View\Middleware\ShareErrorsFromSession` |
| 6 | `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` |
| 7 | `Illuminate\Routing\Middleware\SubstituteBindings` |
| 8 | `Filament\Http\Middleware\DisableBladeIconComponents` |
| 9 | `Filament\Http\Middleware\DispatchServingFilamentEvent` |
| 10 | `App\Http\Middleware\FilamentEventSelector` - custom, the global event selector |

**Auth middleware:** `->authMiddleware([Filament\Http\Middleware\Authenticate::class])` only.

**Render hooks:**

| Hook | Payload |
|---|---|
| `PanelsRenderHook::GLOBAL_SEARCH_BEFORE` (line 60) | `view('filament.components.event-selector', ['events' => Event::orderBy('starts_at','desc')->get(), 'selectedEventId' => session('filament_selected_event_id')])` - the global event dropdown in the topbar |
| `PanelsRenderHook::HEAD_END` (line 67) | `'<link rel="stylesheet" href="'.asset('css/filament-custom.css').'">'` |

**`public/css/filament-custom.css`** (8 lines) is the entire custom theme:

```css
/* Custom Filament Styles - Ultra Condensed Layout */

/* Ultra condensed table cells and form inputs */
.fi-ta-cell,
.fi-ta-cell div {
    padding-top: 2px !important;
    padding-bottom: 2px !important;
}
```

Every admin table row is vertically condensed to 2px cell padding. This is the only styling the
panel carries beyond stock Filament, and it is the reason the admin tables look dense. A byte-identical
copy is committed at `resources/css/filament-custom.css`; the served file is the one under `public/`,
which Vite does not build.

### 2.1 The global event selector

Four moving parts.

**1. `app/Http/Middleware/FilamentEventSelector.php`** (32 lines) runs on every `/admin` request.
The full body of `handle()`:

```php
if ($request->has('selected_event_id')) {
    $eventId = $request->get('selected_event_id');
    if ($eventId === 'all') {
        session()->forget('filament_selected_event_id');
    } else {
        session(['filament_selected_event_id' => $eventId]);
    }
}

if (! session()->has('filament_selected_event_id')) {
    $latestEvent = Event::orderBy('starts_at', 'desc')->first();
    if ($latestEvent) {
        session(['filament_selected_event_id' => $latestEvent->id]);
    }
}

return $next($request);
```

The `forget()` at line 18 and the unconditional re-seed at lines 24-28 run inside the same
`handle()` call. **`session('filament_selected_event_id')` is therefore never null after the
middleware, as long as at least one `Event` row exists.** The value is stored as the raw **string**
from the query string.

Consequences, which the rebuild must record rather than reproduce by accident:

- **The "all events" option does not work.** Requesting `?selected_event_id=all` forgets the key
  and immediately re-seeds it with the newest event's id on the same request. There is also no
  `all` option rendered in the blade, so there is no way to reach that branch from the UI at all.
- **`HasEventFilter::applyEventFilter()`'s "no id, return the query unfiltered" branch is dead
  code.** It is never taken in a request that passed through the middleware.
- **Every `getNavigationBadge()` that returns `null` when `getSelectedEventId()` is falsy is dead
  code on that branch** - `BadgeResource::getNavigationBadge()`, `FursuitResource::getNavigationBadge()`
  and `FursuitResource::getNavigationBadgeColor()` all guard on a condition that cannot occur.

**2. `resources/views/filament/components/event-selector.blade.php`** (34 lines) - the topbar
`<select id="event-selector">`. Label text `Event:`. Options are every `Event` ordered
`starts_at desc`; option text is `{{ $event->name }} ({{ $event->starts_at->format('Y') }})` and,
**only for the currently selected event**, an appended `✓ Orders Open` when `$event->allowsOrders()`
else `✗ Orders Closed`. On change it does a full page navigation via an inline
`updateQueryStringParameter(uri, 'selected_event_id', value)` helper (regex query-string rewrite);
no Livewire, no fetch. The `<script>` defining that helper is emitted once per render-hook
invocation into the global scope. The select offers **no `all` option**.

**3. `app/Filament/Traits/HasEventFilter.php`** (38 lines) - the read side used by resources and widgets:

- `getSelectedEventId(): ?int` → `session('filament_selected_event_id')` (returns the stored **string**,
  coerced by the `?int` return type)
- `getSelectedEvent(): ?Event` → `Event::find($id)` or null
- `applyEventFilter(Builder $query, ?string $relationship = null): Builder` → no id, return the query
  untouched (dead branch, see above); `$relationship` given, `whereHas($relationship, fn ($q) => $q->where('event_id', $eventId))`;
  otherwise `where('event_id', $eventId)`

**4. `app/Filament/Components/EventSelector.php`** (21 lines) - a `Filament\View\Component` that
renders the same blade with the same data. **It is never referenced anywhere.** The panel provider
inlines the `view(...)` call in the render hook instead. Dead code.

**Session-key mismatch.** Everything above reads and writes `filament_selected_event_id`. But
`app/Filament/Pages/PdfGenerator.php:365` reads `session('filament.admin.selected_event_id')`,
which nothing ever writes. The PDF Generator therefore always sees `null` for the selected event and
silently falls back to `Event::latest('starts_at')->first()`.

**Which surfaces are event-scoped:**

| Surface | Event-scoped? |
|---|---|
| `BadgeResource` table | yes - `applyEventFilter($query, 'fursuit')` |
| `FursuitResource` table | yes - `applyEventFilter($query)` |
| `StatsOverview`, `BadgeStatusChart`, `EventComparisonChart` widgets | yes - via `getSelectedEventId()`, falling back to newest event |
| `ViewFursuit`'s moderation queue (`Fursuit::where('status','pending')->first()`) | **no** |
| `EventResource`, `SpecialCodeResource`, `CheckoutResource`, all POS resources, `UserResource` | **no** |
| `PdfGenerator` | **no** - reads the wrong session key, always uses the newest event |

### 2.2 Nav tree

Groups have no declared order, so they render in Filament's group-registration order. Within a
group, `navigationSort` ascending, then label. No resource declares `$slug`, `$modelLabel`,
`$pluralModelLabel` or `$recordTitleAttribute` anywhere in the panel - every URL slug and label is
Filament's kebab/plural derivation from the model name.

| Group | Item | Class | Label | Icon | Sort | Navigation badge |
|---|---|---|---|---|---|---|
| Events & Registration | Events | `Resources/EventResource.php` | default `Events` | `heroicon-o-calendar-days` | 1 | - |
| Events & Registration | Badges | `Resources/BadgeResource.php` | default `Badges` | `heroicon-o-identification` | 2 | `Badge::whereHas('fursuit', event_id = selected)->count()`; no badge colour (Filament default primary) |
| Events & Registration | Fursuits | `Resources/FursuitResource.php` | default `Fursuits` | `heroicon-o-user-circle` | 3 | `Fursuit::where('event_id', $eventId)->count()`; colour `warning` if any `status = 'pending'` for that event, else `success` |
| Events & Registration | Special Codes | `Resources/SpecialCodeResource.php` | default `Special Codes` | `heroicon-o-qr-code` | 3 (duplicate of Fursuits) | - |
| Sales | Checkouts | `Resources/CheckoutResource.php` | default `Checkouts` | `heroicon-o-shopping-cart` | 10 | - |
| POS | Machines | `Resources/MachineResource.php` | default `Machines` | `heroicon-o-computer-desktop` | 1 | - |
| POS | Printers | `Resources/PrinterResource.php` | default `Printers` | `heroicon-o-printer` | 2 | - |
| POS | Print Batches | `Resources/PrintBatchResource.php` | `Print Batches` | `heroicon-o-rectangle-stack` | 2 (duplicate of Printers) | count of **batches** having a `printed` job with `verified_print_at IS NULL`, `null` when 0; colour always `warning` |
| POS | Print Jobs | `Resources/PrintJobResource.php` | default `Print Jobs` | `heroicon-o-queue-list` | 3 (duplicate of Staff) | - |
| POS | Staff | `Resources/StaffResource.php` | default `Staff` | `heroicon-o-user-group` | 3 (duplicate of Print Jobs) | - |
| POS | SumUp Readers | `Resources/SumUpReaderResource.php` | default `Sum Up Readers` | `heroicon-o-credit-card` | 4 | - |
| POS | TSE Clients | `Resources/TseClientResource.php` | default `Tse Clients` | `heroicon-o-shield-check` | 5 | - |
| User Management | Users | `Resources/UserResource.php` | default `Users` | `heroicon-o-users` | 1 | - |
| Tools | PDF Generator | `Pages/PdfGenerator.php` | from `$title` = `PDF Generator` | `heroicon-o-document-text` | none (`null`) | - |
| Debug Tools | Badge Preview | `Pages/BadgePreview.php` | `Badge Preview` | `heroicon-o-identification` | 100 | - |
| Maintenance | DB Service | `Pages/DbService.php` | `DB Service` | `heroicon-o-wrench-screwdriver` | none | - (admin-only: `shouldRegisterNavigation()` and `canAccess()` both return `auth()->user()?->is_admin`) |
| (ungrouped) | Dashboard | `Filament\Pages\Dashboard` | `Dashboard` | default | default | - |

Three `navigationSort` collisions exist inside a single group: `FursuitResource`/`SpecialCodeResource`
(both 3, Events & Registration), `PrinterResource`/`PrintBatchResource` (both 2, POS),
`PrintJobResource`/`StaffResource` (both 3, POS). The tie-break is Filament-internal, so the rendered
order is partly accidental. Capture the rendered order, not just the numbers.

## 3. Authorization today

**The panel gate is `App\Models\User::canAccessPanel()`** (`app/Models/User.php:74-77`):

```php
public function canAccessPanel(Panel $panel): bool
{
    return $this->is_admin || $this->is_reviewer;
}
```

`User` implements `Filament\Models\Contracts\FilamentUser`. This is the **only** panel-level gate and
the only place the admin authorization rule is expressed. Any authenticated user with
`users.is_admin = 1` **or** `users.is_reviewer = 1` gets into the panel. There is no environment
check, no IP restriction, no 2FA, no role beyond these two booleans. `is_admin` is cast to `bool`
on the model; `is_reviewer` is **not cast**.

Everything below is stated relative to that baseline. "No policy exists" therefore means "open to
every admin **and every reviewer**", not "open to any authenticated user" - an ordinary attendee
never reaches `/admin` at all.

**Per-resource authorization.** Filament v3's `Resource::can()`
(`vendor/filament/filament/src/Resources/Resource.php:195`) calls
`authorize($action, $record ?? $model, static::shouldCheckPolicyExistence())`, and with policy-existence
checking on, **a model with no policy is allowed for everyone who passes the panel gate**. No Filament
resource in this codebase overrides `can*()`, `canAccess()` or `shouldRegisterNavigation()` at the
class level except where noted; the only page-level gate is `DbService`.

Policies registered explicitly in `App\Providers\AuthServiceProvider`: `Machine → MachinePolicy`,
`Printer → PrinterPolicy`, `PrintJob → PrintJobPolicy`, `Staff → StaffPolicy`,
`SumUpReader → SumUpReaderPolicy`, `TseClient → TseClientPolicy`.

Policies picked up by Laravel auto-discovery (`App\Models\… → App\Policies\…Policy`):
`User → UserPolicy`, `Event → EventPolicy`, `App\Models\Fursuit\Fursuit → FursuitPolicy`,
`App\Models\Badge\Badge → BadgePolicy`, `App\Models\FCEA\UserCatchLog → UserCatchLogPolicy`.

| Filament surface | Model | Policy | Effective rule |
|---|---|---|---|
| `UserResource` | `App\Models\User` | `UserPolicy` | every ability `return $user->is_admin;` - admin only |
| `StaffResource` | `App\Models\Staff` | `StaffPolicy` | every ability `is_admin` - admin only. `viewAny` is false for reviewers, so Filament hides the nav entry |
| `EventResource` | `App\Models\Event` | `EventPolicy` | `viewAny`/`view`/`create`/`update`/`delete` → `is_admin`. No `restore`/`forceDelete` defined; Filament treats a missing policy method as **allowed** |
| `MachineResource` | `App\Models\Machine` | `MachinePolicy` | all abilities `is_admin` |
| `PrinterResource` | `App\Domain\Printing\Models\Printer` | `PrinterPolicy` | all seven abilities `is_admin` |
| `PrintJobResource` | `App\Domain\Printing\Models\PrintJob` | `PrintJobPolicy` | all seven abilities `is_admin` |
| `SumUpReaderResource` | `App\Models\SumUpReader` | `SumUpReaderPolicy` | all abilities `is_admin` |
| `TseClientResource` | `App\Domain\Checkout\Models\TseClient` | `TseClientPolicy` | all abilities `is_admin`. Docblock verbatim: `Only admins can view TSE clients (sensitive security equipment).` |
| `BadgeResource` | `App\Models\Badge\Badge` | `BadgePolicy` | `viewAny`/`view` → `is_admin \|\| is_reviewer`; `update` → `is_admin` **only when** `request()->routeIs('filament.*','livewire.*')`, else owner rules; `delete` → `is_admin` or owner-with-conditions; `create` carries the prepaid/order-window logic (admins always true) |
| `FursuitResource` | `App\Models\Fursuit\Fursuit` | `FursuitPolicy` | `viewAny`/`view` → `is_admin \|\| is_reviewer`; `create` → **always `false`**; `update`/`delete`/`restore`/`forceDelete` → `is_admin` |
| `CheckoutResource` | `App\Domain\Checkout\Models\Checkout\Checkout` | **none** | Open to every admin **and reviewer**. Writes are blocked only by the resource's own hard `canCreate/canEdit/canDelete => false`; nothing at the model or gate layer stops a rewrite re-enabling them |
| `PrintBatchResource` | `App\Domain\Printing\Models\PrintBatch` | **none** | Open to every admin **and reviewer**. `canCreate(): false` is the only resource-level override; pause / resume / cancel of a live convention print run are reachable by reviewers |
| `SpecialCodeResource` | `App\Domain\CatchEmAll\Models\SpecialCode` | **none** | Open to every admin **and reviewer**, including create/edit/delete of codes that award Catch-Em-All points |
| `RfidTagsRelationManager` | `App\Models\RfidTag` | **none** | Open, but reachable only through the Staff edit page, which is admin-only via `StaffPolicy` |
| `PrintJobsRelationManager` | `App\Domain\Printing\Models\PrintJob` | not consulted | Relation managers authorize against the **owner** record (`PrintBatch`), which has no policy. `PrintJobPolicy` does not apply here |
| `PdfGenerator`, `BadgePreview`, Dashboard, all three widgets | n/a | none | Visible to admins **and reviewers** |
| `DbService` | n/a | page-level gate | `canAccess()` and `shouldRegisterNavigation()` both `(bool) (auth()->user()?->is_admin)`. Source comment: `Restrict the whole Maintenance group + this page to admins. The panel itself also admits reviewers (User::canAccessPanel), so this extra gate is required.` Locked in by `tests/Feature/DbServiceMaintenancePageTest.php` |

The three domain models under `App\Domain\**\Models\**` would auto-discover to
`App\Domain\Checkout\Policies\CheckoutPolicy`, `App\Domain\Printing\Policies\PrintBatchPolicy` and
`App\Domain\CatchEmAll\Policies\SpecialCodePolicy` - **none of those directories or classes exist**.
`Printer`, `PrintJob` and `TseClient` are also domain models and are protected only because
`AuthServiceProvider` maps them explicitly.

**Other admin-identity gates outside Filament:**

- `App\Providers\HorizonServiceProvider`: `Gate::define('viewHorizon', fn ($user) => $user->is_admin === true);` - strict `=== true`, which works only because `is_admin` is cast to bool.
- `App\Http\Controllers\StatisticsController:25`: `if (! Auth::user()?->is_admin ?? true) { $statistics = $this->removePrivateData($statistics); }`.
- `Gate::allows('create', Badge::class)` in `BadgeController`, `WelcomeController` and `User::getPrepaidBadgesLeft()`.
- There are no named `Gate::define` permission strings anywhere except `viewHorizon`. No roles/permissions package, no permission table, no `hasRole` / `can('some.string')` usage. The entire authorization model is the two boolean columns `users.is_admin` and `users.is_reviewer`.

**POS identity does not intersect with `/admin`.** POS uses separate tables and guards:
`machine` → `App\Models\Machine` (the till), `machine-user` → `App\Models\Staff`, authenticated by a
plaintext 6-digit PIN (`Staff::where('pin_code', $data['code'])`, `MachineUserAuthController.php:50`),
a one-shot setup code (line 56), or an RFID tag (`RfidTag::active()->where('content', …)` plus
`$rfidTag->staff->is_active`, lines 34-43). A `Staff` row has no `is_admin`/`is_reviewer` column and no
link to a `User`. `App\Http\Middleware\HandleInertiaRequests:51` shares
`$request->user('machine-user')?->only(['id','name','is_admin'])` on POS routes - `is_admin` does not
exist on `staff`, so the frontend always receives it as absent. Managing POS staff is done from
`/admin` via `StaffResource`, which is admin-only, so the admin panel is the control plane for POS
identity even though POS identity grants no admin access.

## 4. Modules, in nav order

### 4.1 Events (`EventResource`)

`app/Filament/Resources/EventResource.php`, 161 lines.

**Nav:** group `Events & Registration` / label auto (`Events`) / icon `heroicon-o-calendar-days` /
sort `1` / no navigation badge / no badge colour.

**Model:** `App\Models\Event`. **Route base:** `/admin/events`. **Pages:** index only -
`Pages\ManageEvents::route('/')`, a `ManageRecords` page (list plus modal create/edit). No dedicated
create/edit/view routes.

**Guards:** no `canX` overrides, no `modifyQueryUsing`, no global scopes. `EventPolicy` gates
everything on `is_admin`. Events are **not** soft-deleted (`Event` has no `SoftDeletes`) and
`public $timestamps = false`.

**Event state is computed, not stored.** There is no `state` column and no state column, filter or
badge anywhere in this resource. `Event::state()` is an appended `Attribute` returning
`App\Enum\EventStateEnum`:

```php
if ($this->ends_at < now())                                    return EventStateEnum::CLOSED;
if ($this->order_starts_at && $this->order_starts_at > now())  return EventStateEnum::CLOSED;
if ($this->order_ends_at   && $this->order_ends_at   < now())  return EventStateEnum::CLOSED;
return EventStateEnum::OPEN;
```

The only levers the admin UI has over event state are the three date fields; the resulting state is
never displayed here.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | auto `Name` | TextColumn | yes | no | no | no | `->numeric()` applied to a **string** name |
| 2 | `badge_class` | `Badge Class` | TextColumn | yes | no | yes | **yes** | `->placeholder('Not set')` |
| 3 | `starts_at` | auto `Starts at` | TextColumn | yes | no | no | no | `->date()` (default `M j, Y`), description `fn (Event $record) => $record->starts_at?->diffForHumans()` |
| 4 | `ends_at` | auto `Ends at` | TextColumn | yes | no | no | no | `->date()`, description `$record->ends_at?->diffForHumans()` |
| 5 | `mass_printed_at` | auto `Mass printed at` | TextColumn | yes | no | no | no | `->dateTime('d.m.Y H:i')`, description `$record->mass_printed_at?->diffForHumans()` |
| 6 | `order_starts_at` | `Order Start` | TextColumn | yes | no | no | no | `->dateTime('d.m.Y H:i')`, description `$record->order_starts_at?->diffForHumans()` |
| 7 | `order_ends_at` | `Order End` | TextColumn | yes | no | no | no | `->dateTime('d.m.Y H:i')`, description `$record->order_ends_at?->diffForHumans()` |
| 8 | `catch_em_all_enabled` | `Catch-Em-All` | IconColumn | no | no | yes | **yes** | `->boolean()` |
| 9 | `archival_notice` | `Archival Notice` | TextColumn | no | no | yes | **yes** | `->limit(50)`, `->tooltip(fn (Event $record) => $record->archival_notice)`, `->placeholder('None')` |

**Filters:** none (`->filters([ // ])`).

**Row actions:** `EditAction` (opens the form in a modal, Filament default copy);
`DeleteAction` (Filament default delete copy - heading `Delete :label`, description
`Are you sure you would like to do this?`, submit `Delete`). Hard delete.

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - Filament default copy
(trigger `Delete selected`, heading `Delete selected :label`, submit `Delete`).

**Header actions:** `ManageEvents::getHeaderActions()` → `Actions\CreateAction::make()`, default copy
(`New event`). No `mutateFormDataBeforeCreate` / `mutateFormDataBeforeSave`, no redirects, no widgets.

**Form sections.** The schema uses `Filament\Forms\Components\Group` wrappers with `->label(...)`.
`Group` is an **invisible layout component in Filament v3 - these labels render nowhere.** Ordered:

1. `TextInput::make('name')` - required, `columnSpanFull()`.
2. `Select::make('badge_class')` (line 31) - label `Badge Class`, helperText `PHP class used for badge generation`, `columnSpanFull()`, not required. Options hardcoded: `'EF28_Badge' => 'EF28 Badge'`, `'EF29_Badge' => 'EF29 Badge'`, `'EF30_Badge' => 'EF30 Badge'`.
3. Group labelled `Event Dates` (line 40), `->columns()` (2), `columnSpanFull()`:
   - `DatePicker::make('starts_at')` - required, **date only**, no helper text.
   - `DatePicker::make('ends_at')` - required, **date only**, no helper text.
4. Group labelled `Order Management` (line 46), `->columns()` (2), `columnSpanFull()`:
   - `DateTimePicker::make('order_starts_at')` - label `Order Window Start`, required, helperText `When badge orders can start`.
   - `DateTimePicker::make('order_ends_at')` - label `Order Window End`, helperText `When badge orders must end`, required.
   - `DateTimePicker::make('mass_printed_at')` (line 55) - label `Mass Print Date`, helperText `When the badges were mass printed, if applicable`, **required**.
5. Group labelled `Financial Tracking` (line 61), `columnSpanFull()`:
   - `TextInput::make('cost')` - label `Printing Cost (€)`, helperText `Total printing cost in euros that we need to cover for this event`, `->numeric()`, `->step(0.01)`, `->suffix('€')`, `->placeholder('1914.95')`, not required.
6. Group labelled `Gallery Settings` (line 71), `->columns()` (2), `columnSpanFull()`:
   - `Toggle::make('catch_em_all_enabled')` - label `Catch-Em-All Enabled`, helperText `Enable catch-em-all functionality for this event`, `->default(true)`.
   - nested Group (line 76), `->columns()` (2), `columnSpanFull()`:
     - `DateTimePicker::make('catch_em_all_start')` - label `Catch-Em-All Start`, helperText `When the catch-em-all game should start (leave empty to start with event)`, `->nullable()`.
     - `DateTimePicker::make('catch_em_all_end')` - label `Catch-Em-All End`, helperText `When the catch-em-all game should end (leave empty to end with event)`, `->nullable()` (written as `->helperText(text: '...')`, a named argument).
   - `Textarea::make('archival_notice')` (line 86) - label `Archival Notice`, helperText `Notice to display for archival/historical events`, `->rows(3)`, `columnSpanFull()`.

Exhaustive list of date fields the form exposes:

| field | component | granularity | required |
|---|---|---|---|
| `starts_at` | `DatePicker` | date only | yes |
| `ends_at` | `DatePicker` | date only | yes |
| `order_starts_at` | `DateTimePicker` | date + time | yes |
| `order_ends_at` | `DateTimePicker` | date + time | yes |
| `mass_printed_at` | `DateTimePicker` | date + time | **yes** |
| `catch_em_all_start` | `DateTimePicker` | date + time | no (`->nullable()`) |
| `catch_em_all_end` | `DateTimePicker` | date + time | no (`->nullable()`) |

**Infolist:** none. **Relation managers:** none. **Custom blade views:** none.
**Table config:** `->defaultSort('starts_at', 'desc')`. No poll, no pagination override, no
`selectCurrentPageOnly`, no summarizers.
**Notifications:** none custom; only Filament's stock save/delete toasts.

---

### 4.2 Badges (`BadgeResource`)

`app/Filament/Resources/BadgeResource.php`, 583 lines.

**Nav:** group `Events & Registration` / label default (`Badges`) / icon `heroicon-o-identification` /
sort `2`. Navigation badge, verbatim:

```php
public static function getNavigationBadge(): ?string
{
    $eventId = static::getSelectedEventId();
    if (! $eventId) {
        return null;
    }

    return (string) Badge::whereHas('fursuit', fn ($q) => $q->where('event_id', $eventId))->count();
}
```

No `getNavigationBadgeColor()`, so the chip takes Filament's default primary. The `null` return is
unreachable (see §2.1).

**Model:** `App\Models\Badge\Badge`. **Route base:** `/admin/badges`. **Pages:** index (`/`),
create (`/create`), edit (`/{record}/edit`). No view page. `getRelations()` returns `[]`.

**Guards:** no resource-level `canX` overrides; authorization is `BadgePolicy` (see §3). `Badge` uses
`SoftDeletes`, but the resource adds **no** `TrashedFilter` and does **not** call
`->withoutGlobalScopes([SoftDeletingScope::class])` - soft-deleted badges are simply invisible in admin.

`modifyQueryUsing`, verbatim:

```php
->modifyQueryUsing(function ($query) {
    $query = static::applyEventFilter($query, 'fursuit');

    // Add joins for attendee_id sorting but select only badges columns to avoid conflicts
    return $query->leftJoin('fursuits', 'badges.fursuit_id', '=', 'fursuits.id')
        ->leftJoin('event_users', function ($join) {
            $join->on('fursuits.user_id', '=', 'event_users.user_id')
                ->on('fursuits.event_id', '=', 'event_users.event_id');
        })
        ->select('badges.*')
        ->addSelect('event_users.attendee_id as sort_attendee_id');
})
```

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `fursuit.image` | `Image` | ImageColumn | no | no | no | no | `->disk('s3')->visibility('private')->circular()->size(40)->defaultImageUrl(url('/images/placeholder.png'))->checkFileExistence(false)` |
| 2 | `fursuit.name` | `Fursuit` | TextColumn | **yes** | **yes** | no | no | `->url(fn (Badge $record): string => route('filament.admin.resources.fursuits.view', ['record' => $record->fursuit->id]))` (line 248) |
| 3 | `fursuit.species.name` | `Species` | TextColumn | no | no | **yes** | no | `->color('gray')` |
| 4 | `fursuit.user.name` | `Owner` | TextColumn | no | **yes** | **yes** | no | `->url(fn (Badge $record): string => '/admin/users?tableSearch='.urlencode($record->fursuit->user->name))` (line 261) - hardcoded path, not `route()` |
| 5 | `custom_id` | `Badge ID` | TextColumn | no | **yes** | **yes** | no | `->copyable()`, `toggleable(isToggledHiddenByDefault: false)` |
| 6 | `sort_attendee_id` | `Attendee ID` | TextColumn | **yes (custom)** | no | **yes** | no | `->formatStateUsing(fn ($state) => $state ?? 'N/A')`; sort query `$query->orderByRaw("CAST(sort_attendee_id AS UNSIGNED) $direction")` (line 275) |
| 7 | `print_jobs_count` | `Print Jobs` | TextColumn `->badge()` | no | no | no | no | `->alignCenter()`; url → `route('filament.admin.resources.print-jobs.index', ['tableFilters[printable_id][value]' => $record->id, 'tableFilters[printable_type][value]' => get_class($record)])` (line 283); state and colour logic below |
| 8 | `status_fulfillment` | `Fulfillment` | TextColumn `->badge()` | no | no | no | no | `formatStateUsing`: `pending`→`Pending`, `processing`→`Processing`, `ready_for_pickup`→`Ready for Pickup`, `picked_up`→`Picked Up`, default `ucfirst($state)`. No explicit `->color()`, so the badge colour comes from the state class's own colour if any, otherwise primary |
| 9 | `status_payment` | `Payment` | TextColumn `->badge()` | no | no | no | no | `formatStateUsing(fn (string $state) => ucfirst($state))` |
| 10 | `extra_copy` | `Extra Copy` | IconColumn `->boolean()` | no | no | **yes** | **yes** | `->trueIcon('heroicon-o-document-plus')->falseIcon(null)` |
| 11 | `total` | `Total` | TextColumn | no | no | **yes** | **yes** | `->money('EUR')->alignEnd()` (line 354) - **no `divideBy: 100`**, see landmines |
| 12 | `created_at` | `Created` | TextColumn | no | no | **yes** | **yes** | `->dateTime('M j, Y')` |
| 13 | `printed_at` | `Printed At` | TextColumn | no | no | **yes** | **yes** | `->dateTime('M j, Y H:i')->placeholder('Not printed')` |
| 14 | `picked_up_at` | `Picked Up` | TextColumn | no | no | **yes** | **yes** | `->dateTime('M j, Y H:i')->placeholder('Not picked up')` |

Column 7 state and colour logic, verbatim:

```php
->getStateUsing(function (Badge $record): string {
    $jobs = $record->printJobs()->get();
    $total = $jobs->count();
    $pending = $jobs->whereIn('status', ['pending', 'queued', 'printing', 'retrying'])->count();
    $failed = $jobs->where('status', 'failed')->count();
    $printed = $jobs->where('status', 'printed')->count();

    if ($total === 0) { return '0'; }
    if ($failed > 0) { return "{$total} ({$failed} failed)"; }
    if ($pending > 0) { return "{$total} ({$pending} pending)"; }

    return "{$total}";
})
->color(function (Badge $record): string {
    $jobs = $record->printJobs()->get();
    if ($jobs->count() === 0) { return 'gray'; }

    $hasFailed = $jobs->where('status', 'failed')->count() > 0;
    $hasPending = $jobs->whereIn('status', ['pending', 'queued', 'printing', 'retrying'])->count() > 0;

    if ($hasFailed) { return 'warning'; }
    if ($hasPending) { return 'info'; }

    return 'success';
})
```

`$printed` is computed and never used. `printJobs()->get()` executes twice per row, with no eager load.

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `status_fulfillment` | SelectFilter | `Fulfillment Status` | yes | none, placeholder `All Statuses` | options `pending`→`Pending`, `processing`→`Processing`, `ready_for_pickup`→`Ready for Pickup`, `picked_up`→`Picked Up`; default `whereIn('status_fulfillment', …)` |
| 2 | `status_payment` | SelectFilter | `Payment Status` | yes | none, placeholder `All Payments` | options `unpaid`→`Unpaid`, `paid`→`Paid`; default `whereIn('status_payment', …)` |
| 3 | `is_free_badge` | TernaryFilter | `Free Badge` | n/a | none, placeholder `All Badges` | `trueLabel('Free Badges Only')`, `falseLabel('Paid Badges Only')` |
| 4 | `attendee_id_range` | custom `Filter` with form | no label set, so Filament renders `Attendee id range` | n/a | none | see below |

Filter 4 form fields: `from_attendee_id` (TextInput, label `From Attendee ID`, `->numeric()`,
placeholder `e.g., 1`), `to_attendee_id` (TextInput, label `To Attendee ID`, `->numeric()`,
placeholder `e.g., 1000`). Query verbatim:

```php
->query(function ($query, array $data) {
    return $query
        ->when($data['from_attendee_id'], function ($query, $fromAttendeeId) {
            return $query->whereHas('fursuit.user.eventUsers', function ($q) use ($fromAttendeeId) {
                $q->where('event_id', static::getSelectedEventId())
                    ->whereRaw('CAST(attendee_id AS UNSIGNED) >= ?', [$fromAttendeeId]);
            });
        })
        ->when($data['to_attendee_id'], function ($query, $toAttendeeId) {
            return $query->whereHas('fursuit.user.eventUsers', function ($q) use ($toAttendeeId) {
                $q->where('event_id', static::getSelectedEventId())
                    ->whereRaw('CAST(attendee_id AS UNSIGNED) <= ?', [$toAttendeeId]);
            });
        });
})
```

Indicators: `'From attendee #'.$data['from_attendee_id']` and `'To attendee #'.$data['to_attendee_id']`.

**Row actions:**

| name | label | icon | colour | visibility | modal | form | does |
|---|---|---|---|---|---|---|---|
| `EditAction` (default) | `Edit` | `heroicon-m-pencil-square` | default | policy `update` | none | none | links to `/admin/badges/{id}/edit` |
| `printBadge` | `Print Badge` | `heroicon-o-printer` | `warning` | always | bare `requiresConfirmation()` - heading `Print Badge` (the action label), description `Are you sure you would like to do this?`, submit `Confirm`, cancel `Cancel`, icon `heroicon-o-exclamation-triangle`, centered, medium width | none | `return static::printBadge($record);` |

**Bulk actions:**

| name | label | icon | colour | visibility | modal | form |
|---|---|---|---|---|---|---|
| `printBadgeBulk` (line 453) | `Print Badges` | `heroicon-o-printer` | `warning` | always | `requiresConfirmation()`, `modalHeading('Print Selected Badges')`, `modalDescription('This will print all selected badges to the specified printer.')`, submit `Confirm` | `Select::make('printer_id')` label `Select Printer`, `->required()`, helper text `Select a specific printer for all selected badges.`, options `Printer::where('type', PrintJobTypeEnum::Badge)->where('is_active', true)->pluck('name','id')` (line 461), evaluated once at table-build time |

Body, verbatim:

```php
->action(function (Collection $records, array $data) {
    $printerId = $data['printer_id'];
    // sort by attendee id numerically
    $sortedRecords = $records->sortBy(fn (Badge $badge) => (int) $badge->sort_attendee_id);

    // PrintBatch::build() does the ordering itself, from
    // one definition shared with every other caller.
    BadgePrintQueue::queue(
        badges: $sortedRecords,
        printer: Printer::find($printerId),
        createdById: auth()->id(),
    );

    return true;
})
```

**`printBadgeBulk` / `Print Badges` is the authoritative name and label of the batch entry point.**
`PrintBatchResource`'s class docblock calls it "the 'Build print batch' bulk action on badges"; that
docblock is stale source text and is reproduced verbatim in §4.8 only because it is verbatim, not
because it names anything that exists.

There is no `DeleteBulkAction`, no `ExportBulkAction`, no `DissociateBulkAction`. Filament's default
bulk delete is absent because `bulkActions()` is passed an explicit array.

**Header / page actions:**

- `ListBadges` (`BadgeResource/Pages/ListBadges.php`, 19 lines): `[Actions\CreateAction::make()]`, label `New badge`. No `getTabs()`, no `getHeaderWidgets()`, no `getTableQuery()` override.
- `CreateBadge` (11 lines): empty apart from `protected static string $resource`. No `mutateFormDataBeforeCreate`, no `getRedirectUrl`, no `handleRecordCreation`.
- `EditBadge` (19 lines): `[Actions\DeleteAction::make()]` - Filament default delete copy (heading `Delete :label`, description `Are you sure you would like to do this?`, submit `Delete`, success toast `Deleted`). No `mutateFormDataBeforeSave`, no `mutateFormDataBeforeFill`, no `getRedirectUrl`, no `afterSave`. Raw form state is written straight to the model.

**Form sections** (`->columns(1)` at root; five sections):

1. **`Badge Information`** - description `Basic badge details and associated fursuit`, icon `heroicon-o-identification`, not collapsed.
   - Grid(2): `fursuit_id` (line 53) - label `Fursuit`, `Select`, **disabled**, `->relationship('fursuit','name')`, `->required()`, helper text `The fursuit this badge belongs to (cannot be changed)`, columnSpan 1. `custom_id` (line 61) - label `Badge ID`, `TextInput`, **disabled**, helper text `Unique badge identifier (auto-generated)`, columnSpan 1.
   - Grid(2): `species_name` - label `Species`, `TextInput`, **disabled**, `->dehydrated(false)`, `->formatStateUsing(fn ($record) => $record?->fursuit?->species?->name)`, helper text `The fursuit species`. `owner_name` - label `Owner`, `TextInput`, **disabled**, `->dehydrated(false)`, `->formatStateUsing(fn ($record) => $record?->fursuit?->user?->name)`, helper text `The fursuit owner`.
2. **`Status Management`** - description `Current fulfillment and payment status of the badge`, icon `heroicon-o-flag`, not collapsed.
   - Grid(2): `status_fulfillment` (line 94) - label `Fulfillment Status`, `Select`, enabled, `->required()`, helper text `Current fulfillment stage of the badge`. Options from `BadgeFulfillmentStatusState::getStateMapping()->keys()->mapWithKeys(...)`: `pending`→`Pending`, `processing`→`Processing`, `ready_for_pickup`→`Ready for Pickup`, `picked_up`→`Picked Up`, default `ucfirst($key)`. `status_payment` - label `Payment Status`, `Select`, enabled, `->required()`, helper text `Current payment status`. Options `BadgePaymentStatusState::getStateMapping()->keys()->mapWithKeys(fn ($key) => [$key => ucfirst($key)])`.
3. **`Pricing Details`** - description `Badge pricing breakdown and financial information`, icon `heroicon-o-banknotes`, not collapsed.
   - Grid(3): `total` (line 129) - label `Total (€)`, `TextInput`, **enabled**, `->prefix('€')`, `->numeric()`, `->step(0.01)`, `->formatStateUsing(fn ($state) => number_format($state / 100, 2))`, helper text `Total amount in euros`. `subtotal` (line 137) - label `Subtotal (€)`, **disabled**, `->prefix('€')`, same `formatStateUsing`, helper text `Amount before tax`. `tax` (line 145) - label `Tax (€)`, **disabled**, `->prefix('€')`, same `formatStateUsing`, helper text `Tax amount`.
   - Grid(2): `is_free_badge` - label `Free Badge`, `Toggle`, **disabled**, helper text `Whether this badge was provided for free`, `->inline(false)`. `extra_copy` - label `Extra Copy`, `Toggle`, **disabled**, helper text `Whether this is an additional copy of another badge`, `->inline(false)`.
4. **`Badge Features & Upgrades`** - description `Special features and upgrade options applied to this badge`, icon `heroicon-o-star`, `->collapsed()`.
   - Grid(2): `dual_side_print` - label `Double-Sided Print`, `Toggle`, **disabled**, helper text `Whether the badge is printed on both sides`, `->inline(false)`. `apply_late_fee` - label `Late Fee Applied`, `Toggle`, **disabled**, helper text `Whether a late fee was applied to this badge`, `->inline(false)`.
5. **`Timeline & Processing`** - description `Key dates and processing timestamps`, icon `heroicon-o-clock`, `->collapsed()`.
   - Grid(3): `created_at` - label `Created At`, `DateTimePicker`, **disabled**, helper text `When the badge was initially created`. `printed_at` - label `Printed At`, **disabled**, helper text `When the badge was printed`. `picked_up_at` - label `Picked Up At`, **disabled**, helper text `When the badge was collected by the owner`.

No reactive / `live()` / `visible(fn …)` logic anywhere in the form. No validation beyond `required()`
on `fursuit_id`, `status_fulfillment`, `status_payment` and `numeric()` on `total`.

**Infolist:** none (no `infolist()` method, no view page). **Relation managers:** none.
**Custom blade views:** none owned by this resource.

**Table config:** `->selectCurrentPageOnly()` (line 487) - "select all" never crosses pages;
`->paginationPageOptions([10, 25, 50, 100])` (no `'all'`); `->defaultSort('sort_attendee_id', 'asc')`
(sorts on the joined alias, not a real column); `->poll('5s')` (line 490). No `->striped()`, no
`->deferLoading()`, no `->groups()`, no summarizers.

**Notifications:** **none.** There is no `Notification::make()` anywhere in `BadgeResource` or its
three page classes. Without `->successNotificationTitle()`, a custom action gives no feedback.
Anything the rewrite adds here is new behaviour, not parity.

**Static helpers that must survive the rewrite:**

```php
public static function printBadge(Badge $badge, $mass = 0, ?int $printerId = null): Badge     // line 493
public static function printBadgeWithPrinter(Badge $badge, int $printerId, int $delaySeconds = 0): Badge  // line 526
```

Callers, grepped across the whole repo:

| Caller | Which helper | Note |
|---|---|---|
| `app/Filament/Resources/BadgeResource.php:449` (own `printBadge` row action) | `printBadge` | the only production call site |
| `app/Filament/Resources/CheckoutResource/RelationManagers/ItemsRelationManager.php:63` | neither - uses `BadgeResource::getUrl('edit', ['record' => $record->payable])` | the class is referenced for URL generation, so removing the resource breaks that link |
| `tests/Feature/Printing/BatchPrintJobOrderingTest.php:256` | neither - comment only: `// Simulate the BadgeResource bulk action` | the test re-implements the bulk action inline |

`printBadge()` has exactly one caller (its own row action); `printBadgeWithPrinter()` has **zero**
callers anywhere in the repo. POS printing goes through
`App\Domain\Printing\Services\BadgePrintQueue` directly.

`printBadge()` body: logs `printBadge called` with `badge_id`, `before_fulfillment`,
`before_payment`, `can_transition`; transitions to `Processing` if allowed; `refresh()`; logs
`printBadge after transition`; calls `BadgePrintQueue::queue(badges: collect([$badge]), printer: …, createdById: auth()->id())`;
returns `$badge->fresh()`. The `$mass = 0` parameter is accepted and never used.

`printBadgeWithPrinter()` body: transitions to `Processing` if allowed; picks a renderer by
`$badge->fursuit->event->badge_class ?? 'EF30_Badge'` (`EF30_Badge` / `EF29_Badge` / `EF28_Badge`,
default `EF30_Badge`); renders `$printer->getPdf($badge)`; `Storage::put('badges/'.$badge->id.'.pdf', $pdfContent)`
(default disk, **not** s3); creates a `printJobs()` row with `printer_id`, `type = PrintJobTypeEnum::Badge`,
`status = PrintJobStatusEnum::Pending`, `file`, `queued_at = now()`, `priority = 1`; logs
`Badge print job created with specific printer`. It bypasses `BadgePrintQueue` / `PrintBatch` entirely:
no batch, no frozen ordering, no pause-on-failure, no printing lock. `$delaySeconds` is logged and
otherwise unused.

---

### 4.3 Fursuits (`FursuitResource`)

`app/Filament/Resources/FursuitResource.php`, 196 lines.

**Nav:** group `Events & Registration` / label auto (`Fursuits`) / icon `heroicon-o-user-circle` /
sort `3`. Navigation badge and colour, verbatim:

```php
public static function getNavigationBadge(): ?string
{
    $eventId = static::getSelectedEventId();
    if (! $eventId) {
        return null;
    }

    return (string) Fursuit::where('event_id', $eventId)->count();
}

public static function getNavigationBadgeColor(): ?string
{
    $eventId = static::getSelectedEventId();
    if (! $eventId) {
        return null;
    }

    $pendingCount = Fursuit::where('event_id', $eventId)
        ->where('status', 'pending')
        ->count();

    return $pendingCount > 0 ? 'warning' : 'success';
}
```

The badge counts **all** fursuits of the selected event; the colour is driven by the **pending**
count. Two different numbers behind one chip. Both `null` branches are unreachable (see §2.1).

**Model:** `App\Models\Fursuit\Fursuit`. **Route base:** `/admin/fursuits`. **Pages:** index
(`ListFursuits`), create (`CreateFursuit`), view (`ViewFursuit`, `/{record}`), edit (`EditFursuit`,
`/{record}/edit`) - all four exist.

**Guards:** no `canX` overrides; `FursuitPolicy` applies (see §3), including
`create(): return false;`. `modifyQueryUsing(fn ($query) => static::applyEventFilter($query))`.
`SoftDeletes` on the model with no trashed filter exposed, so soft-deleted fursuits are invisible and
unrecoverable from this UI. The view / approve / reject flow ignores the event filter entirely.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `user.name` | `By` | TextColumn | yes | no | no | no | `->numeric()` applied to a **name string** (line 142) |
| 2 | `species.name` | auto `Species.name` | TextColumn | yes | no | no | no | `->numeric()` on a string (line 145) |
| 3 | `status` | auto `Status` | TextColumn badge | no | **yes** | no | no | `->badge()`, `->color(fn (Fursuit $fursuit) => $fursuit->status->color())` → Pending `warning`, Approved `success`, Rejected `danger`; `->formatStateUsing(fn ($state) => ucfirst($state))` (line 147) |
| 4 | `name` | auto `Name` | TextColumn | no | **yes** | no | no | plain |
| 5 | `image` | auto `Image` | ImageColumn | no | no | no | no | `->disk('s3')`, `->visibility('private')`, `->circular()`, `->checkFileExistence(false)` (line 154) |
| 6 | `published` | auto `Published` | IconColumn | no | no | no | no | `->boolean()` |
| 7 | `catch_em_all` | auto `Catch em all` | IconColumn | no | no | no | no | `->boolean()` |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `status` | SelectFilter | auto `Status` | no | **`'pending'`** (line 172) | options `['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']`; default Filament `where('status', $value)` |

**Row actions:** `Tables\Actions\ViewAction::make()` only (label `View`, icon `heroicon-m-eye`, no
confirm). `EditAction` is present but **commented out** (`// Tables\Actions\EditAction::make(),`, line 176).

**Bulk actions:** none declared.

**Header actions:** `ListFursuits` → `Actions\CreateAction::make()` (default copy `New fursuit`).
Hidden in practice because `FursuitPolicy::create()` returns `false`.

**Form sections:** none - a flat schema on the create/edit page:

| field | label | component | disabled | required | validation | notes |
|---|---|---|---|---|---|---|
| `user_id` | auto `User` | Select `->relationship('user','name')` | no | yes | required | - |
| `species_id` | auto `Species` | Select `->relationship('species','name')` | no | yes | required | - |
| `event_id` (line 114) | auto `Event` | **TextInput** `->numeric()` | no | yes | required, numeric | free numeric input, not a relation select |
| `status` (line 117) | auto `Status` | **TextInput** `->maxLength(255)` | no | yes | required, max 255 | writes the raw state string |
| `name` | auto `Name` | TextInput `->maxLength(255)` | no | yes | required, max 255 | - |
| `image` (line 123) | auto `Image` | FileUpload `->image()` | no | yes | required, image | **no `->disk()`** - writes to the default filesystem disk while the table and infolist read from `s3` |
| `published` | auto `Published` | Toggle | no | yes | required | - |
| `catch_em_all` | auto `Catch em all` | Toggle | no | yes | required | - |
| `approved_at` | auto `Approved at` | DateTimePicker | no | no | - | hand-editable, can contradict `status` |
| `rejected_at` | auto `Rejected at` | DateTimePicker | no | no | - | hand-editable |

**Infolist** (resource-level, rendered by `ViewFursuit`):

- Root: `Group::make([...])->columns(12)->columnSpanFull()` containing two nested groups.
- Left `Group`, `columnSpan(3)`: `ImageEntry::make('image')` (line 63) → `->disk('s3')`, `->height('100%')`, `->width('100%')`, `->visibility('private')`, `->alignCenter()`.
- Right `Group`, `columnSpan(9)`:
  - `TextEntry::make('name')` label `Name`, hint `Name of the fursuit on the Badge`, helperText `Should not contain profanities.`, size `Large`, weight `Bold`
  - `TextEntry::make('species.name')` label `Species`, hint `Name of the species on the Badge`, helperText `Should not contain profanities.`, size `Large`, weight `Bold`
  - nested `Group::make([...])->columns()` (2 cols):
    - `IconEntry::make('published')` size `Large`, `->boolean()`, hint `Publish your fursuit in our online gallery for everyone to see.`
    - `IconEntry::make('catch_em_all')` size `Large`, `->boolean()`, hint `Participate in the convention game to be catchable by other attendees.`
  - `TextEntry::make('status')` (line 94) `->badge()`, hint `Current status of the fursuit.`, `->color(fn (Fursuit $fursuit) => $fursuit->status->color())`, `->formatStateUsing(fn ($state) => ucfirst($state))`

Nothing else is on the view page: no user, no event, no timestamps, no badges, no rejection reason,
no indication of who holds a claim.

**Table config:** no `defaultSort`, no poll, no pagination override (Filament default 10/25/50/all),
no `selectCurrentPageOnly`, no summarizers. **Notifications:** none at resource level.
**Custom blade views:** none.

#### 4.3.1 `ViewFursuit` - the approval workflow

`app/Filament/Resources/FursuitResource/Pages/ViewFursuit.php`, 192 lines.
Type `Filament\Resources\Pages\ViewRecord`. Route `/admin/fursuits/{record}`.

**`public $defaultAction = 'Claim';`** (line 23) - Filament auto-mounts the action named `Claim` on
every page load. Visiting a pending fursuit therefore claims it automatically, without any user gesture.

Shared local array used by the Reject action (line 27, verbatim, in order - a **list**, so keys are `0..7`):

```php
$errorOptions = [
    'The submission shows a human. We can only accept badges created for fursuits.',
    'The submission is explicit and does not follow our guidelines.',
    'The submission is of low quality and does not meet our guidelines.',
    'The submission is a not a photo. We only accept photos, we do not accept illustrations or other digital art as fursuit images.',
    'The submission shows an animal. We do not allow images of real animals, only fursuits.',
    'The submission is AI generated and does not show a real fursuit.',
    'The name of the fursuit is not appropriate.',
    'The species of the fursuit is not appropriate.',
];
```

**Header actions, in order:**

**1. `Claim`** - label `Claim` (auto), no icon, colour `primary`, no confirmation.
- Visible: `fn (Fursuit $record) => $record->status->canTransitionTo(Approved::$name, auth()->user()) && ! $record->isClaimedBySelf(auth()->user())`
- Action:
```php
if ($record->isClaimed() && $record->isClaimedBySelf(auth()->user()) === false) {
    return $this->toNextFursuit($record);
}
$record->claim(auth()->user());
$record->refresh();
```
- `Fursuit::claim()` writes cache key `fursuit:{id}:claim` = `auth()->user()->id` with a **5 minute TTL** (`cache()->put(..., now()->addMinutes(5))`); returns `false` if already claimed. Cache driver is `database`.
- Is the page's `$defaultAction`, so it fires on mount.

**2. `Unclaim`** - label `Unclaim` (auto), colour `danger`, no confirmation.
- Visible: `fn (Fursuit $record) => $record->status->canTransitionTo(Approved::$name, auth()->user()) && $record->isClaimedBySelf(auth()->user())`
- Action: `$record->unclaim(auth()->user()); $record->refresh();`
- `Fursuit::unclaim()` is declared with **zero** parameters (`public function unclaim(): bool`) but is called with one. Legal PHP, but it means the claim can be dropped by anyone, with no ownership check.

**3. `Approve`** - label `Approve` (auto), icon `heroicon-o-check-circle`, colour `success`, bare
`->requiresConfirmation()`: heading `Approve` (the action label), description
`Are you sure you would like to do this?`, submit `Confirm`, cancel `Cancel`.
- Visible: `fn (Fursuit $record) => $record->status->canTransitionTo(Approved::class, auth()->user()) && $record->isClaimedBySelf(auth()->user())`
- Action:
```php
if ($record === null) { return; }                       // unreachable, parameter is typed Fursuit
if ($record->isClaimed() === false) {
    Log::error('Fursuit is not claimed, but user tried to approve it.', ['fursuit' => $record]);
    return;                                             // silent: no notification to the operator
}
$record->status->transitionTo(Approved::class, auth()->user());
$nextFursuit = Fursuit::where('status', 'pending')->first();
if ($nextFursuit) {
    return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
}
return redirect()->route('filament.admin.resources.fursuits.index');
```
- No Filament notification on success; the redirect is the only feedback.

**4. `Reject`** - label `Reject` (auto), icon `heroicon-o-x-circle`, colour `danger`, bare
`->requiresConfirmation()`: heading `Reject`, description `Are you sure you would like to do this?`,
submit `Confirm`.
- Visible: `fn (Fursuit $record) => $record->status->canTransitionTo(Rejected::class, auth()->user(), '') && $record->isClaimedBySelf(auth()->user())`
- Form inside the action:
  - `Select::make('reason')` (line 89) - `->live()`, options = `$errorOptions` (integer-keyed list, so the stored value is `0`–`7`), `->afterStateUpdated(fn (Set $set, ?string $state) => $set('custom_reason', $errorOptions[$state]))` (line 88)
  - `Textarea::make('custom_reason')` - label `Reason Sent to the User!`, `->required()`
- Action:
```php
if ($record->isClaimed() === false) {
    Log::error('Fursuit is not claimed, but user tried to reject it.', ['fursuit' => $record]);
    return;
}
$record->status->transitionTo(Rejected::class, auth()->user(), $data['custom_reason']);
$record->save();
$nextFursuit = Fursuit::where('status', 'pending')->first();
if ($nextFursuit) {
    return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
}
return redirect()->route('filament.admin.resources.fursuits.index');
```
- Only `custom_reason` is persisted and sent; the `reason` select value is discarded after prefilling the textarea.

**5. `Approve Rejected`** (line 111) - action name `Approve Rejected`, label `Approve (Rejected)`,
icon `heroicon-o-check-circle`, colour `success`.
- Visible: `fn (Fursuit $record) => $record->status instanceof Rejected`
- `->requiresConfirmation()` with custom copy, verbatim: modalHeading `Approve Rejected Fursuit` (line 117), modalDescription `This will send an apology email to the user and approve the fursuit.`, modalSubmitActionLabel `Yes, approve it`
- Action: `$record->status->transitionTo(Approved::class, auth()->user());` → runs `RejectedToApproved` (always notifies via `FursuitRejectionReversedNotification`), then:
```php
Notification::make()
    ->title('Rejected fursuit approved successfully')
    ->success()
    ->send();
```
- Requires **no claim**, unlike Approve / Reject.

**6. `Send Notification`** (line 128) - action name and label `Send Notification`, icon
`heroicon-o-envelope`, colour `info`, **no confirmation, no visibility predicate (always visible)**.
- Form inside the action:
  - `Select::make('notification_type')` - label `Notification Type`, `->required()`, options `'approved' => 'Approval Notification'`, `'rejected' => 'Rejection Notification'`
  - `Textarea::make('rejection_reason')` - label `Rejection Reason (Required for Rejection)`, `->visible(fn ($get) => $get('notification_type') === 'rejected')`, `->required(fn ($get) => $get('notification_type') === 'rejected')`. The Select is **not `->live()`**, so this reactive visibility only re-evaluates on the next form round-trip.
- Action:
```php
if ($data['notification_type'] === 'approved') {
    $record->user->notify(new FursuitApprovedNotification($record));
    $message = 'Approval notification sent successfully';
} else {
    $reason = $data['rejection_reason'] ?? 'No reason provided';
    $record->user->notify(new FursuitRejectedNotification($record, $reason));
    $message = 'Rejection notification sent successfully';
}

Notification::make()->title($message)->success()->send();
```
- Notification titles verbatim, both `success`, no body: `Approval notification sent successfully`, `Rejection notification sent successfully`. Fallback reason string verbatim: `No reason provided`.
- Sends the email without changing state and without checking the current state.

**7. `Next Fursuit`** - label `Next Fursuit` (auto), icon `heroicon-o-arrow-right`, colour `primary`,
always visible, no confirmation. Action: `return $this->toNextFursuit($record);`

**Private helper `toNextFursuit(Fursuit $record)`** (line 170, verbatim):

```php
$tries = 0;
$maxTries = 3;
$excludedIds = [$record->id];
do {
    $nextFursuit = Fursuit::where('status', 'pending')
        ->whereNotIn('id', $excludedIds)
        ->first();
    if ($nextFursuit) {
        $excludedIds[] = $nextFursuit->id;
    }
    $tries++;
} while ($nextFursuit && $nextFursuit->isClaimed() && $tries < $maxTries);

if ($nextFursuit) {
    return redirect()->route('filament.admin.resources.fursuits.view', $nextFursuit);
}
return redirect()->route('filament.admin.resources.fursuits.index');
```

Redirects to the last candidate found, which after 3 tries may still be a claimed fursuit.

**State machine backing these actions** (`App\Models\Fursuit\States\FursuitStatusState::config()`):

```php
->default(Pending::class)
->allowTransition(Pending::class,  Approved::class, PendingToApproved::class)
->allowTransition(Rejected::class, Pending::class)                              // no transition class
->allowTransition(Rejected::class, Approved::class, RejectedToApproved::class)
->allowTransition(Pending::class,  Rejected::class, PendingToRejected::class)
```

- `PendingToApproved(Fursuit $fursuit, User $reviewer)` - in a DB transaction: sets `status = Approved`, `approved_at = now()`, `rejected_at = null`, saves, `activity()->performedOn(...)->causedBy($reviewer)->log('Fursuit approved')`, then notifies `FursuitApprovedNotification` **only** if `$fursuit->event->ends_at` exists **and** `now()->lt($eventEndsAt)`.
- `PendingToRejected(Fursuit $fursuit, User $reviewer, string $reason)` - sets `status = Rejected`, `rejected_at = now()`, `approved_at = null`, saves, `activity()->performedOn(...)->by($reviewer)->withProperties(['reason' => $reason])->log('Fursuit rejected')`, then notifies `FursuitRejectedNotification($fursuit, $reason)` under the same `ends_at` guard.
- `RejectedToApproved(Fursuit $fursuit, User $reviewer)` - sets `status = Approved`, `approved_at = now()`, `rejected_at = null`, `log('Fursuit approved (was previously rejected)')`, **always** notifies `FursuitRejectionReversedNotification`.
- `RejectedToPending` exists as a class in `Transitions/` but is **never wired into `config()`** (the Rejected→Pending edge is registered without a transition class) and no UI exposes it. Dead code.

Activity-log strings written by the transitions, verbatim, visible in the relation manager:
`Fursuit approved`, `Fursuit rejected` (with property `reason`), `Fursuit approved (was previously rejected)`.

Notification classes triggered from this slice (mail bodies live outside these files):
`App\Notifications\FursuitApprovedNotification`, `App\Notifications\FursuitRejectedNotification`
(takes a `$reason` string), `App\Notifications\FursuitRejectionReversedNotification`.

#### 4.3.2 `CreateFursuit`

`FursuitResource/Pages/CreateFursuit.php`, 11 lines. `Filament\Resources\Pages\CreateRecord` at
`/admin/fursuits/create`. No header actions, no `mutateFormDataBeforeCreate`, no redirect override.
Body is only `protected static string $resource = FursuitResource::class;`. Unreachable in practice -
`FursuitPolicy::create()` returns `false`.

#### 4.3.3 `EditFursuit`

`FursuitResource/Pages/EditFursuit.php`, 20 lines. `EditRecord` at `/admin/fursuits/{record}/edit`.

Header actions: `Actions\ViewAction::make()` (default label `View`, default icon, no confirm) and
`Actions\DeleteAction::make()` - Filament default delete copy (heading `Delete :label`, description
`Are you sure you would like to do this?`, submit `Delete`), gated by `FursuitPolicy::delete`
(`is_admin`). Performs a **soft delete**.

No `mutateFormDataBeforeSave`, no `getRedirectUrl`, no `afterSave`.

#### 4.3.4 `ListFursuits`

`FursuitResource/Pages/ListFursuits.php`, 19 lines. `ListRecords` at `/admin/fursuits`. Header
actions: `Actions\CreateAction::make()` (default copy `New fursuit`), hidden by
`FursuitPolicy::create() === false`. No tabs, no `getTableQuery` override, no widgets.

#### 4.3.5 `ActivitiesRelationManager`

`FursuitResource/RelationManagers/ActivitiesRelationManager.php`, 70 lines.

**Relationship:** `activities` (morphMany `Spatie\Activitylog\Models\Activity`, provided by the
`LogsActivity` trait on `Fursuit`). Rendered on both `ViewFursuit` and `EditFursuit`.
`recordTitleAttribute('description')`.

The model logs only a subset of attributes: `LogOptions::defaults()->logOnly(['name', 'image', 'species_id'])`,
plus the manual transition log entries listed above.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `description` | auto `Description` | TextColumn | no | no | no | no | plain |
| 2 | `causer.name` | `By` | TextColumn | **yes** | **yes** | no | no | polymorphic causer relation |

**Filters:** none (`->filters([ // ])`).

**Row actions:** `ViewAction`, `EditAction`, `DeleteAction` - all Filament default labels, icons and
copy (`Delete :label` / `Are you sure you would like to do this?` / `Delete`).

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - Filament default copy
(`Delete selected` / `Delete selected :label` / `Delete`).

**Header actions:** `CreateAction::make()` - default copy (`New activity`).

**Form** (used by create / edit / view of an activity):

| field | label | component | disabled | required | validation | hint | default / notes |
|---|---|---|---|---|---|---|---|
| `event` | auto `Event` | TextInput | no | yes | required, max 255 | - | - |
| `description` | auto `Description` | TextInput | no | yes | required, max 255 | - | - |
| `properties` | `Properties` | Textarea | no | no | `->json()` | `Key-value pairs of properties` | `->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))`, `->columnSpanFull()`, `->rows(25)` |
| `created_at` | auto `Created at` | DateTimePicker | **yes** | yes (moot) | required | - | `now()` |

**Notifications:** none custom.

---

### 4.4 Special Codes (`SpecialCodeResource`)

`app/Filament/Resources/SpecialCodeResource.php`, 137 lines.

**Nav:** group `Events & Registration` / label auto (`Special Codes`) / icon `heroicon-o-qr-code` /
sort `3` / no navigation badge / no badge colour.

**Model:** `App\Domain\CatchEmAll\Models\SpecialCode`. **Route base:** `/admin/special-codes`.
**Pages:** index only - `Pages\ManageSpecialCodes::route('/')` (`ManageRecords`, modal create/edit).

**Guards:** **no policy exists** (`app/Policies/` has no `SpecialCodePolicy`, and none is registered
in `AuthServiceProvider`), and the resource declares no `canX` overrides. Access is therefore
everyone who passes `canAccessPanel()` - admins **and reviewers** - with full create/edit/delete.
No `modifyQueryUsing`, so **this resource is not event-scoped** even though every row has an
`event_id`. No soft deletes on the model.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `code` | `Code` | TextColumn | yes | no | no | no | plain |
| 2 | `class_name` (line 88) | `Class` | TextColumn | yes | no | no | no | `->formatStateUsing(fn (string $state): string => match ($state) { 'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction' => 'Bug Hunter Bounty', default => $state })` |
| 3 | `constructor_data` (line 95) | `Data` | TextColumn | yes | no | no | no | raw output of an `object`-cast column |
| 4 | `event_id` (line 98) | `Event` | TextColumn | yes | no | no | no | `->formatStateUsing(fn (string $state): string => \App\Models\Event::where('id', $state)->pluck('name')->first())` - one query per row |

**Filters:** none (`->filters([ // ])`).

**Row actions:** `Tables\Actions\EditAction::make()` (modal, default copy);
`Tables\Actions\DeleteAction::make()` - Filament default delete copy (heading `Delete :label`,
description `Are you sure you would like to do this?`, submit `Delete`). Hard delete.

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - Filament default copy.

**Header actions:** `ManageSpecialCodes::getHeaderActions()` → `Actions\CreateAction::make()` (default
copy `New special code`). No `mutateFormDataBeforeCreate` / `mutateFormDataBeforeSave`, no redirect
overrides, no widgets.

**Form sections:** flat schema, no groups:

| # | field | label | component | disabled | required | validation | helper text (verbatim) | default / placeholder | reactive |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `event_id` (line 28) | `Event` | Select, `columnSpanFull()` | no | yes | required | `Event in which the code can be used` | - | options built eagerly: `\App\Models\Event::all()->pluck('name', 'id')` |
| 2 | `class_name` (line 36) | `Class` | Select, `columnSpanFull()` | no | no | - | `PHP class used for code handling` | - | options `['App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction' => 'Bug Hunter Bounty']`, a single hardcoded entry |
| 3 | `constructor_data` (line 43) | `Constructor Data` | Textarea `->rows(3)`, `columnSpanFull()` | `->disabled(fn ($get) => match ($get('class_name')) { 'EXAMPLE' => false, default => true })` (line 48) → always disabled | no | `->rules(['nullable', 'json'])` | `Data to be passed to the constructor of the action class` | placeholder `fn ($get) => match ($get('class_name')) { 'EXAMPLE' => '{"amount": 100, "reason": "An Example"}', default => '' }` (line 52) | depends on `class_name`, which is **not `->live()`** |
| 4 | `code` | `Code` | TextInput | no | yes | required, `maxLength(5)`, `minLength(5)`, `->unique(ignoreRecord: true, table: 'special_codes', column: 'code')`, plus the custom closure rule below | `E.g. ABC45` | - | - |
| 5 | `catch_url` | `Catch URL` | TextInput, `columnSpanFull()` | `->readOnly()`, `->dehydrated(false)` | no | - | `URL to catch the fursuiter with this code` | - | `->formatStateUsing(fn ($state, $get) => self::buildCatchAutoUrl($get('code') ?? ''))` - computed once at render, not live |

Custom validation rule on `code`, verbatim:

```php
->rule(fn () => function ($attribute, $value, $fail) {
    if (Fursuit::where('catch_code', $value)->exists()) {
        $fail('This code is already used in Fursuits.');
    }
})
```

Failure message verbatim: `This code is already used in Fursuits.`

URL builder:

```php
private static function buildCatchAutoUrl(string $code): string
{
    $scheme = parse_url(config('app.url'), PHP_URL_SCHEME) ?: 'https';
    $baseDomain = (string) config('fcea.domain', 'catch.localhost');

    return sprintf('%s://%s/?code=%s&auto', $scheme, $baseDomain, urlencode($code));
}
```

`config('fcea.domain')` = `env('CATCH_DOMAIN', 'catch.localhost')`.

**Infolist:** none. **Relation managers:** none. **Custom blade views:** none.
**Table config:** `->defaultSort('created_at', 'desc')`. No poll, no pagination override, no
`selectCurrentPageOnly`, no summarizers. **Notifications:** none custom.

---

### 4.5 Checkouts (`CheckoutResource`)

`app/Filament/Resources/CheckoutResource.php`, 294 lines.

**Nav:** group `Sales` / label `Checkouts` (Filament default from class name) / icon
`heroicon-o-shopping-cart` / sort `10` / no navigation badge / no badge colour.

**Model:** `App\Domain\Checkout\Models\Checkout\Checkout`. **Route base:** `/admin/checkouts`.
**Pages:** `index` (`ListCheckouts`), `view` (`ViewCheckout`). No create page, no edit page.

**Guards:**
- `canCreate(): bool => false` - comment verbatim: `// Checkouts are created through POS only`
- `canEdit(Model $record): bool => false` - comment verbatim: `// Checkouts should not be edited`
- `canDelete(Model $record): bool => false` - comment verbatim: `// Checkouts should not be deleted`
- No `canView`/`canViewAny` override. **No `CheckoutPolicy` exists**, so viewing is open to every
  admin **and reviewer** (see §3). No `modifyQueryUsing`, no `getEloquentQuery()` override, no
  global scopes on the model.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `id` | `ID` | TextColumn | yes | yes | no | no | `->searchable()` triggers a `LIKE` on a bigint column |
| 2 | `user.name` (line 119) | `Customer` | TextColumn | yes | yes | no | no | `url(fn ($record) => $record->user ? UserResource::getUrl('index').'?tableSearch='.urlencode($record->user->name) : null)` - links to the Users **index** with a pre-filled table search, not to the user record. Same tab (no `openUrlInNewTab`) |
| 3 | `cashier.name` (line 124) | `Cashier` | TextColumn | yes | yes | no | no | `->default('-')` when null. Relation is `belongsTo(Staff::class, 'cashier_id')` |
| 4 | `machine.name` (line 130) | `Machine` | TextColumn | yes | yes | **yes** | no | toggleable, visible by default |
| 5 | `status` | `Status` (default) | TextColumn `->badge()` | no | no | no | no | `formatStateUsing(fn ($state) => class_basename($state))` → `Active` / `Finished` / `Cancelled`. Colour `match(true) { $state instanceof Finished => 'success', $state instanceof Active => 'warning', $state instanceof Cancelled => 'danger', default => 'gray' }` |
| 6 | `payment_method` | `Payment Method` (default) | TextColumn `->badge()` | no | no | no | no | Colour `match($state) { 'cash' => 'success', 'card' => 'info', default => 'gray' }` |
| 7 | `total` (line 159) | `Total` (default) | TextColumn | yes | no | no | no | `->money('EUR', divideBy: 100)`; summarizer `Sum::make()->money('EUR', divideBy: 100)->label('Total')` (line 162) |
| 8 | `items_count` (line 166) | `Items` | TextColumn | no | no | no | no | `->counts('items')` (withCount subquery), not sortable |
| 9 | `created_at` | `Created At` (default) | TextColumn | yes | no | **yes** | no | `->dateTime()` (app default format) |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `status` (line 176) | SelectFilter | `Status` (default) | **yes** (`->multiple()`) | none | Options keyed by FQCN: `Active::class => 'Active'`, `Finished::class => 'Finished'`, `Cancelled::class => 'Cancelled'`. Default Filament `whereIn('status', $values)`; the DB stores `ACTIVE`/`FINISHED`/`CANCELLED` |
| 2 | `payment_method` | SelectFilter | `Payment Method` (default) | no | none | Options `['cash' => 'Cash', 'card' => 'Card']`; `where('payment_method', $value)` |
| 3 | `machine_id` | SelectFilter | `Machine` | no | none | `->relationship('machine', 'name')` |
| 4 | `created_at` | Filter (custom form) | `Created At` (default) | n/a | none | Form: `DatePicker::make('created_from')`, `DatePicker::make('created_until')`. Query verbatim: `$query->when($data['created_from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))->when($data['created_until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date))` |

**Row actions:**

1. `ViewAction` - stock Filament, links to `/admin/checkouts/{record}`.
2. `receipt` - label `Receipt`, icon `heroicon-o-document-text`, colour `gray`, no visibility predicate, no confirm. `->url(fn (Checkout $record): string => route('pos.checkout.receipt', $record))`, `->openUrlInNewTab()`. Target route is `GET pos/checkout/{checkout}/receipt` → `App\Http\Controllers\ReceiptController@show`.
3. `print` - label `Print`, icon `heroicon-o-printer`, colour `info`, no visibility predicate, `->requiresConfirmation()`.
   - Modal heading verbatim: `Print Receipt`
   - Modal description verbatim: `This will add the receipt to the print queue.`
   - No form fields.
   - Body (lines 225-258), verbatim:

```php
\App\Jobs\CreateReceiptFromCheckoutJob::dispatchSync($record);

// Find active receipt printer
$receiptPrinter = \App\Domain\Printing\Models\Printer::where('is_active', true)
    ->where('type', 'receipt')
    ->first();

if (! $receiptPrinter) {
    \Filament\Notifications\Notification::make()
        ->title('No receipt printer found')
        ->body('Please configure an active receipt printer first.')
        ->danger()
        ->send();

    return;
}

// Create print job
$record->printJobs()->create([
    'printer_id' => $receiptPrinter->id,
    'type' => 'receipt',
    'file' => 'checkouts/'.$record->id.'.pdf',
    'status' => \App\Enum\PrintJobStatusEnum::Pending,
]);

\Filament\Notifications\Notification::make()
    ->title('Receipt added to print queue')
    ->body("Receipt for checkout #{$record->id} has been queued for printing.")
    ->success()
    ->send();
```

`CreateReceiptFromCheckoutJob` renders receipt HTML via `resources/views/receipts/sale.blade.php`
through mPDF and embeds the Fiskaly QR from `$checkout->fiskaly_data['qr_code_data']`.

**Bulk actions:** none. Literal body: `// No bulk actions for checkouts`.

**Header actions - `ListCheckouts`:** none. Literal body:
`// No create action - checkouts are created through POS only`.

**Header actions - `ViewCheckout`** (`CheckoutResource/Pages/ViewCheckout.php`, 66 lines):

1. `receipt` - label `Download Receipt`, icon `heroicon-o-arrow-down-tray`, colour `gray`, `->url(fn (Checkout $record): string => route('pos.checkout.receipt', $record))`, `->openUrlInNewTab()`.
2. `print` - label `Print Receipt`, icon `heroicon-o-printer`, colour `info`, `->requiresConfirmation()`, modal heading `Print Receipt`, modal description `This will add the receipt to the print queue.`. The body is a **byte-for-byte duplicate** of the table `print` action above: same `dispatchSync`, same printer lookup with the raw string `'receipt'`, same print-job create with `'type' => 'receipt'`, same two notifications.

**Form sections** (used by the view page since there is no `infolist()`; every field `disabled()`):

- Section `Checkout Information` - no description, no icon, not collapsed, `->columns(2)`
  - `remote_id` - label `Remote ID`, TextInput, disabled
  - `user_id` - label `Customer`, Select, `->relationship('user', 'name')`, disabled, `->searchable()` (line 43)
  - `cashier_id` - label `Cashier`, Select, `->relationship('cashier', 'name')`, disabled
  - `machine_id` - label `Machine`, Select, `->relationship('machine', 'name')`, disabled
  - `status` - default label, TextInput, disabled (renders the Spatie state object stringified)
  - `payment_method` - default label, TextInput, disabled
- Section `Financial Details` (lines 63-79) - `->columns(3)`, not collapsed
  - `subtotal` - TextInput, `->prefix('€')`, `->numeric()`, disabled
  - `tax` - TextInput, `->prefix('€')`, `->numeric()`, disabled
  - `total` - TextInput, `->prefix('€')`, `->numeric()`, disabled
  - **No division by 100 anywhere in this section.** See landmines.
- Section `TSE Information` - `->columns(2)`, `->collapsed()`
  - `tse_start_timestamp` - label `TSE Start`, DateTimePicker, disabled
  - `tse_end_timestamp` - label `TSE End`, DateTimePicker, disabled
  - `tse_signature` (line 92) - label `TSE Signature`, TextInput, disabled, `->columnSpanFull()`
- Section `Timestamps` - `->columns(2)`, `->collapsed()`
  - `created_at` - DateTimePicker, disabled
  - `updated_at` - DateTimePicker, disabled

**Infolist:** none defined on the resource; the disabled form doubles as the read-only view.

**Table config:** `defaultSort('created_at', 'desc')`. No poll, no custom pagination options, no
`selectCurrentPageOnly`. One summarizer: Sum on `total`.

**Notifications, verbatim** (both appear twice: table `print` and `ViewCheckout` `print`):
- Danger - title `No receipt printer found`, body `Please configure an active receipt printer first.`
- Success - title `Receipt added to print queue`, body `Receipt for checkout #{$record->id} has been queued for printing.`

**Custom blade views:** none registered on the resource. Indirect: the print action renders
`resources/views/receipts/sale.blade.php` inside `CreateReceiptFromCheckoutJob`.

#### 4.5.1 `ItemsRelationManager`

`CheckoutResource/RelationManagers/ItemsRelationManager.php`, 120 lines.

**Relationship:** `items` (`Checkout hasMany App\Domain\Checkout\Models\Checkout\CheckoutItem`).
**Title:** `Checkout Items`. `recordTitleAttribute('name')`.
**Guards:** `canViewForRecord(): bool => true`; `protected canCreate(): bool => false`;
`protected canEdit(Model $record): bool => false`; `protected canDelete(Model $record): bool => false`.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | `Item` | TextColumn | no | **yes** | no | no | - |
| 2 | `description` | `Features` | TextColumn | no | no | no | no | `->wrap()`. `formatStateUsing`: `if (is_array($state) && ! empty($state)) return implode(', ', $state); return '-';` (column is `array`-cast on `CheckoutItem`) |
| 3 | `payable` (line 63) | `Badge` | TextColumn | no | no | no | no | `formatStateUsing(function ($record) { if ($record->payable_type === Badge::class && $record->payable) { $badge = $record->payable; return "{$badge->fursuit->name} (#{$badge->custom_id})"; } return '-'; })`. `url(...)`: same guard, returns `BadgeResource::getUrl('edit', ['record' => $record->payable])`, else `null`. `->openUrlInNewTab()` |
| 4 | `subtotal` (line 72) | `Subtotal` | TextColumn | no | no | no | no | `->money('EUR', divideBy: 100)`, `->alignEnd()` |
| 5 | `tax` (line 77) | `Tax` | TextColumn | no | no | no | no | `->money('EUR', divideBy: 100)`, `->alignEnd()` |
| 6 | `total` (line 82) | `Total` | TextColumn | no | no | no | no | `->money('EUR', divideBy: 100)`, `->alignEnd()`, `->weight('bold')` |

**Filters:** none. **Row actions:** none - literal body `// No actions - items are read-only`.
**Bulk actions:** none. **Header actions:** none - literal body
`// No header actions - items are created with checkout`.
**Form:** one implicit schema, `name` - TextInput, `->required()`, `->maxLength(255)`. Dead: create
and edit are both disabled, so this form is never rendered.
**Table config:** `->paginated(false)` - every item of the checkout is rendered. No summarizer row.
**Notifications:** none.

---

### 4.6 Machines (`MachineResource`)

`app/Filament/Resources/MachineResource.php`, 146 lines.

**Nav:** group `POS` / label `Machines` (default) / icon `heroicon-o-computer-desktop` / sort `1` /
no navigation badge / no badge colour.

**Model:** `App\Models\Machine`. **Route base:** `/admin/machines`. **Pages:** `index`
(`ListMachines`), `create` (`CreateMachine`), `edit` (`EditMachine`). No view page.

**Guards:** no resource-level `canX` overrides. `getEloquentQuery(): Builder { return parent::getEloquentQuery(); }`
(line 142) - an explicit **no-op override**, dead code. `App\Policies\MachinePolicy` gates every
ability on `is_admin`. No global scopes on the model; archival is manual local scopes only.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | `Name` (default) | TextColumn | no | **yes** | no | no | unreachable - the table sets `->searchable(false)` |
| 2 | `tseClient.remote_id` | `TSE Client` | TextColumn | no | no | no | no | `->placeholder('None assigned')` |
| 3 | `sumupReader.name` | `SumUp Reader` | TextColumn | no | no | no | no | `->placeholder('None assigned')` |
| 4 | `should_discover_printers` | `Auto-discover Printers` | IconColumn | no | no | no | no | `->boolean()` |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `archived` | TernaryFilter | `Archived` | n/a | blank (`Active machines`) | `->placeholder('Active machines')`, `->trueLabel('Archived machines')`, `->falseLabel('All machines')`; `queries(true: fn ($q) => $q->onlyArchived(), false: fn ($q) => $q->withArchived(), blank: fn ($q) => $q->notArchived())` (line 73). Model scopes: `onlyArchived` = `whereNotNull('archived_at')`, `withArchived` = `return $query` (no-op), `notArchived` = `whereNull('archived_at')` |

**Row actions:**

1. `EditAction` - stock.
2. `archive` - label `Archive`, icon `heroicon-o-archive-box`, colour `warning`, `->requiresConfirmation()`, visible when `! $record->isArchived()`.
   - Modal heading verbatim: `Archive Machine`
   - Modal description verbatim: `Are you sure you want to archive this machine? It will be hidden from normal view.`
   - Submit label verbatim (line 88): `Yes, archive it`
   - No form fields. Action `fn (Machine $record) => $record->archive()` → sets `archived_at = now()` and saves.
3. `unarchive` - label `Restore`, icon `heroicon-o-arrow-uturn-left`, colour `success`, `->requiresConfirmation()`, visible when `$record->isArchived()`.
   - Modal heading verbatim: `Restore Machine`
   - Modal description verbatim: `Are you sure you want to restore this machine? It will be visible again.`
   - Submit label verbatim (line 98): `Yes, restore it`
   - No form fields. Action `fn (Machine $record) => $record->unarchive()` → nulls `archived_at` and saves.

**Bulk actions** (not wrapped in a `BulkActionGroup`):

1. `archive` - label `Archive selected`, icon `heroicon-o-archive-box`, colour `warning`, `->requiresConfirmation()`, `->deselectRecordsAfterCompletion()`.
   - Modal heading verbatim: `Archive Machines`
   - Modal description verbatim: `Are you sure you want to archive the selected machines? They will be hidden from normal view and unable to log in to the POS system.`
   - Submit label verbatim (line 110): `Yes, archive them`
   - Action `fn ($records) => $records->each->archive()`
2. `unarchive` - label `Restore selected`, icon `heroicon-o-arrow-uturn-left`, colour `success`, `->requiresConfirmation()`, `->deselectRecordsAfterCompletion()`.
   - Modal heading verbatim: `Restore Machines`
   - Modal description verbatim: `Are you sure you want to restore the selected machines? They will be visible again and able to log in to the POS system.`
   - Submit label verbatim (line 120): `Yes, restore them`
   - Action `fn ($records) => $records->each->unarchive()`

**Header / page actions:**

- `ListMachines` (`Pages/ListMachines.php`, 19 lines): `Actions\CreateAction::make()`.
- `CreateMachine` (`Pages/CreateMachine.php`, 11 lines): bare `CreateRecord`, no `mutateFormDataBeforeCreate`, no `getRedirectUrl`.
- `EditMachine` (`Pages/EditMachine.php`, 30 lines): one header action, `Actions\Action::make('Login Link')` - the label is the raw name, so Filament renders it as `Login Link`. `->modal()` with an infolist containing `TextEntry::make('Login Link')->copyable()->getStateUsing(fn (Machine $record) => URL::signedRoute('pos.auth.machine.login', ['machine_id' => $record->id]))` (line 24). No confirm, no visibility predicate, no notification. No `mutateFormDataBeforeSave`, no `getRedirectUrl`, **no `DeleteAction`**.

**Form sections:** no `Section` wrappers; flat schema, all `columnSpanFull()`.

- `name` - default label `Name`, TextInput, `->required()`, `->maxLength(255)`. No helper text, no default.
- `tse_client_id` - label `TSE Client`, Select, `->relationship('tseClient', 'remote_id')`, `->getOptionLabelFromRecordUsing(fn ($record) => $record->remote_id ?? 'Unknown TSE Client')`. Not required, not searchable, no preload.
- `sumup_reader_id` - label `SumUp Reader`, Select, `->relationship('sumupReader', 'name')`, `->getOptionLabelFromRecordUsing(fn ($record) => $record->name ?? 'Unknown SumUp Reader')`. Not required, not searchable.
- `should_discover_printers` - default label `Should discover printers`, **Checkbox**. No default set in the form (DB default is `true`).

**Infolist:** only the ad-hoc single-entry infolist inside the `Login Link` modal.
**Relation managers:** none (`getRelations()` returns `[//]`). **Custom blade views:** none.
**Table config:** `->searchable(false)` (line 77, global search input disabled), `->paginated(false)`
(line 78). No `defaultSort`, no poll, no summarizers, no `selectCurrentPageOnly`.
**Notifications:** none defined anywhere in this resource or its pages. Archive, restore and login-link
give no feedback at all beyond the row re-render.

---

### 4.7 Printers (`PrinterResource`)

`app/Filament/Resources/PrinterResource.php`, 133 lines.

**Nav:** group `POS` / label auto (`Printers`) / icon `heroicon-o-printer` / sort `2` / no navigation
badge / no badge colour.

**Model:** `App\Domain\Printing\Models\Printer`. **Route base:** `/admin/printers`. **Pages:** index,
create, edit (no view page).

**Guards:** no `canCreate`/`canEdit`/`canDelete`/`canView` overrides, no `modifyQueryUsing`, no
`getEloquentQuery()` override, no global scopes. `App\Policies\PrinterPolicy` gates every method
(`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`) on `$user->is_admin`.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | `Name` (auto) | TextColumn | no | **yes** | no | no | - |
| 2 | `type` | `Type` (auto) | TextColumn | no | no | no | no | raw enum-backed value (`badge` / `receipt`), no label mapping |
| 3 | `machine.name` | `Machine` | TextColumn | no | no | no | no | - |
| 4 | `status` (line 68) | `Status` (auto) | **BadgeColumn** (deprecated in v3) | no | no | no | no | state `fn (Printer $record): string => $record->status->value ?? 'unknown'` (line 69). `->colors(['success' => 'idle', 'warning' => 'working', 'danger' => 'paused', 'secondary' => 'offline', 'info' => 'processing', 'gray' => 'unknown'])` - 6 of `PrinterStatusEnum`'s 12 cases |
| 5 | `pending_jobs` | `Pending Jobs` | TextColumn `->badge()` | no | no | no | no | state `$record->printJobs()->where('status','pending')->count()`; url `PrintJobResource::getUrl('index', ['printer' => $record->id])`; colour `warning` |
| 6 | `active_jobs` | `Active Jobs` | TextColumn `->badge()` | no | no | no | no | state `$record->printJobs()->whereIn('status', ['queued','printing','retrying'])->count()`; same url; colour `info` |
| 7 | `failed_jobs` | `Failed Jobs` | TextColumn `->badge()` | no | no | no | no | state `$record->printJobs()->where('status','failed')->count()`; same url; colour `danger` |
| 8 | `is_active` (line 96) | `Active` | **CheckboxColumn** | no | no | no | no | inline editable - toggling writes `is_active` straight to the DB, no confirm, no notification |
| 9 | `last_state_update` | `Last Update` | TextColumn | no | no | no | no | `->dateTime()->since()` (relative, e.g. "3 minutes ago") |

**Filters:** none (`->filters([//])`).

**Row actions:** `EditAction` (Filament default: label `Edit`, icon `heroicon-m-pen-to-square`, no
confirm, opens the resource form on the Edit page).

**Bulk actions:** `BulkActionGroup` containing `DeleteBulkAction` - Filament default copy: trigger
label `Delete selected`, heading `Delete selected :label`, description
`Are you sure you would like to do this?`, submit `Delete`. Hard delete; `Printer` has no `SoftDeletes`.

**Header / page actions:**

- `ListPrinters` (`Pages/ListPrinters.php`, 17 lines): `getHeaderActions(): []` - explicitly empty, so there is **no Create button in the UI** even though the create page and route exist.
- `CreatePrinter` (`Pages/CreatePrinter.php`, 11 lines): bare `CreateRecord`, no overrides.
- `EditPrinter` (`Pages/EditPrinter.php`, 19 lines): header actions `[Actions\DeleteAction::make()]` - Filament default delete copy: heading `Delete :label`, description `Are you sure you would like to do this?`, submit `Delete`, success toast `Deleted`.

**Form sections:** none - flat schema, every field `columnSpanFull()`.

| field | label | component | disabled | required | validation | default | notes |
|---|---|---|---|---|---|---|---|
| `name` | `Name` (auto) | TextInput | no | **yes** | `maxLength(255)` | - | - |
| `type` | `Type` (auto) | Select | no | **yes** | options `['receipt' => 'Receipt', 'badge' => 'Badge']` | - | labels duplicated by hand |
| `machine_id` | `Machine` | Select | no | **yes** | `->relationship('machine','name')` | - | - |
| `default_paper_size` (line 43) | `Default Paper Size` (auto) | Select | no | no | options `fn (Printer $record) => collect($record->paper_sizes)->pluck('name','name')` | - | non-nullable `Printer $record` type hint |
| `paper_sizes` | `Paper Sizes` (auto) | Textarea, 10 rows | **yes** | no | - | `'{}'` | `formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT))` |
| `is_active` | `Is Active` (auto) | Checkbox | no | no | - | - | - |

**Infolist:** none. **Relation managers:** none (`getRelations(): [//]`). **Custom blade views:** none.
**Table config:** no `defaultSort`, **no poll**, `->searchable(false)` (line 114 - the global search
input is hidden, so the `name` column's own `searchable` is unreachable), `->paginated(false)`
(line 115, all printers on one page).
**Notifications:** none defined in this resource; only Filament defaults from Edit and Delete.

Columns that exist on `printers` but are surfaced nowhere in this resource: `condition`,
`condition_message`, `condition_reported_at`, `cards_remaining`, `cards_capacity`, `condition_raw`
(all added by `2026_08_05_100300_add_agent_condition_columns_to_printers_table.php`), plus
`last_error_message`, `handling_machine_name`, `current_job_id`. There is no admin action to clear a
printer error even though `Printer::clearPrinterError()` exists, and no action to reset a stuck state.

---

### 4.8 Print Batches (`PrintBatchResource`)

`app/Filament/Resources/PrintBatchResource.php`, 296 lines.

Class docblock (lines 20-27), verbatim:

> Batch oversight for staff who are not standing at the printer.
>
> Everything here is read-only apart from the three run controls and the manual verification. A batch is immutable once built, so there is no create or edit form: batches come from the "Build print batch" bulk action on badges, which is the only path that can freeze the sequence and lock the badges together.

The docblock's `"Build print batch"` is **stale**. The authoritative name of that entry point is
`printBadgeBulk`, label `Print Badges`, on `BadgeResource` (§4.2, line 453). The docblock is
reproduced here verbatim only because this document quotes source verbatim.

**Nav:** group `POS` / label `Print Batches` / icon `heroicon-o-rectangle-stack` / sort `2`.

Navigation badge, with its docblock (lines 40-53), verbatim:

```php
/**
 * Cards that printed but nobody has confirmed came out right. This is the
 * number staff act on, so it is the one worth carrying in the sidebar.
 */
public static function getNavigationBadge(): ?string
{
    $unverified = PrintBatch::query()
        ->whereHas('printJobs', fn (Builder $query) => $query->where('status', PrintJobStatusEnum::Printed)
            ->whereNull('verified_print_at'))
        ->count();

    return $unverified > 0 ? (string) $unverified : null;
}
```

The docblock says the number is **cards**. The code counts **batches** that contain at least one
printed-but-unverified job. Docblock and code disagree; the code is what renders.
`getNavigationBadgeColor()` returns `'warning'` unconditionally.

**Model:** `App\Domain\Printing\Models\PrintBatch`. **Route base:** `/admin/print-batches`.
**Pages:** index, view (no create, no edit).

**Guards:** `canCreate(): false` (hard override). No `canEdit`/`canDelete`/`canView` overrides, and
**no `PrintBatchPolicy` exists**, so listing, viewing, pausing, resuming and cancelling batches are
open to every admin **and reviewer** who passes `canAccessPanel()` - while merely viewing a printer
requires `is_admin` via `PrinterPolicy`. No `modifyQueryUsing`, no global scopes, no
`getEloquentQuery()` override.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `id` | `ID` | TextColumn | **yes** | no | no | no | - |
| 2 | `name` | `Name` (auto) | TextColumn | **yes** | **yes** | no | no | - |
| 3 | `status` | `Status` (auto) | TextColumn `->badge()` | no | no | no | no | `formatStateUsing(fn (PrintBatchStatusEnum $state) => $state->label())`, colour `static::statusColor($state)` |
| 4 | `printer.name` | `Printer` | TextColumn | **yes** | **yes** | no | no | placeholder `Unassigned` |
| 5 | `event.name` | `Event` | TextColumn | no | no | **yes** | **yes** | placeholder `None` |
| 6 | `progress` | `Progress` | TextColumn `->badge()` | no | no | no | no | state `"{$record->printed_count} / {$record->total_jobs}"`; description `"{$record->verified_count} verified, {$record->failed_count} failed"`; colour `match(true) { failed_count > 0 => 'danger', printed_count >= total_jobs && total_jobs > 0 => 'success', default => 'info' }` |
| 7 | `unverified` | `Needs check` | TextColumn `->badge()->alignCenter()` | no | no | no | no | state `$record->printed_count - $record->verified_count` (from the denormalised counters); colour `fn (int $state) => $state > 0 ? 'warning' : 'gray'` |
| 8 | `pause_reason` | `Reason` | TextColumn | no | no | no | no | `->limit(40)`, placeholder `None`, tooltip = full `pause_reason` |
| 9 | `createdBy.name` | `Built by` | TextColumn | no | no | **yes** | **yes** | placeholder `System` |
| 10 | `started_at` | `Started` | TextColumn | **yes** | no | no | no | `->dateTime('M j, H:i')`, placeholder `Not started` |
| 11 | `completed_at` | `Completed` | TextColumn | no | no | **yes** | **yes** | `->dateTime('M j, H:i')`, placeholder `Not finished` |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `status` | SelectFilter | `Status` (auto) | **yes** (`->multiple()`) | none | options from `PrintBatchStatusEnum::cases()` mapped `value => label()`; `whereIn('status', $values)` |
| 2 | `printer` | SelectFilter | `Printer` (auto) | no | none | `->relationship('printer', 'name')` |
| 3 | `needs_verification` | Filter `->toggle()` | `Has unverified cards` | n/a | off | `$query->whereHas('printJobs', fn ($jobs) => $jobs->where('status', PrintJobStatusEnum::Printed)->whereNull('verified_print_at'))`. Source comment: `The queue moved on but nothing has vouched for the card. These are the batches somebody has to walk over and check.` |

**Row actions:**

1. **`view`** - `Tables\Actions\ViewAction` with Filament defaults. Opens `/admin/print-batches/{record}`.

2. **`pause`** - icon `heroicon-o-pause`, colour `warning`, label auto (`Pause`). Visible:
   `fn (PrintBatch $record): bool => $record->status === PrintBatchStatusEnum::Printing`.
   **No `requiresConfirmation()`**, but it has a form, so a form modal opens with the default heading
   (the action label `Pause`) and no description.
   - Form field: `TextInput::make('reason')` label `Why is it being paused?`, `->required()`, `->maxLength(1000)`, helper text verbatim (line 171): `Shown to whoever is standing at the printer.`
   - Action: `$record->pause($data['reason'])` → `transitionTo(PrintBatchStatusEnum::Paused, $reason)` → sets `status = paused`, `pause_reason = $reason`. Returns false silently if the transition is illegal.
   - Notification: `Notification::make()->success()->title('Batch paused')->send();`

3. **`resume`** - icon `heroicon-o-play`, colour `success`, label auto (`Resume`). Visible:
   `fn (PrintBatch $record): bool => $record->status === PrintBatchStatusEnum::Paused`.
   `->requiresConfirmation()`, **no `modalHeading`** so the heading defaults to the action label
   `Resume`; `modalDescription` verbatim: `Only resume once the fault at the printer has actually been dealt with.`
   Submit is `Confirm`. No form fields.
   - Action: `$record->resume()` → `transitionTo(PrintBatchStatusEnum::Printing)` → sets `status = printing`, `started_at = started_at ?? now()`, `pause_reason = null`.
   - Notification: `Notification::make()->success()->title('Batch resumed')->send();`

4. **`cancel`** - icon `heroicon-o-x-circle`, colour `danger`, label auto (`Cancel`). Visible:
   `fn (PrintBatch $record): bool => ! $record->status->isTerminal()`. `->requiresConfirmation()`,
   `modalHeading` verbatim: `Cancel this batch`, `modalDescription` verbatim:
   `Cards already printed stay printed. Everything still queued is cancelled, and attendees whose card never printed get their badge back to edit.`
   - Form field: `TextInput::make('reason')` label `Reason`, not required, `->maxLength(1000)`, `->default('Cancelled from the admin panel')` (line 208).
   - Action: `$cancelled = $record->cancel($data['reason'] ?: 'Cancelled from the admin panel');`
   - `PrintBatch::cancel()` in a DB transaction: pulls every job in `[Pending, Queued, Printing, Retrying, Failed]`; a `Printing` job is first transitioned back to `Pending` (source comment: `a card mid-transfer is not something we can un-print`), then every job goes to `Cancelled`; for each job whose badge has **no** `Printed` job, `$badge->forceFill(['printing_locked_at' => null])->saveQuietly()` (unlocks attendee editing); then `update(['pause_reason' => $reason])`, `recalculateCounters()`, and `transitionTo(PrintBatchStatusEnum::Cancelled)`.
   - Notifications, both verbatim:
     - success: `Notification::make()->status('success')->title('Batch cancelled')->send();`
     - failure: `Notification::make()->status('danger')->title("Cannot cancel a batch that is {$record->status->label()}")->send();`

**Bulk actions:** none - `->bulkActions()` is not called, so Filament renders no bulk-select column.

**Header / page actions:**

- `ListPrintBatches` (`Pages/ListPrintBatches.php`, 20 lines): `getHeaderActions(): []` with the docblock `No create action: a batch can only come from PrintBatch::build(), which needs the badges it will contain.`
- `ViewPrintBatch` (`Pages/ViewPrintBatch.php`, 21 lines): `getHeaderActions(): []` with the docblock `Run controls live on the table row rather than here, so staff can pause a batch from the list without opening it. A batch is immutable once built, so there is nothing to edit on this page either.` Consequence: pause / resume / cancel are **not** reachable from the batch detail page, only from the list row.

**Form sections:** one placeholder only (the form exists because Filament requires one; unreachable
since there is no create or edit page):
`Forms\Components\Placeholder::make('immutable')->label('')->content('Batches are immutable. Build one from the badge list instead.')`
- content verbatim: `Batches are immutable. Build one from the badge list instead.`

**Infolist** (used by the View page):

| Section | Columns | Collapsed | Entries |
|---|---|---|---|
| `Batch` | 3 | no | `name`; `status` (`->badge()`, `formatStateUsing(fn (PrintBatchStatusEnum $state) => $state->label())`, colour `static::statusColor($state)`); `printer.name` label `Printer` placeholder `Unassigned`; `event.name` label `Event` placeholder `None`; `createdBy.name` label `Built by` placeholder `System`; `pause_reason` label `Pause reason` placeholder `None` |
| `Progress` | 4 | no | `total_jobs` label `Cards`; `printed_count` label `Printed` colour `success`; `verified_count` label `Verified` colour `success`; `failed_count` label `Failed` colour `danger` |
| `Timing` | 3 | **yes** (`->collapsed()`) | `created_at` `->dateTime()`; `started_at` `->dateTime()` placeholder `Not started`; `completed_at` `->dateTime()` placeholder `Not finished` |

No section descriptions or icons are set on any of the three.

**Table config:** `defaultSort('id', 'desc')`, `->poll('10s')` (line 217), default pagination options,
no `selectCurrentPageOnly`, no summarizers.
**Notifications:** the four listed under the actions above. None has a body.
**Custom blade views:** none.

`statusColor()` is a public static helper on the resource, shared by the table and the infolist.

#### 4.8.1 `PrintJobsRelationManager`

`PrintBatchResource/RelationManagers/PrintJobsRelationManager.php`, 127 lines.

**Name:** `PrintJobsRelationManager`, title override `Cards` (`protected static ?string $title = 'Cards'`).
**Relationship:** `printJobs` (`PrintBatch hasMany PrintJob`). Attached via `getRelations()`, so it
renders on the batch **View** page.

Class docblock, verbatim:

> The cards in a batch, in the order they print.
>
> The column that matters most is Verified. A job reaching Printed only means something claimed it finished; whether a correct card physically came out is a separate question, and the ones nobody has answered are exactly the ones staff need to walk over and check.

**Guards:** `isReadOnly(): false` - this is what makes the custom `verify` action reachable at all.
No policy on `PrintBatch`; `PrintJobPolicy` is **not** consulted here (relation managers authorize
against the owner record).

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `sequence` | `#` | TextColumn | **yes** | no | no | no | frozen print order set by `PrintBatch::build()` |
| 2 | `printable.custom_id` | `Badge` | TextColumn | no | **yes** | no | no | placeholder `Deleted` |
| 3 | `printable.fursuit.name` | `Fursuit` | TextColumn | no | **yes** | no | no | placeholder `Deleted` |
| 4 | `status` | `Status` (auto) | TextColumn `->badge()` | no | no | no | no | `formatStateUsing(fn (PrintJobStatusEnum $state) => $state->label())`; colour `match($state) { Printed => 'success', Failed => 'danger', Printing\|Queued => 'primary', Retrying => 'warning', Cancelled => 'gray', default => 'gray' }` |
| 5 | `completion_source` | `Finished by` | TextColumn | no | no | no | no | placeholder `Not finished`; `formatStateUsing(fn ($state) => $state?->label())` (`PrintCompletionSourceEnum`) |
| 6 | `verified_print_at` | `Verified` | **IconColumn** `->boolean()` | no | no | no | no | state `fn (PrintJob $record) => $record->verified_print_at !== null`; trueIcon `heroicon-o-check-badge` / falseIcon `heroicon-o-question-mark-circle`; trueColor `success` / falseColor `warning`; tooltip `fn (PrintJob $record) => $record->verified_print_at ? $record->verification_source?->label() : 'Nobody has confirmed this card came out'` - tooltip string verbatim: `Nobody has confirmed this card came out` |
| 7 | `attempt_count` | `Tries` | TextColumn | no | no | **yes** | **yes** | - |
| 8 | `error_message` | `Error` | TextColumn `->wrap()` | no | no | **yes** | **yes** | placeholder `None` |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `unverified` | Filter (checkbox, not `->toggle()`) | `Printed but unverified` | n/a | off | `$query->unverified()` → `where('status', PrintJobStatusEnum::Printed)->whereNull('verified_print_at')` |
| 2 | `status` | SelectFilter | `Status` (auto) | no | none | options from `PrintJobStatusEnum::cases()` mapped `value => label()` - **all 7 cases including `cancelled`**, unlike `PrintJobResource` |

**Row actions:**

- **`verify`** - label `Mark verified`, icon `heroicon-o-check-badge`, colour `success`.
  `->requiresConfirmation()`, `modalHeading` verbatim: `Confirm this card`, `modalDescription` verbatim:
  `Only do this with the printed card in front of you. This records that a human checked it.`
  Submit is `Confirm`. No form fields.
  - Visible: `fn (PrintJob $record) => $record->status === PrintJobStatusEnum::Printed && $record->verified_print_at === null` (source comment: `Only offered for cards that printed and that nobody has vouched for yet.`)
  - Action: `$record->markVerified(PrintVerificationSourceEnum::Operator, auth()->user());` → updates `verified_print_at = now()`, `verification_source = operator`, `verified_by_id = auth id`; if the printable is a `Badge`, also `forceFill(['verified_print_at' => now()])->saveQuietly()` on the badge; then `$this->batch?->recalculateCounters()`.
  - Notification: `Notification::make()->title('Card verified')->success()->send();`

**Bulk actions:** `->bulkActions([])` - explicitly empty. No bulk verify.
**Header actions:** none defined. `isReadOnly()` returning false means Filament would normally add
Create/Attach headers, but none are configured, so the header is empty.
**Table config:** `defaultSort('sequence')` ascending, **no poll** (the parent list polls at 10s; the
view page with this relation manager does not poll at all), default pagination, no summarizers.

---

### 4.9 Print Jobs (`PrintJobResource`)

`app/Filament/Resources/PrintJobResource.php`, 259 lines.

**Nav:** group `POS` / label auto (`Print Jobs`) / icon `heroicon-o-queue-list` / sort `3` / no
navigation badge / no badge colour.

**Model:** `App\Domain\Printing\Models\PrintJob`. **Route base:** `/admin/print-jobs`. **Pages:**
index, create, view, edit (all four).

**Guards:** no `canCreate`/`canEdit`/`canDelete`/`canView` overrides. `App\Policies\PrintJobPolicy`
gates all seven methods on `$user->is_admin`. **`getEloquentQuery()` is overridden** (line 248):

```php
public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();
    if (request()->has('printer')) {
        $query->where('printer_id', request('printer'));
    }
    return $query;
}
```

This is a *global* scope on the resource, not a table filter - it applies to every page of the
resource (index, view, edit, record resolution) whenever `?printer=` is on the URL.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `id` | `ID` | TextColumn | **yes** | no | no | no | - |
| 2 | `printer.name` | `Printer` | TextColumn | **yes** | **yes** | no | no | - |
| 3 | `type` (line 89) | `Type` (auto) | **BadgeColumn** | no | no | no | no | state `$record->type->value`; `->colors(['primary' => 'badge', 'secondary' => 'receipt'])` |
| 4 | `status` (line 95) | `Status` (auto) | **BadgeColumn** | no | no | no | no | state `$record->status->value`; `->colors(['warning' => 'pending', 'info' => 'queued', 'primary' => 'printing', 'success' => 'printed', 'danger' => 'failed', 'secondary' => 'retrying'])`. **`cancelled` is not in the map** |
| 5 | `printable` (line 108) | `Printable` | TextColumn | no | no | no | no | state: `if ($record->printable_type === 'App\\Models\\Badge\\Badge') return "Badge #{$record->printable?->custom_id}";` else `class_basename($record->printable_type)." #{$record->printable_id}"` |
| 6 | `priority` (line 117) | `Priority` (auto) | TextColumn `->badge()` | **yes** | no | no | no | colour `fn (int $state) => match(true) { $state >= 10 => 'danger', $state >= 5 => 'warning', $state >= 1 => 'info', default => 'gray' }` |
| 7 | `retry_count` (line 126) | `Retries` | TextColumn `->badge()` | no | no | no | no | colour `fn (int $state) => match(true) { $state >= 3 => 'danger', $state >= 1 => 'warning', default => 'gray' }` |
| 8 | `processingMachine.name` | `Machine` | TextColumn | no | no | no | no | placeholder `Not assigned` |
| 9 | `created_at` | `Created` | TextColumn | **yes** | no | no | no | `->dateTime()` (default format) |
| 10 | `printed_at` | `Printed` | TextColumn | no | no | no | no | `->dateTime()`, placeholder `Not printed` |
| 11 | `error_message` | `Error` | TextColumn | no | no | no | no | `->limit(50)`, placeholder `None`, `->tooltip(fn (PrintJob $record): ?string => $record->error_message)` |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `status` | SelectFilter | `Status` (auto) | no | none | options `pending => Pending, queued => Queued, printing => Printing, printed => Printed, failed => Failed, retrying => Retrying` (**`cancelled` missing**); `where('status', $value)` |
| 2 | `type` | SelectFilter | `Type` (auto) | no | none | options `badge => Badge, receipt => Receipt`; `where('type', $value)` |
| 3 | `printer` | SelectFilter | `Printer` (auto) | no | none | `->relationship('printer', 'name')` |
| 4 | `printable_id` | Filter + form | `Printable ID` | n/a | none | form `TextInput::make('value')->label('Printable ID')->numeric()`; query `$query->when($data['value'], fn ($q, $v) => $q->where('printable_id', $v))`; indicator `'Printable ID: '.$data['value']` (null when empty) |
| 5 | `printable_type` | Filter + form | `Printable Type` | n/a | none | form `TextInput::make('value')->label('Printable Type')`; query `$query->when($data['value'], fn ($q, $v) => $q->where('printable_type', $v))`; indicator `'Type: '.class_basename($data['value'])` (null when empty) |

**Row actions:**

1. `ViewAction` - Filament defaults (label `View`, icon `heroicon-m-eye`), opens `/admin/print-jobs/{record}`.
2. `EditAction` - Filament defaults, opens `/admin/print-jobs/{record}/edit`.
3. `retry` - label auto (`Retry`), icon `heroicon-o-arrow-path`, colour `warning`, visible
   `fn (PrintJob $record): bool => $record->canRetry()` (i.e. `status === Failed && retry_count < 3`),
   bare `->requiresConfirmation()`: heading `Retry` (the action label), description
   `Are you sure you would like to do this?`, submit `Confirm`, cancel `Cancel`. No form fields.
   - Body: `$retryJob = $record->createRetryJob(reassignPrinter: true);` then a notification. `createRetryJob(true)` creates a **new** `PrintJob` row with `status = Pending`, `priority = 1`, `retry_count = 0`, `retry_of = $this->id`, the same `print_batch_id`, `sequence`, `printable_*`, `file`; nulls `processing_machine_id`, `firmware_job_id`, `firmware_job_uuid`, `error_message` and all timestamps. `reassignPrinter: true` runs `findAvailablePrinter()`: `Printer::where('type', $originalPrinter->type)->where('is_active', true)->whereNotIn('status', ['offline','paused'])->orderBy('status')->first()`, falling back to the original `printer_id`. The **original job stays `Failed`** and the batch stays `Paused`.

**Bulk actions:** `BulkActionGroup` → `DeleteBulkAction`, Filament default copy (trigger
`Delete selected`, heading `Delete selected :label`, description `Are you sure you would like to do this?`,
submit `Delete`). Hard delete; no soft deletes on `print_jobs`.

**Header / page actions:**

- `ListPrintJobs` (`Pages/ListPrintJobs.php`, 31 lines): header actions `[Actions\CreateAction::make()]`. Custom page title:
```php
public function getTitle(): string
{
    if (request()->has('printer')) {
        $printerName = \App\Domain\Printing\Models\Printer::find(request('printer'))?->name ?? 'Unknown';
        return "Print Jobs - {$printerName}";
    }
    return 'Print Jobs';
}
```
- `CreatePrintJob` (`Pages/CreatePrintJob.php`, 11 lines): bare `CreateRecord`, no `mutateFormDataBeforeCreate`, no redirect override.
- `ViewPrintJob` (`Pages/ViewPrintJob.php`, 19 lines): header actions `[Actions\EditAction::make()]`. **No `infolist()` is defined on the resource**, so the view page falls back to rendering the *form* schema disabled.
- `EditPrintJob` (`Pages/EditPrintJob.php`, 20 lines): header actions `[Actions\ViewAction::make(), Actions\DeleteAction::make()]` - Filament default delete copy. No `mutateFormDataBeforeSave`, no redirect override.

**Form sections:** none - flat, every field `columnSpanFull()`.

| field | label | component | disabled | required | validation | default | notes |
|---|---|---|---|---|---|---|---|
| `printer_id` | `Printer` | Select | no | **yes** | `->relationship('printer','name')` | - | - |
| `type` | `Type` (auto) | Select | no | **yes** | options `PrintJobTypeEnum::Badge->value => 'Badge'`, `PrintJobTypeEnum::Receipt->value => 'Receipt'` | - | - |
| `status` | `Status` (auto) | Select | no | **yes** | options `Pending => 'Pending', Queued => 'Queued', Printing => 'Printing', Printed => 'Printed', Failed => 'Failed', Retrying => 'Retrying'` (**`Cancelled` absent**) | - | writes the raw status |
| `priority` | `Priority` (auto) | TextInput | no | no | `->numeric()` | `0` | - |
| `retry_count` | `Retry Count` (auto) | TextInput | no | no | `->numeric()` | `0` | - |
| `error_message` | `Error Message` (auto) | Textarea, 3 rows | no | no | - | - | - |
| `firmware_job_id` | `Printer job id` | TextInput | no | no | `maxLength(64)` | - | source comment: `Reported by the printer firmware over SNMP, which is what the agent matches a finished card against.` |
| `firmware_job_uuid` | `Printer job UUID` | TextInput | no | no | `maxLength(64)` | - | - |

**Infolist:** none. **Relation managers:** none (`getRelations(): [//]`). **Custom blade views:** none.
**Table config:** `defaultSort('id', 'desc')`, `->poll('5s')` (line 227), default pagination options,
no `selectCurrentPageOnly`, no summarizers.
**Notifications:** `Notification::make()->success()->title("Created retry job #{$retryJob->id}")->send();`
- status success, no body.

Fields that exist on the model but are surfaced nowhere in this resource: `print_batch_id`,
`sequence`, `lease_expires_at`, `attempt_count`, `completion_source`, `verified_print_at`,
`verification_source`, `verified_by_id`, `retry_of` / `originalJob` / `retryJobs`, `file`,
`queued_at`, `started_at`, `failed_at`. From the print-jobs list you cannot tell which batch a card
belongs to.

---

### 4.10 Staff (`StaffResource`)

`app/Filament/Resources/StaffResource.php`, 128 lines.

**Nav:** group `POS` / label auto (`Staff`) / icon `heroicon-o-user-group` / sort `3` / no navigation
badge / no badge colour.

**Model:** `App\Models\Staff` (table `staff`, extends `Authenticatable`, guard `machine-user`).
**Route base:** `/admin/staff`. **Pages:** index (`ListStaff`), create (`CreateStaff` at `/create`),
edit (`EditStaff` at `/{record}/edit`). No view page.

**Guards:** no resource-level `can*` overrides, no `modifyQueryUsing`, no global scopes.
`App\Policies\StaffPolicy` (registered explicitly) returns `$user->is_admin` for every method, so
`viewAny` is false for reviewers and Filament hides the nav entry from them.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | auto `Name` | TextColumn | **yes** | **yes** | no | no | - |
| 2 | `pin_code` (line 77) | `PIN Code` | TextColumn | no | no | yes | **yes** | `->formatStateUsing(fn ($state) => $state ? 'Set' : 'Not Set')` - renders the literal strings `Set` / `Not Set`, never the PIN. The underlying value **is** loaded into the page payload before formatting; `Staff::$hidden` only affects serialization |
| 3 | `is_active` | `Active` | IconColumn `->boolean()` | no | no | no | no | model cast `boolean` |
| 4 | `rfid_tags_count` | `RFID Tags` | TextColumn `->counts('rfidTags')` | no | no | no | no | withCount subquery over `rfid_tags` |
| 5 | `last_login_at` | `Last Login` | TextColumn `->dateTime()->since()` | **yes** | no | no | no | `->since()` wins over `->dateTime()`, so it renders as a human diff. Null renders empty (no placeholder) |
| 6 | `created_at` | auto `Created at` | TextColumn `->dateTime()` | **yes** | no | yes | **yes** | - |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `is_active` | TernaryFilter | `Active Status` | n/a | none (all) | Filament default ternary: true → `where('is_active', true)`, false → `where('is_active', false)`, blank → no constraint |

**Row actions:**

- `EditAction::make()` - default label `Edit`, icon `heroicon-m-pencil-square`, primary, no visibility predicate. On a resource with an edit **page**, this navigates to `/admin/staff/{record}/edit` rather than opening a modal. Gated by `StaffPolicy::update`.
- `DeleteAction::make()` - Filament default delete copy (heading `Delete :label`, description `Are you sure you would like to do this?`, submit `Delete`, cancel `Cancel`, success toast `Deleted`). Hard delete; `Staff` has no `SoftDeletes` and `rfid_tags.staff_id` is `onDelete('cascade')`, so all the member's RFID tags are destroyed with them. Gated by `StaffPolicy::delete`.

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - Filament default copy
(trigger `Delete selected`, heading `Delete selected :label`, submit `Delete`). Same RFID cascade.

**Header / page actions:**

- `ListStaff` (`Pages/ListStaff.php`, 19 lines): `Actions\CreateAction::make()` - label `New staff`, icon `heroicon-m-plus`. No tabs, no header widgets.
- `CreateStaff` (`Pages/CreateStaff.php`, 11 lines): completely empty - no header actions, no `mutateFormDataBeforeCreate`, no `getRedirectUrl`, no `handleRecordCreation`.
- `EditStaff` (`Pages/EditStaff.php`, 19 lines): `Actions\DeleteAction::make()` in the header (default copy; on success redirects to the index). No `mutateFormDataBeforeSave`, no `getRedirectUrl`, no `afterSave`.

**Form sections:** none - flat schema, default 2-column grid.

| # | field | label | component | disabled | required | validation | helper text (verbatim) | default |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | auto `Name` | TextInput | no | **yes** | `required`, `maxLength(255)` | none | none |
| 2 | `pin_code` (line 32) | `PIN Code (6 digits)` | TextInput | no | no (`->nullable()`) | `nullable`, `numeric`, `length(6)` (→ `size:6`), plus `->rules([new SecurePinRule])` (line 38) | `Enter a secure 6-digit PIN code. Leave empty to require setup code first.` | none |
| 3 | `setup_code` | `Setup Code` | TextInput | no | no (`->nullable()`) | `nullable`, `length(6)` | `6-character alphanumeric code for initial account setup. Auto-generated if left empty.` | none. `->extraAttributes(['style' => 'text-transform: uppercase'])` (CSS only, the typed value is not uppercased in the DOM). `->mutateDehydratedStateUsing(fn ($state) => strtoupper($state ?? ''))` (line 45) - uppercases on save and turns `null` into `''` |
| 4 | `is_active` | `Active` | Toggle | no | no | none | `Inactive staff cannot login to POS` | `true` |

**Suffix action inside the `setup_code` field:**

- name `generate_setup_code`, label `Generate`, icon `heroicon-m-arrow-path`, no colour override, no confirm modal.
- Visibility: `fn ($record) => ! $record || ! $record->pin_code` (line 61) - shown while creating, and on edit only while the member still has **no** PIN.
- Body: if `$record` exists → `$code = $record->generateSetupCode();` (line 52), which **writes to the database immediately** (`Staff::generateSetupCode()` does `$this->update(['setup_code' => $code])`) before the form is saved. If there is no record (create screen) → loops `strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6))` until `Staff::where('setup_code', $code)` does not exist, without persisting. Then `$set('setup_code', $code)`.

**Infolist:** none. **Custom blade views:** none.
**Table config:** `->paginated(false)` (line 110) - the whole staff table is rendered in one page,
unbounded. No `defaultSort`, no poll, no `selectCurrentPageOnly`, no summarizers.
**Notifications:** none defined; Filament defaults (`Saved`, `Created`, `Deleted`) apply.
**Relation managers:** `RfidTagsRelationManager`, rendered only on the Edit page (there is no view page).

#### 4.10.1 `RfidTagsRelationManager`

`StaffResource/RelationManagers/RfidTagsRelationManager.php`, 81 lines.

Tab title defaults to `Rfid tags` (no `$title` / `$modelLabel` override).
**Model:** `App\Models\RfidTag` via `protected static string $relationship = 'rfidTags';` →
`Staff::rfidTags()` = `hasMany(RfidTag::class)` (unfiltered - the `activeRfidTags()` relation is
**not** used here). `->recordTitleAttribute('content')`.
**Guards:** none. No `canViewForRecord`, no policy on `RfidTag` at all, so anyone who reaches the
Staff edit page can create, edit and delete tags. That page is admin-only via `StaffPolicy`.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `content` | `RFID Code` | TextColumn | no | **yes** | no | no | `->copyable()` (click-to-copy, default copy message) |
| 2 | `name` | `Tag Name` | TextColumn | no | **yes** | no | no | `->placeholder('No name set')` |
| 3 | `is_active` | `Active` | IconColumn `->boolean()` | no | no | no | no | model cast `boolean` |
| 4 | `last_login_at` | `Last Used` | TextColumn `->dateTime()->since()` | **yes** | no | no | no | `->placeholder('Never used')` |
| 5 | `created_at` | `Added` | TextColumn `->dateTime()->since()` | **yes** | no | no | no | `->since()` overrides `->dateTime()` |

**Filters:**

| # | key | type | label | multiple | default | query logic |
|---|---|---|---|---|---|---|
| 1 | `is_active` | TernaryFilter | `Active Status` | n/a | none (all) | true → `where('is_active', true)`; false → `where('is_active', false)`; blank → unconstrained |

**Row actions:** `EditAction::make()` (default label `Edit`, icon `heroicon-m-pencil-square`, modal with
the form below); `DeleteAction::make()` - Filament default delete copy (heading `Delete :label`,
description `Are you sure you would like to do this?`, submit `Delete`). Hard delete.

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - Filament default copy
(trigger `Delete selected`, heading `Delete selected :label`, submit `Delete`).

**Header actions:** `Tables\Actions\CreateAction::make()` - default label `Create rfid tag`, icon
`heroicon-m-plus`. Attaches a new `RfidTag` to the owning staff record.

**Form sections:** none - flat schema.

| # | field | label | component | disabled | required | validation | helper text (verbatim) | default |
|---|---|---|---|---|---|---|---|---|
| 1 | `content` | `RFID Code` | TextInput | no | **yes** | `required`, `unique(ignoreRecord: true)` against `rfid_tags.content`, `maxLength(255)` | `The unique identifier from the RFID tag` | none |
| 2 | `name` | `Tag Name (Optional)` | TextInput | no | no | `maxLength(255)` | `A friendly name for this RFID tag` | none |
| 3 | `is_active` | `Active` | Toggle | no | no | none | `Inactive tags cannot be used for authentication` | `true` |

**Infolist:** none. **Custom blade views:** none.
**Table config:** no `defaultSort`, no poll, default pagination, no summarizers.
**Notifications:** none defined; Filament defaults apply.

---

### 4.11 SumUp Readers (`SumUpReaderResource`)

`app/Filament/Resources/SumUpReaderResource.php`, 77 lines.

**Nav:** group `POS` / label `Sum Up Readers` (Filament default from `SumUpReader`) / icon
`heroicon-o-credit-card` / sort `4` / no navigation badge / no badge colour.

**Model:** `App\Models\SumUpReader` (table `sumup_readers`). **Route base:** `/admin/sum-up-readers`.
**Pages:** `index` (`ListSumUpReaders`), `create` (`CreateSumUpReader`), `edit` (`EditSumUpReader`).
No view page.

**Guards:** no resource-level overrides. `App\Policies\SumUpReaderPolicy` gates all abilities on
`$user->is_admin`. No `modifyQueryUsing`, no `getEloquentQuery()` override, no global scopes, no soft
deletes.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `name` | `Name` (default) | TextColumn | no | no | no | no | - |
| 2 | `remote_id` | `Remote Id` (default) | TextColumn | no | no | no | no | SumUp-side reader id |
| 3 | `paring_code` (line 47) | `Paring Code` (default) | TextColumn | no | no | no | no | **Displayed in full plaintext.** No masking, no `->copyable()`, no toggleable-hidden, no `->visible()` guard. The column name is a typo for "pairing code", baked into `2024_09_14_224516_create_sumup_readers_table.php` |

**Filters:** none.

**Row actions:** `EditAction` only. No delete row action.

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - Filament default copy
(trigger `Delete selected`, heading `Delete selected :label`, description
`Are you sure you would like to do this?`, submit `Delete`). Hard delete; the model has no `SoftDeletes`.

**Header / page actions:**

- `ListSumUpReaders` (`Pages/ListSumUpReaders.php`, 19 lines): `Actions\CreateAction::make()`.
- `CreateSumUpReader` (`Pages/CreateSumUpReader.php`, 11 lines): bare `CreateRecord`, no hooks, no redirect override.
- `EditSumUpReader` (`Pages/EditSumUpReader.php`, 19 lines): `Actions\DeleteAction::make()` - Filament default delete copy. No `mutateFormDataBeforeSave`, no `getRedirectUrl`.

**Form sections:** no `Section` wrappers; flat, all `columnSpanFull()`.

- `name` - default label `Name`, TextInput, `->required()`, `->maxLength(255)`.
- `remote_id` (line 33) - default label `Remote Id`, TextInput, `->readOnly()`. Note `readOnly()`, **not** `disabled()` - the value is still submitted with the form and therefore still writable by a crafted request.
- `paring_code` (line 34) - default label `Paring Code`, TextInput, `->required()`, `->maxLength(255)`. **Not `->password()`, not masked, not `->revealable()`.** Plain visible text input.

**Infolist:** none. **Relation managers:** none. **Custom blade views:** none.
**Table config:** no `defaultSort`, no poll, default pagination, no summarizers, no `selectCurrentPageOnly`.
**Notifications:** none custom; only Filament's stock create/save/delete toasts.

---

### 4.12 TSE Clients (`TseClientResource`)

`app/Filament/Resources/TseClientResource.php`, 83 lines.

**Nav:** group `POS` / label `Tse Clients` (Filament default) / icon `heroicon-o-shield-check` /
sort `5` / no navigation badge / no badge colour.

**Model:** `App\Domain\Checkout\Models\TseClient`. **Route base:** `/admin/tse-clients`. **Pages:**
`index` (`ListTseClients`), `create` (`CreateTseClient`), `edit` (`EditTseClient`). No view page.

**Guards:** no resource-level overrides. `App\Policies\TseClientPolicy` gates all abilities on
`$user->is_admin`; docblock verbatim: `Only admins can view TSE clients (sensitive security equipment).`
No `modifyQueryUsing`, no `getEloquentQuery()` override, no global scopes, no soft deletes.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `remote_id` | `Remote ID` | TextColumn | no | **yes** | no | no | Fiskaly-side client id |
| 2 | `serial_number` | `Serial Number` | TextColumn | no | **yes** | no | no | TSE serial - the identifier tying signed transactions to the security module |
| 3 | `state` | `State` | TextColumn | no | **yes** | no | no | backed by `TseClientStateEnum` cast; rendered raw (`REGISTERED` / `DEREGISTERED`), no badge, no colour |

**Filters:** none (empty array with a blank line).

**Row actions:** `EditAction` only. No delete, no view, no deregister/register action.

**Bulk actions:** none (empty array).

**Header / page actions:**

- `ListTseClients` (`Pages/ListTseClients.php`, 31 lines): a **single custom action**, `Actions\Action::make('createnew')` (line 18) - label `Create TSE Client`, icon `heroicon-o-plus-circle`, colour default, **no `requiresConfirmation()`**, no visibility predicate, no form fields, no notification. Body verbatim:
```php
$uuid = Str::uuid();
TseClient::create([
    'remote_id' => $uuid,
    'serial_number' => $uuid,
    'state' => 'REGISTERED',
]);
```
  There is **no** `Actions\CreateAction::make()` on the list page, so `/admin/tse-clients/create` exists but is only reachable by typing the URL.
- `CreateTseClient` (`Pages/CreateTseClient.php`, 11 lines): bare `CreateRecord`, no hooks, no redirect override.
- `EditTseClient` (`Pages/EditTseClient.php`, 17 lines): `getHeaderActions()` (line 12) returns an **empty array** - an explicit override that removes the default `DeleteAction`. No `mutateFormDataBeforeSave`, no `getRedirectUrl`.

**Form sections:** no `Section` wrappers; flat, default column layout (2 columns).

- `remote_id` - label `Remote ID`, TextInput, `->required()`. **Not disabled.** No maxLength, no helper text, no default, no unique rule.
- `serial_number` - label `Serial Number`, TextInput, `->required()`. **Not disabled.** No maxLength, no helper text, no default, no unique rule.
- `state` - label `State`, Select, `->required()`, options `['REGISTERED' => 'Registered', 'DEREGISTERED' => 'Deregistered']` (duplicated by hand from `TseClientStateEnum`). Not reactive, no default, no helper text.

**Infolist:** none. **Relation managers:** none - `TseClient::machine()` is a `hasOne(Machine::class)`
but is not surfaced, so there is no way to see which POS machine a TSE client is bound to from this
screen. **Custom blade views:** none.
**Table config:** no `defaultSort`, no poll, default pagination, no summarizers, no `selectCurrentPageOnly`.
**Notifications:** none anywhere, including after `createnew` fires. The user gets no confirmation
that a TSE client was created.

No admin PIN / admin PUK / secret-key fields are stored on the model at all - those live in Fiskaly
config (`TSE.md`), so nothing secret is exposed here, but equally there is no UI for them.

---

### 4.13 Users (`UserResource`)

`app/Filament/Resources/UserResource.php`, 93 lines.

**Nav:** group `User Management` / label auto (`Users`) / icon `heroicon-o-users` / sort `1` / no
navigation badge / no badge colour.

**Model:** `App\Models\User`. **Route base:** `/admin/users`. **Pages:** index only
(`Pages\ManageUsers::route('/')`) - a `ManageRecords` page, so create, edit and delete all happen in
**modals**; there is no create/edit/view URL.

**Guards:** no `canCreate`/`canEdit`/`canDelete`/`canView` overrides, no `modifyQueryUsing`, no
global scopes on `User`. `App\Policies\UserPolicy` (auto-discovered) returns `$user->is_admin` for
every method.

**Table columns:**

| # | key | label | type | sortable | searchable | toggleable | hidden by default | notes |
|---|---|---|---|---|---|---|---|---|
| 1 | `remote_id` | auto `Remote id` | TextColumn | no | yes | no | no | Identity SSO id |
| 2 | `valid_registration` (line 54) | auto `Valid registration` | IconColumn `->boolean()` | no | no | no | no | **The column no longer exists on `users`** - dropped by `2025_08_03_195303_remove_old_columns_from_users_table` and moved to `event_users.valid_registration` |
| 3 | `name` | auto `Name` | TextColumn | no | yes | no | no | not sortable |
| 4 | `email` | auto `Email` | TextColumn | no | yes | no | no | not sortable |
| 5 | `is_admin` | auto `Is admin` | IconColumn `->boolean()` | no | no | no | no | cast `bool` on the model |
| 6 | `is_reviewer` | auto `Is reviewer` | IconColumn `->boolean()` | no | no | no | no | **no cast** on the model (raw tinyint) |
| 7 | `created_at` | auto `Created at` | TextColumn `->dateTime()` | yes | no | yes | **yes** | - |
| 8 | `updated_at` | auto `Updated at` | TextColumn `->dateTime()` | yes | no | yes | **yes** | - |

**Filters:** none - `->filters([ // ])` is an empty array with a comment placeholder.

**Row actions:**

- `EditAction::make()` - Filament default. Label `Edit`, icon `heroicon-m-pencil-square`, primary, no visibility predicate, no confirm modal. Opens the resource form in a modal and saves via `$record->update()`. Gated by `UserPolicy::update`.
- `DeleteAction::make()` - Filament default delete copy, not overridden in this file: heading `Delete :label`, description `Are you sure you would like to do this?`, submit `Delete`, cancel `Cancel`, success toast `Deleted`. Hard `delete()` - `User` has no `SoftDeletes`. Gated by `UserPolicy::delete`.

**Bulk actions:** `BulkActionGroup::make([ DeleteBulkAction::make() ])` - grouped under the default
"Bulk actions" dropdown. Trigger label `Delete selected`, icon `heroicon-m-trash`, colour `danger`,
modal heading `Delete selected :label`, description `Are you sure you would like to do this?`,
submit `Delete`. Hard-deletes each selected user.

**Header / page actions** (`UserResource/Pages/ManageUsers.php`, 19 lines):
`Actions\CreateAction::make()` - Filament default, label `New user`, icon `heroicon-m-plus`. Opens
the form in a modal and creates a `User`. Gated by `UserPolicy::create`. No `mutateFormDataBeforeCreate`,
no redirect override, no `getHeaderWidgets`, no `getTabs`.

**Form sections:** none - a flat schema, default 2-column grid, no `Section`, no description, no
icon, nothing collapsed.

| # | field | label | component | disabled | required | validation | helper text | default |
|---|---|---|---|---|---|---|---|---|
| 1 | `remote_id` | auto `Remote id` | TextInput | no | **yes** | `required`, `maxLength(255)` | none | none |
| 2 | `valid_registration` (line 30) | auto `Valid registration` | Toggle | no | no | none | none | none |
| 3 | `name` | auto `Name` | TextInput | no | **yes** | `required`, `maxLength(255)` | none | none |
| 4 | `email` | auto `Email` | TextInput `->email()` | no | **yes** | `email`, `required`, `maxLength(255)` | none | none |
| 5 | `avatar` | auto `Avatar` | Textarea `->columnSpanFull()` | no | no | none | none | none |
| 6 | `is_reviewer` | auto `Is reviewer` | Toggle `->required()` | no | **yes** | `required` | none | none |
| 7 | `is_admin` | auto `Is admin` | Toggle `->required()` | no | **yes** | `required` | none | none |

**Infolist:** none. **Relation managers:** none - `getRelations()` is not defined, and a
`ManageRecords` page cannot host relation managers anyway. The user's badges, fursuits, eventUsers
and wallet are not reachable from this resource. **Custom blade views:** none.
**Table config:** no `defaultSort` (falls back to primary-key order), no `poll()`, default pagination,
no `selectCurrentPageOnly`, no summarizers.
**Notifications:** none defined in this resource or its page. Filament's built-in `Saved` / `Created` /
`Deleted` toasts apply.

Columns that exist on `users` today but are surfaced nowhere in this resource: `is_cashier`
(`2024_08_18_181214`), `pin_code` (users-level, legacy POS), `rfid_code` (unique, `2025_08_04_053827`),
`token` / `token_expires_at` / `refresh_token` / `refresh_token_expires_at` (hidden/encrypted),
`remember_token`.

## 5. Custom pages

### 5.1 PDF Generator (`Pages/PdfGenerator`)

`app/Filament/Pages/PdfGenerator.php`, 483 lines.

**Nav:** group `Tools` / no label set, falls back to `$title` = `PDF Generator` / icon
`heroicon-o-document-text` / sort none (`null`, so alphabetical within group) / no navigation badge.
**Route:** `/admin/pdf-generator`, route name `filament.admin.pages.pdf-generator`.
**Model:** none - the page queries `App\Models\Badge\Badge` and `App\Models\Event` directly.
**Guards:** no `canAccess()`, no `shouldRegisterNavigation()` override, so it is visible to anyone
who passes `User::canAccessPanel()` - admins **and reviewers**. No policy, no scopes.

No table, no filters, no row or bulk actions, no infolist, no relation managers.

**Header actions** (`getHeaderActions()`, ordered):

| # | name | label | icon | colour | visibility predicate | confirm modal | action |
|---|---|---|---|---|---|---|---|
| 1 | `generate_badge_list` | `Generate Badge List PDF` | `heroicon-o-document-text` | `primary` | `fn () => $this->data['pdf_type'] === 'badge_list'` | none | calls the Livewire method `generateBadgeListPdf` |
| 2 | `generate_box_labels` | `Generate Box Labels PDF` | `heroicon-o-tag` | `success` | `fn () => $this->data['pdf_type'] === 'box_labels'` | none | calls the Livewire method `generateBoxLabelsPdf` |

Neither header action declares its own form fields; both read the page form state (`$this->data`).

**Form sections.** One section `PDF Generation Options`, description
`Generate PDFs for badge management`, no icon, not collapsible. Form `statePath('data')`.

| field | label | component | required | default (from `mount()`) | visible-when | helper / placeholder / options |
|---|---|---|---|---|---|---|
| `pdf_type` | `PDF Type` | `Select` | yes | `badge_list` (also `->default('badge_list')`) | always; `->reactive()` | options `badge_list` => `Badge List (Badges by Range)`, `box_labels` => `Box Labels (3 per A4 page)` |
| `payment_status` | `Payment Status Filter` | `Select` | yes | `all` (also `->default('all')`) | `fn ($get) => $get('pdf_type') === 'badge_list'` | options `all` => `All Badges`, `paid` => `Paid Badges Only`, `unpaid` => `Unpaid Badges Only` |
| `badge_ranges` (line 79) | `Badge Ranges` | `Textarea` (`->rows(3)`) | yes | `0-999,1000-1999,2000-2999,3000-3999,4000-4999` (line 42) | `fn ($get) => $get('pdf_type') === 'badge_list'` | placeholder `e.g., 1-1699,1700-2400,2401-3000`; helper text verbatim: `Enter comma-separated ranges (e.g., 1-1699,1700-2400). Each range will be on a separate page.` |
| `title` | `Title` | `TextInput` (inside `Grid::make(2)`) | no | `''` | `fn ($get) => $get('pdf_type') === 'box_labels'` | placeholder `e.g., "Badge Range 1-999"` |
| `subtitle` | `Subtitle` | `TextInput` (inside `Grid::make(2)`) | no | `''` | `fn ($get) => $get('pdf_type') === 'box_labels'` | placeholder `e.g., "Free Badges"` |
| `rows_per_column` | `Rows per Column` | `TextInput` `->numeric()` (inside `Grid::make(3)`) | no | `50` (also `->default(50)`) | `fn ($get) => $get('pdf_type') === 'badge_list'` | placeholder `50` |
| `columns` | `Number of Columns` | `TextInput` `->numeric()` (inside `Grid::make(3)`) | no | `12` (also `->default(12)`) | `fn ($get) => $get('pdf_type') === 'badge_list'` | placeholder `12` |
| `font_size` | `Font Size (px)` | `TextInput` `->numeric()` (inside `Grid::make(3)`) | no | `6` (also `->default(6)`) | `fn ($get) => $get('pdf_type') === 'badge_list'` | placeholder `6` |

**Notifications, verbatim, in source order:**

| Method | Title | Body | Status |
|---|---|---|---|
| `generateBadgeListPdf` | `Error` | `No event selected in the header.` | `danger` |
| `generateBadgeListPdf` | `No Data` | `No {$filterText} found for the current event.` where `$filterText` = `paid badges` / `unpaid badges` / `badges` (match on `payment_status`) | `warning` |
| `generateBadgeListPdf` | `Invalid Range Format` | `Please enter valid badge ranges in the format: 1-1699,1700-2400` | `danger` |
| `generateBadgeListPdf` | `No Badges in Ranges` | `No badges found within the specified ranges. Please check your range settings.` | `warning` |
| `generateBoxLabelsPdf` | `Error` | `Title is required for box labels.` | `danger` |

**Business logic and queries - badge list:**

- Event resolution: `getSelectedEvent()` (line 362) reads `session('filament.admin.selected_event_id')` (line 365); if falsy → `Event::latest('starts_at')->first()`.
- Query: `Badge::whereHas('fursuit', fn => where('event_id', $selectedEvent->id))->with(['fursuit.user.eventUsers' => fn => where('event_id', $selectedEvent->id)])`.
- Payment filter: `paid` → `whereState('status_payment', Paid::class)`; `unpaid` → `whereState('status_payment', Unpaid::class)`; `all` → no filter.
- In-PHP sort by `parseCustomId(custom_id)` → `[intPart0, intPart1]`; an empty `custom_id` sorts to `[999999, 999999]`.
- `parseRanges()` splits on `,`, then on `-`, requires exactly 2 parts and `start <= end`, key `"{start}-{end}"`, `usort` ascending by start.
- `groupBadgesByRangeAndAttendee()` drops badges with an empty `custom_id`; with custom ranges a badge outside every range is silently dropped; without custom ranges it falls back to computed 1000-wide buckets `intval(n/1000)*1000 .. +999`.

**mPDF geometry - badge list:**

```
format => 'A4', orientation => 'P',
margin_left => 5, margin_right => 5, margin_top => 5, margin_bottom => 5,
mode => 'utf-8', default_font => 'helvetica'
```

Views written, in order: `pdfs.badge-list-css` (as `\Mpdf\HTMLParserMode::HEADER_CSS`),
`pdfs.badge-list-header` (`HTML_BODY`, vars: `event`), then per range `$mpdf->AddPage()` (skipped for
the first) plus `pdfs.badge-list-range` (`HTML_BODY`, vars: `range`, `attendees`, `rowsPerColumn`,
`columns`, `fontSize`). Every rendered chunk is passed through `mb_convert_encoding($html, 'UTF-8', 'auto')`.
Download filename (line 308): `badge-list-{$selectedEvent->name}{$paymentStatusSuffix}-Y-m-d.pdf`
with suffix `-paid` / `-unpaid` / `` (empty for `all`), streamed via `response()->streamDownload`.
`$selectedEvent->name` is interpolated **unescaped**.

**mPDF geometry - box labels:**

```
format => [210, 94],   // 210mm wide (A4 width), 94mm tall (99mm - 5mm)
orientation => 'P',
margin_left => 5, margin_right => 5, margin_top => 5, margin_bottom => 5,
mode => 'utf-8', default_font => 'helvetica'
```

Single view `pdfs.box-labels` (vars `title`, `subtitle`), written with plain `WriteHTML()`. Title and
subtitle are pre-passed through `mb_convert_encoding($v, 'UTF-8', 'UTF-8')`; the whole HTML is
re-encoded only if `! mb_check_encoding($html, 'UTF-8')`. Download filename (line 359):
`box-label-`.`Str::slug($title)`.`-Y-m-d.pdf` - this one **does** slug the interpolated value.

**Custom blade views:**

- `resources/views/filament/pages/pdf-generator.blade.php` (32 lines) - `<x-filament-panels::page>` with an `<x-filament::section>` heading `PDF Generator`, description `Generate PDFs for badge management and box labeling`, then two static info callouts and `{{ $this->form }}`. Callout copy verbatim:
  - blue box, `h3` = `Badge List PDF`, body = `Generates a list of all free badges for the current event, grouped by ranges (0-999, 1000-1999, etc.) with 3 columns per page. Each row shows one attendee with all their badge numbers.`
  - green box, `h3` = `Box Labels PDF`, body = `Generates A4 pages with 3 labels per page for badge boxes. Each label takes 1/3 of the page and includes a configurable title and subtitle.`
- `resources/views/pdfs/badge-list-css.blade.php` (85 lines) - pure `<style>` block: 6px body font, `.page-header` (h1 10px, h2 8px, 1px bottom border), `.range-section`, `.range-header` (1px border, 8px bold), `.attendee-table` (`table-layout: fixed`, monospace, zebra `#f8f8f8` on even rows), `.attendee-cell` (`padding: 0.5px 2px`, `Courier New`, `white-space: pre`, right/bottom 1px `#ddd` borders, last-child no right border), `.no-data`, `.summary`.
- `resources/views/pdfs/badge-list-header.blade.php` (3 lines) - `<div class="page-header"><h1>{{ $event->name }}</h1><h2>Attendee Reference</h2></div>`. Literal string `Attendee Reference`.
- `resources/views/pdfs/badge-list-range.blade.php` (71 lines) - range header `{{ $range }} ({{ count($attendees) }} attendees)`; empty-state text `No attendees in this range.`; re-sorts `attendee_id`s numerically in-view (a duplicate of the PHP sort); chunks by `$rowsPerColumn` (default 50), pads/truncates chunks to `$numColumns` (default 12), computes `$maxRows = max(array_map('count', $columnData))`, renders `<table style="font-size: {{ $fontSize }}px">` with cell width `100 / $numColumns`%; each cell left-pads the pre-dash part with `&nbsp;` to 4 characters and emits it with `{!! !!}` (raw).
- `resources/views/pdfs/box-labels.blade.php` (59 lines) - a full standalone HTML document, `<title>Box Label - {{ $title }}</title>`, body sized `84mm × 200mm`, `.border` table-display box, `.label-title` 72pt bold, `.label-subtitle` 40pt `#666` rendered only when `$subtitle` is non-empty.
- `resources/views/pdfs/badge-list.blade.php` (165 lines) - **dead**; nothing references `view('pdfs.badge-list')`.

### 5.2 Badge Preview (`Pages/BadgePreview`)

`app/Filament/Pages/BadgePreview.php`, 105 lines.

**Nav:** group `Debug Tools` / label `Badge Preview` / icon `heroicon-o-identification` / sort `100` /
no navigation badge. **Route:** `/admin/badge-preview`, route name `filament.admin.pages.badge-preview`.
**Model:** `App\Models\Badge\Badge`, loaded ad hoc; the page is not resource-backed.
**Guards:** none - no `canAccess()`, no `shouldRegisterNavigation()`. Any panel user
(`is_admin || is_reviewer`) sees the `Debug Tools` group and can render **any** badge PDF by custom id.

No table, no filters, no row or bulk actions, no infolist, no relation managers. `getHeaderActions()`
is not defined - the buttons live in the blade view.

**Form sections:** no `Section` wrapper; flat schema, default state path (`$customId` is a public
Livewire property at line 27, not under a `statePath`).

| field | label | component | required | validation | default | notes |
|---|---|---|---|---|---|---|
| `customId` (line 40) | `Badge Custom ID` | `TextInput` | yes | `maxLength(255)` | none (`mount()` calls `$this->form->fill()` with no args) | placeholder verbatim: `Enter badge custom ID (e.g., ABC123)` |

**Page methods, invoked from blade via `wire:click`:**

- `loadBadge()` - `$this->validate()`, then `Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])->where('custom_id', $this->customId)->first()` (line 53).
- `downloadPdf()` - `redirect()->route('admin.badge-pdf.download', ['customId' => $this->customId])` (line 88).
- `viewPdf()` - `redirect()->route('admin.badge-pdf.view', ['customId' => $this->customId])` (line 103).

**Notifications, verbatim:**

| Method | Title | Body | Status |
|---|---|---|---|
| `loadBadge` | `Badge not found` | `No badge found with custom ID: ` . `$this->customId` | `danger` |
| `loadBadge` | `Badge loaded` | `Badge found for: ` . `$fursuitName` (the name run through `mb_convert_encoding($name, 'UTF-8', 'UTF-8')`) | `success` |
| `downloadPdf` | `No badge loaded` | `Please load a badge first` | `warning` |
| `viewPdf` | `No badge loaded` | `Please load a badge first` | `warning` |

**Custom blade view:** `resources/views/filament/pages/badge-preview.blade.php` (51 lines)

- Section heading `Load Badge` → `{{ $this->form }}` plus `<x-filament::button wire:click="loadBadge">Load Badge</x-filament::button>`.
- `@if($badge)` section heading `Badge Details`, a manual key/value list with these exact labels: `Custom ID:` `{{ $badge->custom_id }}`, `Fursuit Name:` `{{ $badge->fursuit->name }}`, `Species:` `{{ $badge->fursuit->species->name }}`, `Owner:` `{{ $badge->fursuit->user->name }}`, `Event:` `{{ $badge->fursuit->event->name }}`, `Badge Type:` `{{ $badge->fursuit->event->badge_class ?? 'EF28_Badge' }}`.
- Two buttons: `View PDF in Browser` (`wire:click="viewPdf"`, `target="_blank"`, icon `heroicon-o-eye`) and `Download PDF` (`wire:click="downloadPdf"`, colour `success`, icon `heroicon-o-arrow-down-tray`).

### 5.3 DB Service (`Pages/DbService`)

`app/Filament/Pages/DbService.php`, 113 lines.

**Nav:** group `Maintenance` / label and title `DB Service` / icon `heroicon-o-wrench-screwdriver` /
sort none / no navigation badge. **Route:** `/admin/db-service`, route name
`filament.admin.pages.db-service`.
**Model:** none directly; operates through `App\Services\FreeBadgeRepairService` over
`App\Models\EventUser`, `App\Models\Badge\Badge`, `App\Models\User`, `App\Models\Event`.

**Guards** - the only page-level gate in the panel:

```php
public static function canAccess(): bool { return (bool) (auth()->user()?->is_admin); }           // line 34
public static function shouldRegisterNavigation(): bool { return (bool) (auth()->user()?->is_admin); }  // line 39
```

Source comment (line 32): `Restrict the whole Maintenance group + this page to admins. The panel
itself also admits reviewers (User::canAccessPanel), so this extra gate is required.`
Locked in by `tests/Feature/DbServiceMaintenancePageTest.php` (admin 200, reviewer 403, nav hidden
for non-admin).

**Public Livewire state:** `?array $freeBadgeReport` (preview, null until generated),
`?array $freeBadgeResult` (null until applied), `bool $reviewingFreeBadges = false`.

No Filament form, no table, no `getHeaderActions()`. Every control is a
`<x-filament::button wire:click="...">` in the blade.

**Page methods - the repairs this page can run:**

| Method | Destructive? | What it does |
|---|---|---|
| `previewFreeBadgeFix()` (line 50) | no (read-only) | clears `freeBadgeResult`, calls `FreeBadgeRepairService::preview(Event::getActiveEvent())`, sets `reviewingFreeBadges = true`, fires the "Nothing to fix" notification when `affected_badge_count === 0` |
| `applyFreeBadgeFix()` (line 67) | **yes - mutates DB and wallets** | `FreeBadgeRepairService::repair(Event::getActiveEvent(), auth()->user())` |
| `cancelFreeBadgeFix()` | no | clears `reviewingFreeBadges` and `freeBadgeReport` |
| `resetFreeBadgeFix()` | no | clears report, result and review flag ("Run again") |
| `imageUrl(?string $path)` | no | delegates to `FreeBadgeRepairService::imageUrl()` - S3 `temporaryUrl(path, now()->addMinutes(15))`, falling back to `Storage::disk('s3')->url($path)`, then `null` |
| `formatEuro(int $cents)` | no | `'€'.number_format($cents / 100, 2)` |

**What `applyFreeBadgeFix` mutates** (`FreeBadgeRepairService::repair`, single `DB::transaction`):

- Selects `EventUser::with('user')->where('event_id', $event->id)->where('prepaid_badges', '>', 0)->lockForUpdate()->get()`.
- Per user, `analyseUser()` loads all badges via `whereHas('fursuit', user_id + event_id)` with `fursuit.species`; main badges = `whereNull('extra_copy_of')`; `toConvert = max(0, min(prepaid_badges - freeMainCount, paidMain->count()))`, taking the lowest-`id` paid main badges first.
- For each converted badge: `is_free_badge = true`, `total = 0`, `subtotal = 0`, `tax = 0`, `status_payment = Paid::class`, `paid_at = now()`, then **`saveQuietly()`** (bypasses model events and observers).
- Wallet credit when `oldTotal > 0` and a user exists: `$user->deposit($oldTotal, [...])` with meta `title` = `Prepaid badge fee correction`, `description` = `Refund of wrongly charged fee for badge #{$badge->id}`, plus `event_id`, `badge_id`, `reason => 'free_badge_fix'`.
- Activity log: `activity()->performedOn($badge)->causedBy($admin)->withProperties(['reason' => 'free_badge_fix', 'event_id', 'prepaid_badges', 'old_total', 'old_subtotal', 'old_tax', 'new_total' => 0, 'refunded_cents'])->log('Corrected wrongly charged prepaid badge to free')`. Log message verbatim: `Corrected wrongly charged prepaid badge to free`.
- Any `\Throwable` is caught and returned as `['success' => false, 'error' => $e->getMessage(), ...]` with all counters zeroed.
- `repair(null, …)` returns `error` verbatim: `No active event.`

**Notifications, verbatim:**

| Method | Title | Body | Status |
|---|---|---|---|
| `previewFreeBadgeFix` (only when `affected_badge_count === 0`) | `Nothing to fix` | `No wrongly-charged prepaid badges were found for the current event.` | `success` |
| `applyFreeBadgeFix` (success) | `Fix applied` | `Converted {$result['fixed_badge_count']} badge(s) for {$result['fixed_user_count']} user(s) to free.` | `success` |
| `applyFreeBadgeFix` (failure) | `Fix failed` | `$result['error'] ?? 'Unknown error.'` | `danger` |

**Confirm modal:** not a Filament modal - a browser `wire:confirm` on the apply button
(`db-service.blade.php:112`). Copy verbatim, with interpolation:

`Convert {{ $freeBadgeReport['affected_badge_count'] }} badge(s) to free and refund {{ $this->formatEuro($freeBadgeReport['total_refund_cents']) }}? This cannot be undone automatically.`

**Custom blade view:** `resources/views/filament/pages/db-service.blade.php` (125 lines)

- One `<x-filament::section>`, heading verbatim `Fix free badges`, description verbatim: `Finds badges that were charged the badge fee even though the owner had unused prepaid / free badge entitlement for the current event, converts them to free and refunds the wrongly charged amount to the owner's wallet. The change is logged (activity log + wallet transaction).`
- **Idle state** (`! $reviewingFreeBadges && ! $freeBadgeResult`): button `Fix free badges`, icon `heroicon-o-magnifying-glass`, `wire:click="previewFreeBadgeFix"`, `wire:loading.attr="disabled"`.
- **Result state** (`$freeBadgeResult`): on success a green panel with heading `Fix applied successfully.` and a bullet list - `Badges converted to free: {fixed_badge_count}`, `Users affected: {fixed_user_count}`, `Total refunded: {formatEuro(total_refunded_cents)}`. On failure a red panel `Fix failed.` plus `{{ $freeBadgeResult['error'] }}`. Then a grey button `Run again` (icon `heroicon-o-arrow-path`, `wire:click="resetFreeBadgeFix"`).
- **Review state** (`$reviewingFreeBadges && $freeBadgeReport`): three stat cards labelled `Affected badges` / `Affected users` / `Total to refund`, then (only when `affected_badge_count > 0`) a table with header cells `Image`, `Fursuit`, `Species`, `Owner`, `Badges (event)` (right-aligned), `Should be free` (right), `Should be paid` (right), `Refund` (right). Row cells map to `image` (via `$this->imageUrl($row['image'])`, `<img class="h-10 w-10 rounded-full object-cover">`, falling back to `asset('images/placeholder.png')`), `fursuit ?? '—'`, `species ?? '—'`, `owner ?? '—'`, `badges_total`, `should_be_free`, `should_be_paid`, `formatEuro(current_total)`.
- Buttons: green `Confirm & apply fix` (icon `heroicon-o-check`, `wire:click="applyFreeBadgeFix"`, the `wire:confirm` above, `wire:loading.attr="disabled"`), shown only when `affected_badge_count > 0`; grey `Cancel` (icon `heroicon-o-x-mark`, `wire:click="cancelFreeBadgeFix"`).

Only one repair exists on this page, despite the generic name.

## 6. Widgets

The dashboard is the stock `Filament\Pages\Dashboard` at `/admin`. `->discoverWidgets()` auto-registers
the three app widgets below; `->widgets([Filament\Widgets\StatsOverviewWidget::class])` additionally
registers the framework base class, which renders an empty stats strip.

None of the three widgets defines `canView()`, so all three are visible to reviewers as well as admins.
None overrides the polling interval, so all three inherit
`Filament\Widgets\Concerns\CanPoll::$pollingInterval = '5s'`.

### 6.1 `StatsOverview`

`app/Filament/Widgets/StatsOverview.php`, 74 lines. Extends `Filament\Widgets\StatsOverviewWidget`,
uses `App\Filament\Traits\HasEventFilter`. No `$sort` set (`null`), so it orders after explicitly-sorted
widgets.

**Queries, verbatim logic:**

- `$selectedEventId = static::getSelectedEventId()` → `session('filament_selected_event_id')`.
- `$currentEvent = $selectedEventId ? Event::find($selectedEventId) : Event::orderBy('starts_at', 'desc')->first();`
- `$previousEvent = $currentEvent ? Event::where('starts_at', '<', $currentEvent->starts_at)->orderBy('starts_at', 'desc')->first() : null;`
- `$currentEventBadges = Badge::whereHas('fursuit', fn ($q) => $q->where('event_id', $currentEvent->id))->count()` (0 when no event)
- `$currentEventFursuits = Fursuit::where('event_id', $currentEvent->id)->count()`
- `$currentEventPending = Fursuit::where('event_id', $currentEvent->id)->where('status', 'pending')->count()` (line 32) - a **raw string**, not the state class
- `$previousEventBadges` / `$previousEventFursuits`, same shape against `$previousEvent`
- `$badgeDiff = $currentEventBadges - $previousEventBadges`; `$fursuitDiff = $currentEventFursuits - $previousEventFursuits`

**Stats, ordered, all strings verbatim:**

| # | Label | Value | Description | descriptionIcon | Colour | URL |
|---|---|---|---|---|---|---|
| 1 | `Current Event` | `$currentEvent?->name ?? 'No Event'` | `Orders Open` / `Orders Closed` (via `$currentEvent->allowsOrders()`), or `No current event` when there is no event | `heroicon-m-check-circle` when event and `allowsOrders()`, else `heroicon-m-x-circle` | `success` / `danger` (same predicate) | - |
| 2 | `Current Event Badges` | `$currentEventBadges` | `+{$badgeDiff} vs {$previousEvent?->name}` when `> 0`; `{$badgeDiff} vs {$previousEvent?->name}` when `< 0`; `No previous event` when `== 0` | `heroicon-m-arrow-trending-up` / `heroicon-m-arrow-trending-down` / `heroicon-m-minus` | `success` / `danger` / `gray` | - |
| 3 | `Current Event Fursuits` | `$currentEventFursuits` | `+{$fursuitDiff} vs {$previousEvent?->name}` / `{$fursuitDiff} vs {$previousEvent?->name}` / `No previous event` | `heroicon-m-arrow-trending-up` / `heroicon-m-arrow-trending-down` / `heroicon-m-minus` | `success` / `danger` / `gray` | - |
| 4 | `Pending Approval` | `$currentEventPending` | `Awaiting review` | `heroicon-m-clock` | `warning` when `> 0` else `success` | `route('filament.admin.resources.fursuits.index')` |

### 6.2 `BadgeStatusChart`

`app/Filament/Widgets/BadgeStatusChart.php`, 87 lines. Extends `Filament\Widgets\ChartWidget`,
uses `HasEventFilter`. `protected static ?int $sort = 3;` (line 16). Heading verbatim:
`Current Event Badge Status`. Chart type `doughnut`.

**Query, verbatim:**

```php
Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
        $query->where('event_id', $currentEvent->id);
    })
    ->selectRaw('status_payment, status_fulfillment, COUNT(*) as count')
    ->groupBy(['status_payment', 'status_fulfillment'])
    ->get();
```

Event resolution is identical to `StatsOverview`: `session('filament_selected_event_id')` →
`Event::find()`, else `Event::orderBy('starts_at', 'desc')->first()`.

**No-event fallback dataset:** `['data' => [0], 'backgroundColor' => ['rgb(156, 163, 175)']]`,
labels `['No Active Event']`.

**Label construction:** `ucfirst($status->status_payment).' / '.ucfirst($status->status_fulfillment)`
(e.g. `Paid / Pending`).

**Hardcoded colour ramp** (line 46, used in order, `array_slice($colors, 0, count($statusData))` at
line 64): `rgb(239, 68, 68)` red, `rgb(245, 158, 11)` amber, `rgb(59, 130, 246)` blue,
`rgb(16, 185, 129)` emerald, `rgb(139, 92, 246)` violet.

**Options:** `['plugins' => ['legend' => ['display' => true, 'position' => 'bottom']]]`.
No hardcoded date ranges.

### 6.3 `EventComparisonChart`

`app/Filament/Widgets/EventComparisonChart.php`, 98 lines. Extends `Filament\Widgets\ChartWidget`,
uses `HasEventFilter`. `protected static ?int $sort = 2;` (line 17). Heading verbatim:
`Event Comparison`. Chart type `bar`.

**Queries, verbatim:**

- `$currentEvent` - the same session/latest resolution as the other two widgets.
- `$previousEvent = Event::where('starts_at', '<', $currentEvent->starts_at)->orderBy('starts_at', 'desc')->first()`
- `$currentBadgeCount = Badge::whereHas('fursuit', fn ($query) => $query->where('event_id', $currentEvent->id))->count()`
- `$currentFursuitCount = Fursuit::where('event_id', $currentEvent->id)->count()`
- the previous-event equivalents, added as a second dataset only if `$previousEvent` exists.

**Labels:** `['Badges', 'Fursuits']` (fixed, two bars).
**Datasets:** current event → `label` = `$currentEvent->name`, `backgroundColor` `rgba(59, 130, 246, 0.8)`;
previous event → `label` = `$previousEvent->name`, `backgroundColor` `rgba(16, 185, 129, 0.8)`.
**No-event fallback:** single dataset `label` verbatim `No Events`, `data` `[0, 0]`,
`backgroundColor` `rgba(156, 163, 175, 0.8)`, labels `['Badges', 'Fursuits']`.
**Options:** `['plugins' => ['legend' => ['display' => true]], 'scales' => ['y' => ['beginAtZero' => true]]]`.
No hardcoded date ranges.

## 7. Cross-cutting behaviour

### 7.1 Polling

`->poll(` appears exactly three times under `app/Filament`:

| Surface | Interval | Line |
|---|---|---|
| `BadgeResource` table | `5s` | `BadgeResource.php:490` |
| `PrintJobResource` table | `5s` | `PrintJobResource.php:227` |
| `PrintBatchResource` table | `10s` | `PrintBatchResource.php:217` |

Every other table has **no poll**, including `PrinterResource` - the one screen that tells you the
hardware is jammed never refreshes itself - and `ViewPrintBatch` with its `PrintJobsRelationManager`,
so staff watching a live run see a frozen card list until they reload.

All three widgets inherit `Filament\Widgets\Concerns\CanPoll::$pollingInterval = '5s'` because none
overrides it. An open dashboard therefore re-runs four count queries and one `GROUP BY` over the
whole badges table every 5 seconds, per tab.

### 7.2 Notification copy

Every `Notification::make()` in the panel, in one place. Titles and bodies are verbatim.

| Surface | Status | Title | Body |
|---|---|---|---|
| `PrintJobResource` `retry` | success | `Created retry job #{$retryJob->id}` | none |
| `PrintBatchResource` `pause` | success | `Batch paused` | none |
| `PrintBatchResource` `resume` | success | `Batch resumed` | none |
| `PrintBatchResource` `cancel` (success) | success | `Batch cancelled` | none |
| `PrintBatchResource` `cancel` (failure) | danger | `Cannot cancel a batch that is {$record->status->label()}` | none |
| `PrintJobsRelationManager` `verify` | success | `Card verified` | none |
| `CheckoutResource` `print` and `ViewCheckout` `print` | danger | `No receipt printer found` | `Please configure an active receipt printer first.` |
| `CheckoutResource` `print` and `ViewCheckout` `print` | success | `Receipt added to print queue` | `Receipt for checkout #{$record->id} has been queued for printing.` |
| `ViewFursuit` `Approve Rejected` | success | `Rejected fursuit approved successfully` | none |
| `ViewFursuit` `Send Notification` (approved) | success | `Approval notification sent successfully` | none |
| `ViewFursuit` `Send Notification` (rejected) | success | `Rejection notification sent successfully` | none |
| `PdfGenerator` `generateBadgeListPdf` | danger | `Error` | `No event selected in the header.` |
| `PdfGenerator` `generateBadgeListPdf` | warning | `No Data` | `No {$filterText} found for the current event.` |
| `PdfGenerator` `generateBadgeListPdf` | danger | `Invalid Range Format` | `Please enter valid badge ranges in the format: 1-1699,1700-2400` |
| `PdfGenerator` `generateBadgeListPdf` | warning | `No Badges in Ranges` | `No badges found within the specified ranges. Please check your range settings.` |
| `PdfGenerator` `generateBoxLabelsPdf` | danger | `Error` | `Title is required for box labels.` |
| `BadgePreview` `loadBadge` | danger | `Badge not found` | `No badge found with custom ID: {$this->customId}` |
| `BadgePreview` `loadBadge` | success | `Badge loaded` | `Badge found for: {$fursuitName}` |
| `BadgePreview` `downloadPdf` / `viewPdf` | warning | `No badge loaded` | `Please load a badge first` |
| `DbService` `previewFreeBadgeFix` | success | `Nothing to fix` | `No wrongly-charged prepaid badges were found for the current event.` |
| `DbService` `applyFreeBadgeFix` (success) | success | `Fix applied` | `Converted {$result['fixed_badge_count']} badge(s) for {$result['fixed_user_count']} user(s) to free.` |
| `DbService` `applyFreeBadgeFix` (failure) | danger | `Fix failed` | `$result['error'] ?? 'Unknown error.'` |

Resources with **no** custom notifications at all: `BadgeResource` (including its `printBadge` and
`printBadgeBulk` actions - the user gets no feedback), `EventResource`, `FursuitResource` at
resource level, `SpecialCodeResource`, `MachineResource` (archive/restore/login-link are silent),
`PrinterResource`, `StaffResource`, `SumUpReaderResource`, `TseClientResource` (including `createnew`),
`UserResource`. Those surfaces fall back to Filament's built-in `Saved` / `Created` / `Deleted` toasts
where a stock action is used, and to silence where a custom action is used.

### 7.3 The event filter

Fully described in §2.1. Summary for the rebuild:

- One session key, `filament_selected_event_id`, written by `App\Http\Middleware\FilamentEventSelector` on every `/admin` request, never null in practice.
- The topbar `<select>` sets it through a full page navigation with `?selected_event_id=`, not Livewire.
- Read through `App\Filament\Traits\HasEventFilter` by two resources and three widgets.
- The `all` option is unreachable and its downstream branches are dead code.
- `PdfGenerator` reads a different key and is therefore never scoped by the selector.
- Scope lives in the session, not the URL, so admin links are not shareable or deep-linkable and two browser tabs share one selection.

### 7.4 S3 image handling

| Surface | Disk | Visibility | Notes |
|---|---|---|---|
| `BadgeResource` column 1 `fursuit.image` | `s3` | `private` | `->circular()->size(40)->defaultImageUrl(url('/images/placeholder.png'))->checkFileExistence(false)` |
| `FursuitResource` column 5 `image` | `s3` | `private` | `->circular()->checkFileExistence(false)` |
| `FursuitResource` infolist `ImageEntry::make('image')` | `s3` | `private` | `->height('100%')->width('100%')->alignCenter()` |
| `FursuitResource` form `FileUpload::make('image')` | **default disk** | - | **No `->disk()` call.** Upload target and display source are different disks |
| `DbService` blade review table | `s3` | - | via `FreeBadgeRepairService::imageUrl()`: `temporaryUrl(path, now()->addMinutes(15))`, falling back to `Storage::disk('s3')->url($path)`, then `null`; `asset('images/placeholder.png')` as the final fallback |
| `BadgeResource::printBadgeWithPrinter()` | **default disk** | - | `Storage::put('badges/'.$badge->id.'.pdf', …)` - not s3, unlike everything else. Dead code today |

`config/filament.php` sets `default_filesystem_disk` = `env('FILAMENT_FILESYSTEM_DISK', 'public')`.
Check whether `FILAMENT_FILESYSTEM_DISK` is set in the deployment environment before changing anything
that depends on the default disk.

### 7.5 Money and the cents convention

Every money column in the database is an integer number of cents (`unsignedBigInteger subtotal` /
`tax` / `total` on `checkouts`, per `2024_09_10_162922_create_checkouts_table.php`; the same shape on
`badges`). There is no money value object and no cast; the conversion is done by hand at each render
site, and it is not done consistently.

`->money(` appears six times under `app/Filament`:

| Site | Call | Correct? |
|---|---|---|
| `CheckoutResource.php:159` | `->money('EUR', divideBy: 100)` | yes |
| `CheckoutResource.php:162` (Sum summarizer) | `->money('EUR', divideBy: 100)` | yes |
| `ItemsRelationManager.php:72` | `->money('EUR', divideBy: 100)` | yes |
| `ItemsRelationManager.php:77` | `->money('EUR', divideBy: 100)` | yes |
| `ItemsRelationManager.php:82` | `->money('EUR', divideBy: 100)` | yes |
| `BadgeResource.php:354` | `->money('EUR')` | **no - no `divideBy`** |

Form-side conversions:

- `BadgeResource` form `total` / `subtotal` / `tax` (lines 129, 137, 145): `->formatStateUsing(fn ($state) => number_format($state / 100, 2))` on read, with **no `dehydrateStateUsing`** on write. `subtotal` and `tax` are disabled so they never dehydrate; `total` is enabled and does.
- `CheckoutResource` form `subtotal` / `tax` / `total` (lines 63-79): `->prefix('€')->numeric()->disabled()` with **no division at all**, so the view page shows raw cents.
- `EventResource` form `cost`: `->numeric()->step(0.01)->suffix('€')` - this column is in euros, not cents.
- `DbService::formatEuro(int $cents)`: `'€'.number_format($cents / 100, 2)`.

Currency is hardcoded as `EUR` in six call sites and `€` as a prefix/suffix in another six. There is
no locale or currency configuration.

### 7.6 Timezone

There is no timezone declaration anywhere in `app/Filament`. No `->timezone()` call on any
`DateTimePicker`, `DatePicker` or `TextColumn::dateTime()`. Everything renders in the app's configured
timezone via Laravel defaults.

Date/time formats used, so the rebuild can match them:

| Format string | Where |
|---|---|
| `M j, Y` | `BadgeResource` `created_at` |
| `M j, Y H:i` | `BadgeResource` `printed_at`, `picked_up_at` |
| `M j, H:i` | `PrintBatchResource` `started_at`, `completed_at` |
| `d.m.Y H:i` | `EventResource` `mass_printed_at`, `order_starts_at`, `order_ends_at` |
| `->date()` (default `M j, Y`) | `EventResource` `starts_at`, `ends_at` |
| `->dateTime()` (app default) | `CheckoutResource` `created_at`, `PrintJobResource` `created_at` / `printed_at`, `UserResource` `created_at` / `updated_at`, `StaffResource` `created_at`, `PrintBatchResource` infolist Timing entries |
| `->dateTime()->since()` (renders as a human diff; `since()` wins) | `PrinterResource` `last_state_update`, `StaffResource` `last_login_at`, `RfidTagsRelationManager` `last_login_at` and `created_at` |
| `->diffForHumans()` in a column description | `EventResource` `starts_at`, `ends_at`, `mass_printed_at`, `order_starts_at`, `order_ends_at` |
| `Y-m-d` in a filename | `PdfGenerator` both download filenames |

`EventResource` uses `DatePicker` (date-only, cast to midnight) for `starts_at` / `ends_at` while
`Event::state()` compares `$this->ends_at < now()`.

### 7.7 Soft deletes

| Model | `SoftDeletes`? | Admin exposure |
|---|---|---|
| `App\Models\Badge\Badge` | **yes** | No `TrashedFilter`, no `withoutGlobalScopes([SoftDeletingScope::class])`, no restore action. Soft-deleted badges are invisible and unrecoverable from the panel |
| `App\Models\Fursuit\Fursuit` | **yes** | Same: no trashed filter, no restore action. `EditFursuit`'s Delete performs a soft delete and triggers `FursuitObserver`'s cascade to the fursuit's badges (guarded by `Fursuit::$isCascadingDelete`) |
| `App\Models\Event` | no | Hard delete from a default-copy confirm modal and via bulk delete, despite `fursuits`, `badges` (hasManyThrough) and `eventUsers` hanging off events |
| `App\Models\User` | no | Hard delete, row and bulk. Users own fursuits, badges and wallet transactions |
| `App\Models\Staff` | no | Hard delete, row and bulk. `rfid_tags.staff_id` is `onDelete('cascade')` |
| `App\Models\RfidTag` | no | Hard delete, row and bulk |
| `App\Models\Machine` | no - bespoke `archived_at` column, **not** Laravel SoftDeletes | Archive/restore actions only; no delete action anywhere in the resource. The default list is unarchived only because the ternary filter's `blank` branch applies `notArchived()` |
| `App\Domain\Printing\Models\Printer` | no | Hard delete (Edit page and bulk) |
| `App\Domain\Printing\Models\PrintJob` | no | Hard delete (row Delete, Edit page, bulk) |
| `App\Domain\Printing\Models\PrintBatch` | no | No delete action at all |
| `App\Domain\Checkout\Models\Checkout` | no | `canDelete` hard `false` |
| `App\Domain\Checkout\Models\TseClient` | no | No delete action - `EditTseClient::getHeaderActions()` returns `[]` specifically to remove the default |
| `App\Models\SumUpReader` | no | Hard delete via bulk and the Edit page |
| `App\Domain\CatchEmAll\Models\SpecialCode` | no | Hard delete, row and bulk |

### 7.8 Activity log

`spatie/laravel-activitylog` is used in exactly three places reachable from the panel.

1. **`Fursuit`** carries `LogsActivity` with `LogOptions::defaults()->logOnly(['name', 'image', 'species_id'])`. Its state transitions write manual entries: `Fursuit approved`, `Fursuit rejected` (with property `reason`), `Fursuit approved (was previously rejected)`. These are surfaced by `ActivitiesRelationManager` on the fursuit view and edit pages, which is a **writable** table - create, edit, delete and bulk delete are all enabled on the audit trail.
2. **`FreeBadgeRepairService::repair()`** writes one entry per converted badge: `activity()->performedOn($badge)->causedBy($admin)->withProperties([...])->log('Corrected wrongly charged prepaid badge to free')`. The badge itself is written with `saveQuietly()`, so the automatic log is skipped and this manual entry is the only record.
3. Nothing else. There is **no** activity logging on `Event`, `Badge` (from the admin edit form), `Machine` archive/unarchive, `TseClient` create or edit, `SumUpReader`, `Staff`, `Printer` `is_active` toggles, `PrintJob` status edits, or batch pause/resume/cancel.

### 7.9 Other cross-cutting behaviour worth listing

- **Column visibility.** Many columns use `toggleable(isToggledHiddenByDefault: true)`. Filament persists the user's choice per session. Decide explicitly whether to reimplement or drop.
- **Default-on filter.** `FursuitResource`'s status filter defaults to `'pending'` (line 172), so the fursuit list never shows the full set on first load. Easy to lose in a rewrite, and easy to mistake for missing data.
- **Inline write columns.** `PrinterResource`'s `is_active` is a `CheckboxColumn` (line 96) that writes to the DB on a single click, with no confirm, no notification and no audit trail. It is the only inline-write column in the panel.
- **`selectCurrentPageOnly()`.** Only `BadgeResource` sets it (line 487), so bulk print is capped at one page (max 100 rows). That is a deliberate operational constraint.
- **Unpaginated tables.** `PrinterResource` (line 115), `MachineResource` (line 78), `StaffResource` (line 110) and `ItemsRelationManager` all set `->paginated(false)`.
- **Disabled global search.** `PrinterResource` (line 114) and `MachineResource` (line 77) both call `->searchable(false)`, which hides the search box and makes their columns' own `searchable()` unreachable.
- **Global search.** No resource declares `$recordTitleAttribute` or globally-searchable attributes, so Filament's global search returns nothing anywhere in this panel.
- **Two badge-column APIs.** `PrinterResource` and `PrintJobResource` use `Tables\Columns\BadgeColumn` (deprecated in Filament v3) with the `->colors([colour => value])` map; `PrintBatchResource`, `PrintJobsRelationManager`, `BadgeResource`, `CheckoutResource` and `FursuitResource` use `TextColumn->badge()` with `->color(closure)`. Two implementations with different colour APIs.
- **`'secondary'` is not a valid Filament v3 colour** and is used in three places: `PrinterResource` status `offline`, `PrintJobResource` type `receipt` and status `retrying`. Those badges render unstyled today.
- **State machines are bypassed by three admin forms.** `BadgeResource`'s `status_fulfillment` / `status_payment` selects, `FursuitResource`'s `status` TextInput, and `PrintJobResource`'s `status` select all write raw state strings through the default `EditRecord` save, with no `transitionTo()` and no `mutateFormDataBeforeSave`.
- **Enum label vocabularies diverge.** `PrintJobResource` prints raw `->value` strings; `PrintJobsRelationManager` prints `->label()`. The same job reads `queued` in one list and `Claimed` in the other.
- **Route names are hardcoded** in five places: `filament.admin.resources.fursuits.view` and `filament.admin.resources.print-jobs.index` (`BadgeResource`), `filament.admin.resources.fursuits.view` and `.index` (`ViewFursuit`, four call sites), `filament.admin.resources.fursuits.index` (`StatsOverview` stat 4). Plus one hardcoded panel path, `'/admin/users?tableSearch='` (`BadgeResource.php:261`), and the Filament-internal deep-link query shape `tableFilters[printable_id][value]` / `tableFilters[printable_type][value]`.

### 7.10 Enum mappings surfaced by the panel

#### `App\Enum\PrintJobStatusEnum` (60 lines, backed by `string`)

| Case | Value | `label()` | Colour in `PrintJobsRelationManager` | Colour in `PrintJobResource` (BadgeColumn) |
|---|---|---|---|---|
| `Pending` | `pending` | `Pending` | `gray` (default arm) | `warning` |
| `Queued` | `queued` | **`Claimed`** | `primary` | `info` |
| `Printing` | `printing` | `Printing` | `primary` | `primary` |
| `Printed` | `printed` | `Printed` | `success` | `success` |
| `Failed` | `failed` | `Failed` | `danger` | `danger` |
| `Cancelled` | `cancelled` | `Cancelled` | `gray` | **not mapped** |
| `Retrying` | `retrying` | `Retrying` | `warning` | `secondary` (not a valid v3 colour) |

Behaviour helpers: `canTransitionTo()` - `Pending → [Queued, Cancelled]`;
`Queued → [Printing, Pending, Failed, Cancelled]` (back to Pending covers an expired lease);
`Printing → [Printed, Failed, Pending]`; `Failed → [Retrying, Cancelled]`;
`Retrying → [Queued, Cancelled]`; `Printed` and `Cancelled` → nothing.
`isTerminal()` = Printed|Cancelled. `isActive()` = Queued|Printing|Retrying. `holdsLease()` = Queued|Printing.

#### `App\Enum\PrintBatchStatusEnum` (56 lines, backed by `string`)

| Case | Value | `label()` | Colour (`PrintBatchResource::statusColor()`) |
|---|---|---|---|
| `Draft` | `draft` | `Draft` | `gray` |
| `Ready` | `ready` | **`Ready to print`** | `info` |
| `Printing` | `printing` | `Printing` | `primary` |
| `Paused` | `paused` | `Paused` | `warning` |
| `Completed` | `completed` | `Completed` | `success` |
| `Cancelled` | `cancelled` | `Cancelled` | `danger` |

Behaviour helpers: `canTransitionTo()` - `Draft → [Ready, Cancelled]`;
`Ready → [Printing, Draft, Cancelled]`; `Printing → [Paused, Completed, Cancelled]`;
`Paused → [Printing, Cancelled]`; `Completed` and `Cancelled` → nothing.
`isClaimable()` = `Printing` only. `isTerminal()` = Completed|Cancelled.

#### `App\Enum\PrinterStatusEnum` (104 lines, backed by `string`)

Note this enum uses `getLabel()` / `getIcon()` / `getSeverity()`, not `label()`.

| Case | Value | `getLabel()` | `getSeverity()` | `getIcon()` (PrimeVue class) | Colour in `PrinterResource` BadgeColumn |
|---|---|---|---|---|---|
| `IDLE` | `idle` | `Ready` | `success` | `pi pi-check-circle` | `success` |
| `WORKING` | `working` | `Working` | `info` | `pi pi-spin pi-spinner` | `warning` |
| `PAUSED` | `paused` | `Paused` | `warning` | `pi pi-pause-circle` | `danger` |
| `OFFLINE` | `offline` | `Offline` | `danger` | `pi pi-exclamation-triangle` | `secondary` (invalid v3 colour) |
| `ONLINE` | `online` | `Online` | `success` | `pi pi-check-circle` | **not mapped** |
| `BUSY` | `busy` | `Busy` | `info` | `pi pi-spin pi-spinner` | **not mapped** |
| `PROCESSING` | `processing` | `Processing` | `info` | `pi pi-spin pi-spinner` | `info` |
| `ERROR` | `error` | `Error` | `danger` | `pi pi-times-circle` | **not mapped** |
| `MEDIA_EMPTY` | `media-empty` | `Media Empty` | `warning` | `pi pi-minus-circle` | **not mapped** |
| `MEDIA_JAM` | `media-jam` | `Media Jam` | `warning` | `pi pi-exclamation-triangle` | **not mapped** |
| `COVER_OPEN` | `cover-open` | `Cover Open` | `warning` | `pi pi-exclamation-triangle` | **not mapped** |
| `UNKNOWN` | `unknown` | `Unknown` | `secondary` | `pi pi-question-circle` | `gray` |

`fromStatusCode(string $code)` maps `online/offline/processing/media-empty/media-jam/cover-open/paused/busy/error`
to the matching case, everything else to `UNKNOWN`. `requiresAttention()` =
`OFFLINE, ERROR, MEDIA_EMPTY, MEDIA_JAM, COVER_OPEN, PAUSED`. The icon strings are PrimeIcons
(POS/Vue), not heroicons - unusable in Filament, and this enum is the only place the presentation is
defined.

#### `App\Enum\PrintJobTypeEnum` (9 lines)

| Case | Value | Label | Colour |
|---|---|---|---|
| `Badge` | `badge` | none - hardcoded `'Badge'` in both resources | `primary` (`PrintJobResource`) |
| `Receipt` | `receipt` | none - hardcoded `'Receipt'` | `secondary` (invalid v3 colour) |

No `label()` method exists; every label is duplicated by hand in `PrinterResource::form()`,
`PrintJobResource::form()` and `PrintJobResource::table()`.

#### `App\Enum\PrintCompletionSourceEnum` (43 lines) - surfaced only in the relation manager's "Finished by" column

| Case | Value | `label()` | Notes |
|---|---|---|---|
| `Firmware` | `firmware` | `Confirmed by printer` | `isAuthoritative()` true - SNMP-confirmed |
| `SpoolerOnly` | `spooler_only` | `Spooler only` | the spooler consumed the job, firmware never confirmed |
| `Operator` | `operator` | `Marked done by staff` | a human declared it done |

No colour mapping anywhere; the column is plain text.

#### `App\Enum\PrintVerificationSourceEnum` (28 lines) - surfaced only as the Verified icon's tooltip

| Case | Value | `label()` | Notes |
|---|---|---|---|
| `Camera` | `camera` | `Verified by camera` | webcam matched the card against the rendered badge |
| `Operator` | `operator` | `Verified by staff` | written by the relation manager's `verify` action |

#### `App\Enum\PrinterConditionEnum` (92 lines) - **not surfaced anywhere in the Filament admin**, despite `printers.condition` existing since `2026_08_05_100300`

| Case | Value | `label()` | `remedy()` | `isStop()` | `isWarning()` |
|---|---|---|---|---|---|
| `Ok` | `ok` | `Ready` | - | no | no |
| `Printing` | `printing` | `Printing` | - | no | no |
| `RibbonLow` | `ribbon_low` | `Ribbon low` | `Replace the colour ribbon.` | no | **yes** |
| `RibbonOut` | `ribbon_out` | `Ribbon empty` | `Replace the colour ribbon.` | **yes** | no |
| `FilmLow` | `film_low` | `Transfer film low` | `Replace the transfer film.` | no | **yes** |
| `FilmOut` | `film_out` | `Transfer film empty` | `Replace the transfer film.` | **yes** | no |
| `CardsLow` | `cards_low` | `Card hopper low` | `Refill the card hopper.` | no | **yes** |
| `CardsOut` | `cards_out` | `Out of cards` | `Refill the card hopper.` | **yes** | no |
| `CardJam` | `card_jam` | `Card jam` | `Open the printer and clear the jammed card.` | **yes** | no |
| `CoverOpen` | `cover_open` | `Cover open` | `Close the printer cover.` | **yes** | no |
| `RejectBinFull` | `reject_bin_full` | `Reject bin full` | `Empty the reject bin.` | **yes** | no |
| `ServiceRequired` | `service_required` | `Service required` | `Printer needs servicing, check the front panel.` | **yes** | no |
| `Offline` | `offline` | `Printer offline` | `Check printer power and network cable.` | **yes** | no |
| `Initializing` | `initializing` | `Warming up` | - | **yes** | no |
| `Unknown` | `unknown` | `Unknown state` | `Check the printer front panel for a message.` | **yes** | no |

Fifteen cases, not fourteen: `Initializing` was missing from this table on the first pass.
It matters twice over. `Status::printerCondition()` already handles it, and the rail's
"stopped printers" chip counts `isStop()`, so a printer that is merely warming up is
counted as stopped there - correct for "cannot print right now", surprising if the chip is
read as "broken". `severity()` is the one place it is separated out: it returns `info`
rather than `danger`, because red would send somebody to a machine that needs nothing
doing to it.

No colour mapping is defined on this enum. Remedies are shown in the POS alert, never in admin.

#### `App\Enum\PrinterStatusSeverityEnum` (21 lines) - not used by any Filament resource

| Case | Value | `getLevel()` |
|---|---|---|
| `Fatal` | `FATAL` | 4 |
| `Error` | `ERROR` | 3 |
| `Warning` | `WARN` | 2 |
| `Info` | `INFO` | 1 |

## 8. Framework coupling outside `app/Filament`: the removal checklist

Every file outside `app/Filament/` and `resources/views/filament/` that references Filament, and what
it uses it for.

| # | File | Filament symbols used | What for |
|---|---|---|---|
| 1 | `app/Providers/Filament/AdminPanelProvider.php` | `Filament\PanelProvider`, `Filament\Panel`, `Filament\Pages`, `Filament\Widgets`, `Filament\Support\Colors\Color`, `Filament\View\PanelsRenderHook`, `Filament\Http\Middleware\{Authenticate, DisableBladeIconComponents, DispatchServingFilamentEvent}` | The entire panel definition: `->default()`, id `admin`, path `admin`, primary colour `Color::Blue`, `maxContentWidth('100%')`, resource/page/widget auto-discovery, the middleware stack including `FilamentEventSelector`, auth middleware, and the two render hooks. **Delete wholesale**, but the event-selector behaviour and the middleware must be re-homed |
| 2 | `app/Http/Middleware/FilamentEventSelector.php` | none (Filament only in the class name) | The single source of truth for the admin-wide event scope. Pure Laravel, portable as-is, just rename. Registered only inside the panel's `->middleware([...])`, so it stops running the moment the panel goes |
| 3 | `app/Models/User.php:74-77` | `Filament\Models\Contracts\FilamentUser`, `Filament\Panel` | `class User extends Authenticatable implements Customer, FilamentUser, WalletFloat` and `canAccessPanel()`. **This is the admin authorization rule** - `is_admin \|\| is_reviewer` - and it is the only place it is expressed. Must be reimplemented as middleware or a gate |
| 4 | `app/Models/Badge/State_Payment/BadgePaymentStatusState.php` | `Filament\Support\Contracts\HasColor`, `Filament\Support\Contracts\HasIcon` | Abstract state base `implements HasColor, HasIcon` with `abstract getColor(): string\|array\|null` and `abstract getIcon(): ?string`. Every concrete payment state (`Paid`, `Unpaid`) implements these purely so Filament badge columns can colour and icon themselves. Dropping Filament means dropping the interfaces but **keeping the methods** - they are the colour/icon contract the UI needs |
| 5 | `app/Models/Badge/State_Fulfillment/BadgeFulfillmentStatusState.php` | `Filament\Support\Contracts\HasColor`, `Filament\Support\Contracts\HasIcon` | Same for the fulfillment states (`Pending`, `Processing`, `ReadyForPickup`, `PickedUp`, `Printed`). Also carries the `config()` transition map, which is unrelated to Filament and must not be lost when the interfaces are stripped |
| 6 | `config/filament.php` | whole file | `broadcasting.echo` (fully commented out), `default_filesystem_disk` = `env('FILAMENT_FILESYSTEM_DISK', 'public')`, `assets_path` = `null`, `cache_path` = `base_path('bootstrap/cache/filament')`, `livewire_loading_delay` = `'default'`, `system_route_prefix` = `'filament'`. `FILAMENT_FILESYSTEM_DISK` may be set in the deployment env - check before deleting |
| 7 | `public/css/filament-custom.css` and `resources/css/filament-custom.css` (8 lines each) | Filament CSS classes | Injected via the `HEAD_END` render hook, served from `public/`; the `resources/` copy is a separate committed file that Vite does not build. Contents: `.fi-ta-cell, .fi-ta-cell div { padding-top: 2px !important; padding-bottom: 2px !important; }`. A deliberate ultra-dense table row height worth preserving |
| 8 | `resources/css/pos.css` | comments only | Two comments (lines 5, 53) explaining that the POS colour tokens are scoped so "the public site and Filament keep their own look" / "keep the ramp defined in app.css". No code coupling; the comments go stale |
| 9 | `routes/web.php` lines 42-49 | comment `// Admin badge PDF routes (used by Filament)` | Registers `admin.badge-pdf.view` and `admin.badge-pdf.download` under `middleware(['auth'])->prefix('admin')`, pointing at `App\Http\Controllers\Admin\BadgePdfController`. These are the redirect targets of `BadgePreview::viewPdf()` / `downloadPdf()`. They are guarded by `auth` only, **not** by `canAccessPanel`, so any logged-in attendee can fetch any badge PDF by custom id |
| 10 | `tests/Feature/DbServiceMaintenancePageTest.php` | `App\Filament\Pages\DbService`, `Livewire\Livewire::test()`, `route('filament.admin.pages.db-service')` | 4 tests: admin 200, reviewer 403, `shouldRegisterNavigation()` false/true by role, and a Livewire round-trip `previewFreeBadgeFix` → `applyFreeBadgeFix` asserting `freeBadgeReport.affected_badge_count === 1`, `freeBadgeResult.success === true`, `fixed_badge_count === 1`, plus DB assertions `is_free_badge === true` and `total === 0`. This is the **only** automated coverage of the DB Service repair path |
| 11 | `app/Filament/Traits/HasEventFilter.php` (inside app/Filament but consumed by widgets) | none (pure Laravel) | `getSelectedEventId()`, `getSelectedEvent()`, `applyEventFilter()`. Portable logic; move it out of the Filament namespace rather than delete |
| 12 | `app/Filament/Components/EventSelector.php` | `Filament\View\Component` | A `Filament\View\Component` subclass whose `render()` returns the event-selector view. **Dead code** - `AdminPanelProvider` inlines the same `view(...)` call in the render hook and never references this class |
| 13 | `resources/views/filament/components/event-selector.blade.php` (34 lines) | rendered by the panel render hook | The header event `<select>`. Option text `{{ $event->name }} ({{ $event->starts_at->format('Y') }})` plus, for the selected option only, `✓ Orders Open` / `✗ Orders Closed` from `$event->allowsOrders()`. Navigation is an inline `onchange` calling a globally-defined `updateQueryStringParameter(uri, key, value)` |
| 14 | `composer.json` | `"filament/filament": "^3.2"`, `"flowframe/laravel-trend": "^0.4.0"` | Filament v3.2 is the panel. `flowframe/laravel-trend` is a Filament-ecosystem charting helper that is **referenced nowhere** in `app/` or `resources/` - zero hits for `Flowframe` or `Trend::`. Remove with the panel |

**Removed implicitly with the panel** (no source reference, but they exist and will break):

- Auto-generated route names `filament.admin.pages.pdf-generator`, `filament.admin.pages.badge-preview`, `filament.admin.pages.db-service`, `filament.admin.resources.fursuits.index` / `.view`, `filament.admin.resources.print-jobs.index`.
- `bootstrap/cache/filament` component cache and `public/css/filament` published assets.
- The stock `Filament\Pages\Dashboard` at `/admin` and its widget layout.
- Livewire, which on the web side is pulled in only for the admin panel. Check POS before removing.

## 9. Landmines and known-broken behaviour

Each row is something that is broken, dead, or surprising today. "Why it matters for the rewrite" is
the decision the rebuild has to make consciously: reproduce, fix, or drop.

### 9.1 Money

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 1 | **The badge `Total` column has no `divideBy: 100`.** `->money('EUR')` on a column stored in cents, so every badge total renders 100x too high: 300 cents shows as `EUR 300.00`. It is the only one of the six `->money(` call sites in `app/Filament` without `divideBy` | `app/Filament/Resources/BadgeResource.php:352-356` | A money bug on the primary badge list. The column is toggleable-hidden by default, which is why it has survived. Fix it, and make the cents convention explicit rather than per-call-site |
| 2 | **One checkout screen renders money two contradictory ways.** The read-only `Financial Details` section renders `subtotal` / `tax` / `total` as `TextInput`s with `->prefix('€')->numeric()` and **no division**, so the checkout VIEW page shows raw cents, while the same resource's table column at line 159 uses `divideBy: 100` | `app/Filament/Resources/CheckoutResource.php:63-79` vs `:159` | This is a fiscal record. Two renderings of the same number on one resource is a correctness bug and an audit hazard |
| 3 | **`BadgeResource`'s `total` form field is a money-corruption trap.** `total` is cents, the field renders `number_format($state/100, 2)` on read but has **no** `dehydrateStateUsing` to multiply back by 100. Saving the edit form unchanged writes `"3.00"` into an `unsignedBigInteger` cents column, so the badge total silently becomes 3 cents. `subtotal` and `tax` escape only because they are disabled | `app/Filament/Resources/BadgeResource.php:129` (and `:137`, `:145` for the disabled pair) | Any rewrite that keeps an editable total must round-trip the conversion. Also check the data: badges may already carry corrupted totals from this |

### 9.2 Fiscal and secret-bearing surfaces

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 4 | **The receipt print action writes `'type' => 'receipt'` as a raw string** in the same array where `'status'` uses `PrintJobStatusEnum::Pending`, even though `App\Enum\PrintJobTypeEnum::Receipt` exists. The printer lookup one block earlier uses the same raw string | `app/Filament/Resources/CheckoutResource.php:243-248` and `:229`; a byte-identical block in `app/Filament/Resources/CheckoutResource/Pages/ViewCheckout.php` | Two copies of the same untyped write. Renaming the enum value breaks both silently. Also: the whole action body is duplicated between the two files, so any fix has to be applied twice |
| 5 | **`tse_signature` is not a column on `checkouts`.** The migration `2025_08_23_154237_add_tse_compliance_fields_to_checkouts_table.php` creates `tse_start_signature` and `tse_end_signature`. The `TSE Signature` field is permanently blank, so the actual TSE signatures are never shown anywhere in admin | `app/Filament/Resources/CheckoutResource.php:92` | The fiscally load-bearing data is invisible today. Other never-surfaced fiscal columns: `tse_serial_number`, `tse_transaction_number`, `tse_signature_counter`, `tse_timestamp`, `tse_process_type`, `tse_process_data`, `fiskaly_data`, `remote_rev_count`, `payment_method_remote_id` |
| 6 | **The checkout status filter returns zero rows.** Options are keyed by FQCN (`App\Domain\Checkout\Models\Checkout\States\Active`) but the persisted column value is the Spatie `$name` string (`ACTIVE` / `FINISHED` / `CANCELLED`). Filament's `SelectFilter` issues a plain `whereIn('status', …)`; Spatie only translates names through the `whereState` scope, which is not used | `app/Filament/Resources/CheckoutResource.php:176` | Looks like a working filter, is not. Do not port the FQCN keying |
| 7 | **`createnew` fabricates a TSE client locally** with a random UUID as both `remote_id` and `serial_number`, `state` hardcoded `'REGISTERED'`, and never talks to Fiskaly. One click, no confirmation, no notification, no audit | `app/Filament/Resources/TseClientResource/Pages/ListTseClients.php:18-27` | It claims a TSE serial that does not exist upstream, and any checkout later signed against it inherits a fabricated serial. `tse:update-state` / `tse:change-admin-pin` are the real lifecycle |
| 8 | **`remote_id`, `serial_number` and `state` are freely editable** on the TSE edit form with no disable and no confirmation | `app/Filament/Resources/TseClientResource.php` form schema | Changing a serial or flipping `REGISTERED` → `DEREGISTERED` silently rewrites the identity of the security module past checkouts were signed under. German KassenSichV requires the recorded TSE serial to be traceable; nothing logs or prevents this |
| 9 | **The `Login Link` action mints `URL::signedRoute('pos.auth.machine.login', …)` and shows it in plaintext with a copy button.** Anyone holding the URL can authenticate as the POS machine. No expiry (`signedRoute`, not `temporarySignedRoute`), no confirmation, no audit entry, no revocation path | `app/Filament/Resources/MachineResource/Pages/EditMachine.php:24` | The single most sensitive thing in the panel |
| 10 | **`paring_code` is a SumUp reader pairing secret rendered as a plain table column and a plain text input.** No masking, no `password()`, no `revealable()`, no toggleable-hidden | `app/Filament/Resources/SumUpReaderResource.php:47` (column) and `:34` (form) | Any admin list view or screenshot leaks it. The column-name typo is baked into the migration, so "fixing" the spelling breaks POS code paths |
| 11 | **Staff PINs are stored in plaintext.** `staff.pin_code` is a plain `string(6)` with an index, and POS login does `Staff::…->where('pin_code', $data['code'])`. The admin table masks it to `Set` / `Not Set`, which reads as if it were hashed | `app/Filament/Resources/StaffResource.php:77`; `MachineUserAuthController.php:50` | The masking is cosmetic. A rewrite that assumes hashing will break POS login |
| 12 | **`remote_id` on the SumUp form is `readOnly()`, not `disabled()`** - a client-side-only guard. The field round-trips through the request and `$guarded = []` on the model, so a tampered POST rewrites the SumUp-side reader binding | `app/Filament/Resources/SumUpReaderResource.php:33` | Same trap exists wherever `readOnly()` is used to mean "not editable" |
| 13 | **The `Receipt` / `Download Receipt` links point into the POS route group.** `routes/pos.php` is mounted behind `pos-auth:machine` + `pos-auth:machine-user`. An admin browsing `/admin` without an active POS machine session is bounced, not shown the receipt | `app/Filament/Resources/CheckoutResource.php` `receipt` action; `ViewCheckout.php` `receipt` action | The link is broken from the admin context it lives in |
| 14 | **The print action calls `dispatchSync`** - mPDF rendering happens inside the web request. Any mPDF or Fiskaly failure surfaces as a 500 rather than a notification. `$checkout->fiskaly_data['qr_code_data'] ?? null` is passed straight to `QRCode::png()`, so a checkout with no Fiskaly data yields a QR of `null`. The action also writes `'file' => 'checkouts/'.$record->id.'.pdf'` as a hardcoded path with no verification that the job wrote there, and it can be fired repeatedly to spam duplicate receipts for the same fiscal record, with no activity-log entry | `app/Filament/Resources/CheckoutResource.php:225-258` and the duplicate in `ViewCheckout.php` | - |

### 9.3 Injection, raw SQL and portability

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 15 | **Unescaped event name in a `Content-Disposition` header.** The badge-list download filename interpolates `$selectedEvent->name` straight into `response()->streamDownload()`. Event names are free-text admin input with no charset validation, so a quote, slash or newline breaks or injects into the header. The box-label variant at line 359 uses `Str::slug()`; this one does not | `app/Filament/Pages/PdfGenerator.php:308` | Header injection from an admin-controlled field. Slug both filenames |
| 16 | **`CAST(x AS UNSIGNED)` is MySQL/MariaDB-only** and appears three times: the `sort_attendee_id` sort and both halves of the `attendee_id_range` filter. `.env.example` defaults to SQLite | `app/Filament/Resources/BadgeResource.php:275`, `:419`, `:425` | Badge sorting and attendee-range filtering break on SQLite, so tests that touch them fail on the default dev DB |
| 17 | **`orderByRaw` string-interpolates `$direction`.** The direction comes from the table sort state, which Filament constrains to asc/desc, but it is raw-interpolated all the same | `app/Filament/Resources/BadgeResource.php:275` | Do not carry the pattern forward |
| 18 | **`selectRaw('status_payment, status_fulfillment, COUNT(*) as count')`** - raw SQL with `count` as a column alias, unquoted. Works on MySQL and SQLite today but is engine-sensitive | `app/Filament/Widgets/BadgeStatusChart.php:40` | - |
| 19 | **`PrintJob::scopePrioritized()` uses MySQL-only raw SQL**: `orderByRaw('CAST(SUBSTRING_INDEX(badges.custom_id, "-", 1) AS UNSIGNED) ASC')`. Not called from Filament, but it is the legacy ordering path that `PrintBatch::sortBadgesForPrinting()` replaced | `app/Domain/Printing/Models/PrintJob.php` (`scopePrioritized`) | If the rewrite reuses the old scope it inherits the portability break and the ordering disagreement the `sortBadgesForPrinting()` docblock records |

These are the complete raw-SQL and portability findings under `app/Filament`. There is no `DB::raw`,
no `DATE_FORMAT`, no `havingRaw`, and no hardcoded date literal anywhere in the directory.

### 9.4 State machines bypassed

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 20 | **Badge status selects bypass both state machines.** `status_fulfillment` and `status_payment` are plain Selects writing raw state values through `EditRecord`'s default save. All Spatie transition side effects are skipped: `custom_id` allocation, `printed_at`, `ready_for_pickup_at`, `picked_up_at`, notifications, activity-log semantics. Admin edits can put a badge into a state the machine would have rejected | `app/Filament/Resources/BadgeResource.php:94` (and the payment select beside it) | - |
| 21 | **The fursuit `status` form field is a plain TextInput.** Writing it goes straight through the `FursuitStatusState` cast: no `PendingToApproved` / `PendingToRejected` transition, no `approved_at` / `rejected_at` bookkeeping, no activity-log entry, no user notification. `approved_at` / `rejected_at` are separately hand-editable and can contradict `status` | `app/Filament/Resources/FursuitResource.php:117` | - |
| 22 | **The print-job Edit form writes `status` directly**, bypassing `PrintJob::transitionTo()`: no `canTransitionTo()` check, no `printed_at` / `failed_at` / `queued_at` stamping, no `completion_source`, no `releasePrinter()`, no `promoteBadgeToReadyForPickup()`, no `batch->recalculateCounters()`, no `batch->completeIfFinished()`. Setting a job to Printed from admin leaves the badge stuck in `Processing` and the batch counters wrong. Same for `retry_count` and `priority` | `app/Filament/Resources/PrintJobResource.php` form schema | - |
| 23 | **`DeleteBulkAction` deletes print jobs out from under a batch** without calling `recalculateCounters()`, permanently desyncing `total_jobs` / `printed_count` / `verified_count` / `failed_count` | `app/Filament/Resources/PrintJobResource.php` bulk actions | Every progress badge in the printing slice reads those counters |
| 24 | **`PrintBatch::cancel()` unlocks the badge** (`printing_locked_at = null`) but does **not** revert the badge's fulfilment state out of `Processing`. Badges cancelled out of a batch stay in `Processing` forever | `app/Filament/Resources/PrintBatchResource.php` `cancel` action → `PrintBatch::cancel()` | - |

### 9.5 Broken or unreachable surfaces

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 25 | **The badge Create page is broken.** `ListBadges` exposes a `CreateAction`, but `fursuit_id` is `->disabled()` and Filament does not dehydrate disabled fields, so a create submits no `fursuit_id` while `badges.fursuit_id` is `NOT NULL` with an FK. Creating a badge from admin throws a DB integrity error. `custom_id` is likewise disabled and never saved | `app/Filament/Resources/BadgeResource.php:53`, `:61` | Do not port "create badge" as a working feature - it never was one |
| 26 | **The `UserResource` form writes a column that no longer exists.** `valid_registration` (form Toggle and table IconColumn) was dropped from `users` by `2025_08_03_195303_remove_old_columns_from_users_table` and moved to `event_users`. Listing renders it as an empty icon; **saving the form throws SQL "Column not found: 1054"** | `app/Filament/Resources/UserResource.php:30` (form), `:54` (column) | The Create and Edit actions of this resource are broken today |
| 27 | **The printer create form is very likely broken.** `default_paper_size`'s options closure type-hints `Printer $record`. On the create page `$record` is `null`, so Filament injects null into a non-nullable parameter → `TypeError`. The missing Create header button hides it | `app/Filament/Resources/PrinterResource.php:43`; `ListPrinters::getHeaderActions(): []` | - |
| 28 | **A null printer status 500s the printers table.** The `status` column state uses `$record->status->value ?? 'unknown'` with **no null-safe operator**, so a row with `status = NULL` emits "Attempt to read property on null" → `ErrorException` → the whole table fails | `app/Filament/Resources/PrinterResource.php:69` | - |
| 29 | **`priority` / `retry_count` colour closures type-hint `int $state`.** A NULL column value → `TypeError` → the print-jobs table 500s. `priority` is nullable-capable (`$guarded = []`, no model-enforced default) | `app/Filament/Resources/PrintJobResource.php:117`, `:126` | - |
| 30 | **`SpecialCodeResource`'s `class_name` column closure type-hints `string $state`** while `class_name` is **not required** on the form, so a null value → `TypeError` and the whole table 500s | `app/Filament/Resources/SpecialCodeResource.php:88` | - |
| 31 | **`SpecialCodeResource`'s `event_id` column closure declares `: string`** but returns `null` when the event row is gone (events are hard-deleted) → `TypeError`. It also fires one query per row instead of eager-loading the `event()` relation the model already defines | `app/Filament/Resources/SpecialCodeResource.php:98` | - |
| 32 | **`constructor_data` is permanently disabled.** The `disabled()` matcher compares `$get('class_name')` against the literal `'EXAMPLE'`, which is not one of the options. The only configurable knob for the action class can never be edited through the UI, and the matching placeholder is likewise dead | `app/Filament/Resources/SpecialCodeResource.php:48` and `:52` | - |
| 33 | **`class_name` is not `->live()`**, so `constructor_data`'s `disabled()` and `placeholder()` closures, and `catch_url`'s `formatStateUsing`, never re-evaluate while the modal is open. `catch_url` is `dehydrated(false)` and computed once at render, so a create modal shows `https://catch.example/?code=&auto` | `app/Filament/Resources/SpecialCodeResource.php:36`, `:43`, `catch_url` field | - |
| 34 | **`SecurePinRule` is constructed with no `$excludeStaffId`.** `Staff::validatePinStrength()` then runs `self::where('pin_code', $pin)->exists()` against **all** staff including the record being edited. Opening an existing staff member and pressing Save without changing anything fails validation with `This PIN is not secure enough. Please choose a different PIN.` | `app/Filament/Resources/StaffResource.php:38` | A reproducible bug that makes staff editing impossible once a PIN is set |
| 35 | **Blank setup codes collide on a unique index.** `->mutateDehydratedStateUsing(fn ($state) => strtoupper($state ?? ''))` turns `null` into `''`, and `staff.setup_code` carries a UNIQUE index (`2025_08_24_033156_add_setup_code_to_staff_table`). The first staff member saved blank stores `''`; **the second one blows up with SQL 1062.** `Staff::hasSetupCode()` uses `! empty()` so `''` behaves like "no code" logically, hiding the cause | `app/Filament/Resources/StaffResource.php:45` | - |
| 36 | **The `Generate` suffix action mutates the record before the form is submitted.** On edit it calls `$record->generateSetupCode()`, which does `$this->update([...])` immediately. Generate then navigate away, and the DB has a new setup code the operator never saw committed, with the previous one gone | `app/Filament/Resources/StaffResource.php:52` | - |
| 37 | **Reject's `afterStateUpdated` does `$errorOptions[$state]` with `?string $state`.** Clearing the select yields `$errorOptions[null]` → "Undefined array key" warning and a null textarea. The options are a **list**, so the persisted select value is an integer index `0`–`7`; the human-readable reason survives only because it is copied into `custom_reason`. Renumbering or reordering the array silently rewires the prefill | `app/Filament/Resources/FursuitResource/Pages/ViewFursuit.php:88-89` and `:27-35` | - |
| 38 | **`FursuitPolicy::create()` returns `false`, yet the create page, route and `CreateAction` all still exist.** Dead surface | `app/Policies/FursuitPolicy.php`; `FursuitResource/Pages/CreateFursuit.php`; `ListFursuits.php` | Do not "fix" the policy during a rewrite |
| 39 | **`ListPrinters::getHeaderActions(): []`** removes the Create button while the create page and route remain. Likewise `/admin/tse-clients/create` is registered with no entry point in the UI | `PrinterResource/Pages/ListPrinters.php`; `TseClientResource/Pages/ListTseClients.php` | Orphaned routes |
| 40 | **The `pdfs/badge-list.blade.php` view (165 lines) is dead** - nothing references `view('pdfs.badge-list')`. So is `app/Filament/Components/EventSelector.php` | `resources/views/pdfs/badge-list.blade.php`; `app/Filament/Components/EventSelector.php` | - |
| 41 | **`printBadgeWithPrinter()` is entirely dead code** - zero callers - and it diverges from the batch pipeline: no `PrintBatch`, no printing lock, PDFs written to the default disk instead of s3. `$delaySeconds` is logged and otherwise unused; `$mass = 0` on `printBadge()` is never used; `$printed` in the `print_jobs_count` state closure is computed and never used | `app/Filament/Resources/BadgeResource.php:526`, `:493`, `print_jobs_count` closure | Porting it as-is creates a second, incompatible print path |
| 42 | **`RejectedToPending` exists as a transition class but is never wired into `config()`** (the Rejected→Pending edge is registered without a transition class) and no UI exposes it | `app/Models/Fursuit/States/Transitions/RejectedToPending.php`; `FursuitStatusState::config()` | - |
| 43 | **`MachineResource::getEloquentQuery()` is an explicit no-op override** that reads as if it does something. `Machine::withArchived()` is likewise a no-op scope (`return $query`), so the ternary filter's `false` branch labelled `All machines` means "no constraint" - correct by accident. `onlyArchived()` and `archived()` are duplicate scopes | `app/Filament/Resources/MachineResource.php:142-144`, `:73` | Removing the ternary filter in a rewrite silently exposes archived machines, because nothing scopes them at query level |
| 44 | **The panel registers `Filament\Widgets\StatsOverviewWidget`**, the framework base class with an empty `getStats()`, not `App\Filament\Widgets\StatsOverview`. It renders an empty stats strip and is easy to mistake for the real widget | `app/Providers/Filament/AdminPanelProvider.php:42` | - |
| 45 | **Box labels claims "3 labels per page" everywhere** (option label `Box Labels (3 per A4 page)`, the page copy, the code comment `Generate 3 labels for one page`) but `generateBoxLabelsPdf` renders exactly **one** label on a `210×94mm` custom page. The page geometry is 94mm tall while `pdfs/box-labels.blade.php` hardcodes `height: 84mm; width: 200mm` - two sources of truth for one physical label | `app/Filament/Pages/PdfGenerator.php` box-label branch; `resources/views/pdfs/box-labels.blade.php` | - |
| 46 | **`pdf-generator.blade.php` describes "all free badges" and "3 columns per page"**; the code lists all badges matching the payment filter and defaults to **12** columns | `resources/views/filament/pages/pdf-generator.blade.php` | Stale copy |
| 47 | **The default `badge_ranges` value `0-999,…,4000-4999` is a hardcoded numbering assumption.** Badges numbered ≥ 5000 are silently omitted with no warning; the `No Badges in Ranges` notification only fires when *every* range is empty | `app/Filament/Pages/PdfGenerator.php:42` | - |
| 48 | **The badge-preview default badge class disagrees between layers.** The blade shows `?? 'EF28_Badge'` while `App\Http\Controllers\Admin\BadgePdfController` defaults to `'EF30_Badge'` in `view()`, `download()` and a `default => new EF30_Badge` match arm. The preview screen can label a badge `EF28_Badge` and then hand you an EF30 PDF | `resources/views/filament/pages/badge-preview.blade.php`; `app/Http/Controllers/Admin/BadgePdfController.php` | - |
| 49 | **`target="_blank"` on `<x-filament::button wire:click=…>` does nothing** - the action is a Livewire redirect, so "View PDF in Browser" navigates the current tab away from the admin page | `resources/views/filament/pages/badge-preview.blade.php` | - |
| 50 | **`asset('images/placeholder.png')` does not exist** in `public/images/`. Every DB-Service review row without a resolvable S3 URL renders a broken image. `BadgeResource`'s `defaultImageUrl(url('/images/placeholder.png'))` points at the same missing file | `resources/views/filament/pages/db-service.blade.php`; `app/Filament/Resources/BadgeResource.php` column 1 | - |

### 9.6 Authorization

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 51 | **`CheckoutResource`, `PrintBatchResource` and `SpecialCodeResource` have no policy at all.** Relative to the panel gate (`is_admin \|\| is_reviewer`), that means an `is_reviewer`-only user has full authority over fiscal checkout records, live print-run controls (pause / resume / cancel), and Catch-Em-All special codes. Contrast `PrinterPolicy`, which requires `is_admin` merely to view a printer | `app/Policies/` has no `CheckoutPolicy` / `PrintBatchPolicy` / `SpecialCodePolicy`; the auto-discovery targets under `app/Domain/**/Policies/` do not exist | Almost certainly unintended, and easy to preserve accidentally by copying "there was no check" |
| 52 | **`BadgePolicy::update` contains `request()->routeIs('filament.*', 'livewire.*')`** - the policy is coupled to Filament's route names. Moving the admin to other routes silently flips admins from "can edit any badge" to "owner rules only" | `app/Policies/BadgePolicy.php` | The single most rewrite-hostile line in the authorization layer |
| 53 | **`EventPolicy` defines no `restore` / `forceDelete`**; Filament treats a missing policy method as **allowed**, so those abilities are open | `app/Policies/EventPolicy.php` | Moot only because `Event` has no soft deletes surfaced |
| 54 | **`RfidTag` has no policy** and `RfidTagsRelationManager` has no `canViewForRecord` / `can*` overrides. Access is inherited from the admin-only Staff edit page, which is the only thing protecting it | `app/Filament/Resources/StaffResource/RelationManagers/RfidTagsRelationManager.php` | - |
| 55 | **`PrintJobsRelationManager` sets `isReadOnly(): false` with a docblock that says the opposite**: "Nothing here creates or deletes a job. A batch is immutable, so its contents can only be changed by cancelling the whole run." The `false` is deliberate - it is what makes `verify` clickable - but a reader following the comment would flip it and silently kill the verify action | `app/Filament/Resources/PrintBatchResource/RelationManagers/PrintJobsRelationManager.php` | - |
| 56 | **The fursuit activity log is writable.** Create, Edit, Delete and bulk-delete are all enabled on `ActivitiesRelationManager`. Any panel user who can reach the fursuit can fabricate or erase history, and `causer` is not set on manual creates, so a forged entry shows an empty `By`. `properties` is `json_encode`d on read with no `dehydrateStateUsing`, so a form round-trip double-encodes it into a collection-cast column | `app/Filament/Resources/FursuitResource/RelationManagers/ActivitiesRelationManager.php` | An audit trail that the audited party can edit is not an audit trail |
| 57 | **The `web` guard is shared** between the public badge site and `/admin`. There is no separate admin session, no re-auth for destructive actions, and `AuthenticateSession` is the only session-integrity middleware | `app/Providers/Filament/AdminPanelProvider.php` middleware stack | - |
| 58 | **`is_reviewer` is not cast to bool** on `User`, so `$user->is_reviewer === true` style checks (as `viewHorizon` does with `is_admin`) would fail on some drivers. No code does that today | `app/Models/User.php` casts | A trap |
| 59 | **`2026_06_11_000000_set_user_as_admin_by_remote_id.php` grants `is_admin = true` to a hardcoded `remote_id`** at migration time, inserting the user if missing | `database/migrations/2026_06_11_000000_set_user_as_admin_by_remote_id.php` | An admin account that exists only because of a migration, invisible in any code path |
| 60 | **`admin.badge-pdf.view` / `admin.badge-pdf.download` are guarded by `auth` only**, not by `canAccessPanel`, so any logged-in attendee can fetch any badge PDF by custom id | `routes/web.php:42-49` | The most exposed data path in the coupling checklist |
| 61 | **`BadgePreview` has no authorization beyond panel access** - reviewers can pull any attendee's badge PDF; and `PdfGenerator` likewise has no gate. `DbService` is the only page that re-gates to `is_admin` | `app/Filament/Pages/BadgePreview.php`, `PdfGenerator.php`, `DbService.php:34`, `:39` | - |

### 9.7 The event selector and event scoping

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 62 | **The "all events" option is dead.** The middleware forgets `filament_selected_event_id` on `?selected_event_id=all` and immediately re-seeds it with the newest event on the same request; the blade never renders an `all` option anyway. Consequently `HasEventFilter::applyEventFilter()`'s "no id, unfiltered" branch and every null-returning `getNavigationBadge()` / `getNavigationBadgeColor()` are unreachable dead code | `app/Http/Middleware/FilamentEventSelector.php:14-28`; `app/Filament/Traits/HasEventFilter.php`; `BadgeResource::getNavigationBadge()`, `FursuitResource::getNavigationBadge()` / `getNavigationBadgeColor()` | Document the intent, do not reproduce the mechanism |
| 63 | **Session-key mismatch.** `PdfGenerator::getSelectedEvent()` reads `session('filament.admin.selected_event_id')` while everything else reads and writes `filament_selected_event_id`. The PDF Generator always falls back to `Event::latest('starts_at')->first()`, so changing the header selection has **no effect** on generated PDFs, and its `No event selected in the header.` notification is unreachable unless there are zero events | `app/Filament/Pages/PdfGenerator.php:365` | - |
| 64 | **`getSelectedEventId(): ?int` returns whatever the query string put in the session** - a string, coerced by the return type. A non-numeric `?selected_event_id=foo` stores `'foo'` and would `TypeError` on return. There is no validation that the id is a real event | `app/Filament/Traits/HasEventFilter.php` | - |
| 65 | **Event scoping lives in the session, not the URL.** Admin links are not shareable or deep-linkable, and two browser tabs share one scope | `app/Http/Middleware/FilamentEventSelector.php` | - |
| 66 | **Scoping is inconsistent.** It applies to `BadgeResource` and `FursuitResource` tables and the three widgets, but **not** to `ViewFursuit`'s moderation queue (`Fursuit::where('status','pending')->first()`, which is also unordered and picks up fursuits from past events), nor to `EventResource`, `SpecialCodeResource`, `CheckoutResource`, the POS resources or `UserResource` | `ViewFursuit.php` (4 queue queries); `SpecialCodeResource` (no `modifyQueryUsing`) | The rewrite must decide deliberately where the scope applies |
| 67 | **The badge event filter is a `whereHas('fursuit', …)` on a query that also `leftJoin`s `fursuits`** - the same table both joined and subqueried. It works, but any rewrite that "simplifies" it into a single join changes the semantics for badges with a missing fursuit | `app/Filament/Resources/BadgeResource.php` `modifyQueryUsing` | - |
| 68 | **The event-selector blade's "Orders Open/Closed" suffix only renders on the already-selected option**, which is invisible in a closed `<select>` on most browsers. The `<script>` defining `updateQueryStringParameter` is emitted once per render-hook invocation into the global scope | `resources/views/filament/components/event-selector.blade.php` | - |

### 9.8 The fursuit moderation lock

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 69 | **`$defaultAction = 'Claim'` silently claims the record on page load.** There is no visible indication of who holds a claim - no infolist entry for it - and nothing tells you a claim exists other than the Approve/Reject buttons appearing | `ViewFursuit.php:23` | - |
| 70 | **Claims live only in the cache with a 5-minute TTL and are never released** by Approve or Reject. Flushing the cache drops every claim; a cache-driver swap breaks the whole moderation lock; two reviewers can moderate the same record once the TTL lapses | `Fursuit::claim()` / `unclaim()`; cache key `fursuit:{id}:claim` | - |
| 71 | **`Fursuit::claim(User $user)` ignores its `$user` parameter** and stores `auth()->user()->id`. `Fursuit::unclaim()` takes no parameter but is called with one and performs no ownership check. `isClaimedBySelf()` does `(int) cache()->get($key) == $user->id`, a loose comparison against a nullable cache value that yields `0` when unclaimed | `app/Models/Fursuit/Fursuit.php`; `ViewFursuit.php` | - |
| 72 | **Approve and Reject bail out with only a `Log::error(...)` when the claim is missing** - the operator sees a spinner finish and nothing happen. Log messages verbatim: `Fursuit is not claimed, but user tried to approve it.` / `Fursuit is not claimed, but user tried to reject it.`, both passing `['fursuit' => $record]`, which serialises the whole model into logs | `ViewFursuit.php` Approve and Reject bodies | - |
| 73 | **`Send Notification` is always visible, unconfirmed and not state-gated.** An approval email can be sent for a rejected fursuit and vice versa, with an arbitrary free-text reason, leaving no state change and no activity-log entry. Its `notification_type` Select is not `->live()`, so the conditional `rejection_reason` field only reacts on the next round-trip | `ViewFursuit.php:128` | - |
| 74 | **Approval and rejection emails are silently suppressed when `event->ends_at` is in the past.** A date-dependent behaviour change invisible in the UI. `$this->fursuit->event->ends_at` on a null event raises a warning and yields null, so the guard "works" by accident | `PendingToApproved`, `PendingToRejected` transitions | - |
| 75 | **`Approve Rejected` bypasses the claim mechanism entirely**, so two reviewers can both trigger the apology email. Approve and Reject give no success notification at all while `Approve Rejected` and `Send Notification` do - inconsistent feedback | `ViewFursuit.php:111`, `:128` | - |
| 76 | **`toNextFursuit()` may redirect to a still-claimed fursuit** after 3 tries, and its queries are unordered and not event-scoped | `ViewFursuit.php:170` | - |
| 77 | **`if ($record === null) { return; }` in Approve is unreachable** (the parameter is typed `Fursuit`) | `ViewFursuit.php` Approve body | Dead code |
| 78 | **Deleting a fursuit triggers `FursuitObserver`'s cascade to its badges** (guarded by `Fursuit::$isCascadingDelete`), a side effect completely invisible from a confirm dialog whose copy is the generic Filament default `Delete :label` / `Are you sure you would like to do this?` | `EditFursuit.php`; `app/Observers/FursuitObserver.php` | - |

### 9.9 Printing operations

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 79 | **The nav badge counts batches; the column and the ops language say cards.** `getNavigationBadge()`'s own docblock says "Cards that printed but nobody has confirmed came out right", but the query counts `PrintBatch` rows containing at least one such job. Two different units under one word | `app/Filament/Resources/PrintBatchResource.php:40-53` | The docblock is the author's stated intent and it contradicts the code below it. Pick one unit deliberately |
| 80 | **The `unverified` column and the nav badge can disagree.** The column computes `printed_count - verified_count` from the **denormalised counters**; the nav badge queries the jobs directly via `whereHas`. They diverge whenever `recalculateCounters()` has not run - e.g. after a job is deleted from `PrintJobResource`. `verified_count` counts *any* job with `verified_print_at` set, including Failed and Cancelled ones, so the difference can go **negative** and render as a grey negative badge | `PrintBatchResource.php` column 7 and `getNavigationBadge()`; `PrintBatch::recalculateCounters()` | - |
| 81 | **The nav-badge query runs on every admin page render** in the panel, as a `whereHas` + `count`, unindexed on `verified_print_at` | `PrintBatchResource.php:44-51` | - |
| 82 | **`pause` has no `requiresConfirmation()`.** One click plus a reason halts the whole run | `PrintBatchResource.php` `pause` action | - |
| 83 | **There is no way to start or advance a batch from admin.** No Draft → Ready and no Ready → Printing action exists; only pause / resume / cancel. Advancing happens through the print agent (`PrintBatch::scopeSelectable` / `claimNextJob`) | `PrintBatchResource.php` row actions | A rewrite that assumes admin can drive the run will be wrong |
| 84 | **Pause / resume / cancel are not reachable from the batch detail page**, only from the list row, because `ViewPrintBatch::getHeaderActions()` returns `[]` deliberately | `PrintBatchResource/Pages/ViewPrintBatch.php` | - |
| 85 | **The `retry` action leaves the original job `Failed` and the batch `Paused`.** Nothing here resumes the batch, so a retried card does not start printing until someone also hits Resume. Not obvious from the UI, and the only retry lives on `PrintJobResource`, which shows no batch or sequence - so retrying a failed card in a batch means leaving the batch page, finding the job by id, retrying, then coming back and resuming | `PrintJobResource.php` `retry`; `PrintJobsRelationManager` (no retry action) | - |
| 86 | **Verification is manual, one card at a time, with a confirm modal each.** During a live convention that is one modal per card; `->bulkActions([])` is explicitly empty. `markVerified()` does not check the job's status, so only the visibility predicate stops a Failed job being verified | `PrintJobsRelationManager.php` `verify` and bulk actions | - |
| 87 | **`Cancelled` is missing from the print-job status form Select, the status filter and the BadgeColumn colour map.** Cancelled jobs are uncoloured, unfilterable and unreachable from the filter. The relation manager's own filter includes all 7 cases | `PrintJobResource.php:95` (colours), status filter, status form field | - |
| 88 | **`getEloquentQuery()` reading `request()->has('printer')` is a hidden global constraint.** It fires on the edit and view pages too; a Livewire request that loses the query string silently changes the visible set, and there is no filter chip to clear it from the UI | `PrintJobResource.php:248-254` | - |
| 89 | **Creating a print job by hand produces an orphan.** `Create` allows a badge print job with **no `print_batch_id`**, which lands in the receipt-only unbatched lane; `PrintJob::claimNextUnbatched()` filters `type = Receipt`, so such a job sits Pending forever | `PrintJobResource.php` create page; `PrintJob::claimNextUnbatched()` | - |
| 90 | **`printable` hardcodes the literal `'App\\Models\\Badge\\Badge'`**; if the morph map changes this falls into the `class_basename` branch. `printable?->custom_id` is null-safe but a soft-deleted badge renders `Badge #` with nothing after it. In the relation manager the same chain renders `Deleted` for both columns with no way to see which badge id it was | `PrintJobResource.php:108`; `PrintJobsRelationManager` columns 2-3 | - |
| 91 | **`completion_source`'s `formatStateUsing(fn ($state) => $state?->label())` is untyped**, so it would fatal on a raw-string legacy value if the cast ever failed | `PrintJobsRelationManager` column 5 | - |
| 92 | **The `is_active` CheckboxColumn mutates live hardware config on a single click** with no confirm, no notification and no audit trail, and unlike `Printer::updatePrinterState()` it does **not** broadcast `App\Events\PrinterStatusUpdated`, so POS clients never learn about it | `PrinterResource.php:96` | - |
| 93 | **`PrintBatch::build()` throws `StalePrintFileException`** if any badge's `print_file_hash` does not match `GenerateBadgePrintFileJob::inputHash($badge)`. Nothing in the printing UI surfaces or handles that; the only entry point is `printBadgeBulk` on `BadgeResource`, in a different slice | `App\Domain\Printing\Models\PrintBatch::build()`; `BadgeResource.php:453` | - |
| 94 | **Nothing surfaces `PrintBatch::isSealed()`, `completeIfFinished()` or `unverifiedJobs()`**, and no admin action exists to clear a printer error despite `Printer::clearPrinterError()` existing | `PrintBatchResource.php`; `PrinterResource.php` | - |

### 9.10 Performance

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 95 | **N+1 on every badge-table render, every 5 seconds.** `print_jobs_count` runs `$record->printJobs()->get()` twice per row (once in `getStateUsing`, once in `color`), with `->poll('5s')` and no eager loading. On a 100-row page that is 200 queries every 5 s. The column key is `print_jobs_count` but there is no `withCount` - the value comes entirely from `getStateUsing` | `app/Filament/Resources/BadgeResource.php` column 7, `:490` | - |
| 96 | **Three count columns each fire a separate `COUNT(*)` per printer row** with pagination off - 4+ queries per printer on every render. The counts compare `status` against hardcoded lowercase strings, not `PrintJobStatusEnum` cases, so any enum rename silently zeroes them | `app/Filament/Resources/PrinterResource.php` columns 5-7, `:115` | - |
| 97 | **`->poll('5s')` on a print-jobs table with several relation columns and no eager loading** (`printer`, `processingMachine`, `printable` all lazy-load) | `app/Filament/Resources/PrintJobResource.php:227` | - |
| 98 | **`ItemsRelationManager` lazy-loads a `morphTo` per row** with `->paginated(false)`, and each row additionally lazy-loads `payable->fursuit` | `app/Filament/Resources/CheckoutResource/RelationManagers/ItemsRelationManager.php:63` | - |
| 99 | **`SpecialCodeResource` fires one `Event::where('id', …)` per row** for the event name, and its `event_id` Select uses `Event::all()->pluck(...)` on every form render instead of `->relationship('event','name')` | `app/Filament/Resources/SpecialCodeResource.php:98`, `:28` | - |
| 100 | **Bulk-action printer options are frozen at boot.** `Printer::where(...)->pluck('name','id')` is evaluated when `table()` builds the schema, not per modal open, so newly added or activated printers may not appear until the class is re-resolved | `app/Filament/Resources/BadgeResource.php:461` | - |
| 101 | **All three dashboard widgets poll at 5 s** and duplicate the same current/previous event resolution block verbatim - three copies to keep in sync, four count queries and one `GROUP BY` per tick | `StatsOverview.php`, `BadgeStatusChart.php`, `EventComparisonChart.php` | - |
| 102 | **`->searchable()` on a bigint `id` triggers a `LIKE`** on the checkouts table | `app/Filament/Resources/CheckoutResource.php` column 1 | - |
| 103 | **`->paginated(false)` plus `->counts('rfidTags')`** means one subquery per staff row on every render. Fine at convention scale, but it is a deliberate choice that will disappear silently | `app/Filament/Resources/StaffResource.php:110` | - |
| 104 | **`mpdf/mpdf` is instantiated directly in the page class** - no service, no queue; large events generate synchronously in the request | `app/Filament/Pages/PdfGenerator.php` | - |

### 9.11 Presentation and copy

| # | What | Where | Why it matters for the rewrite |
|---|---|---|---|
| 105 | **Computed event state is invisible.** The single most important property of an event - open vs closed order window - has no column, no filter and no badge. Operators read three timestamps and do the comparison mentally, and changing `order_starts_at` / `order_ends_at` here changes the whole app's behaviour with no confirmation | `app/Filament/Resources/EventResource.php`; `Event::state()` | - |
| 106 | **`mass_printed_at` is `->required()` while its own helper text says "if applicable".** You cannot create or save an event without inventing a mass-print timestamp | `app/Filament/Resources/EventResource.php:55` | Almost certainly unintended, and load-bearing for the printing flow |
| 107 | **`archival_notice` appears in the table and the form but is not in `Event::$fillable`**, so saving it through mass assignment is silently dropped | `app/Filament/Resources/EventResource.php:86`, `:133`; `app/Models/Event.php` `$fillable` | - |
| 108 | **`Group::make([...])->label(...)` renders nothing.** The four apparent sections `Event Dates`, `Order Management`, `Financial Tracking`, `Gallery Settings` exist only in the source | `app/Filament/Resources/EventResource.php:40`, `:46`, `:61`, `:71` | A rewrite that ships them as real headings changes the UI; one that drops them loses the author's intent |
| 109 | **`starts_at` / `ends_at` are `DatePicker` (date-cast, midnight) while `Event::state()` compares `$this->ends_at < now()`**, so an event flips to `CLOSED` at 00:00 on its end date, not at the end of that day | `app/Filament/Resources/EventResource.php` form; `Event::state()` | - |
| 110 | **`badge_class` options are hardcoded per-convention strings** (`EF28` / `EF29` / `EF30`); every new event year needs a code change | `app/Filament/Resources/EventResource.php:31` | - |
| 111 | **`->numeric()` applied to string columns.** `EventResource` `name`, `FursuitResource` `user.name` and `species.name` - Filament number formatting on text | `EventResource.php` column 1; `FursuitResource.php:142`, `:145` | - |
| 112 | **`checkFileExistence(false)` on the image columns** means broken S3 keys render as broken images rather than being skipped | `BadgeResource.php` column 1; `FursuitResource.php:154` | - |
| 113 | **Null dereferences in badge column URLs.** Column 2 does `$record->fursuit->id` and column 4 does `$record->fursuit->user->name` with no null guard. `Fursuit` uses SoftDeletes, so a badge whose fursuit or user is soft-deleted throws while rendering the row and takes down the whole table. `ItemsRelationManager` has the same problem with `$badge->fursuit->name`, and `badge-preview.blade.php` dereferences `->species->name`, `->user->name`, `->event->name` with no guards | `BadgeResource.php:248`, `:261`; `ItemsRelationManager.php:63`; `badge-preview.blade.php` | - |
| 114 | **`BadgeStatusChart` defines only 5 colours** but payment × fulfillment can produce up to 10 combinations; `array_slice($colors, 0, count($statusData))` returns fewer colours than data points once there are more than 5 groups, so Chart.js falls back to undefined segments. Segment ordering is whatever `GROUP BY` returns, so colour-to-status mapping is **not stable across renders**. Labels are built from raw DB state names, so they read like `Paid / Readyforpickup` | `app/Filament/Widgets/BadgeStatusChart.php:46`, `:64` | Already broken for a real event |
| 115 | **`StatsOverview` shows `No previous event` whenever the diff is exactly 0**, even when a previous event exists and simply had the same count. It also uses the raw string `'pending'` rather than `whereState('status', Pending::class)` | `app/Filament/Widgets/StatsOverview.php:32` and the diff descriptions | - |
| 116 | **`EventComparisonChart` compares exactly two events** despite its name, and puts badge and fursuit counts on one Y axis so the smaller series is visually crushed | `app/Filament/Widgets/EventComparisonChart.php` | - |
| 117 | **`class_basename($state)` on the checkout status badge** derives the label from the PHP class name, not from `$name`; renaming a state class silently changes the UI label. The `Badge` column in `ItemsRelationManager` only handles `payable_type === Badge::class` and silently renders `-` for anything else, hiding what was actually sold on a fiscal document | `CheckoutResource.php` column 5; `ItemsRelationManager.php:63` | - |
| 118 | **`mb_convert_encoding($v, 'UTF-8', 'UTF-8')` is a no-op**, not sanitization. It appears in `PdfGenerator`'s box-label title/subtitle handling and in `BadgePreview::loadBadge()`'s fursuit name | `PdfGenerator.php` box-label branch; `BadgePreview.php` `loadBadge` | - |
| 119 | **`code` has `minLength(5)` / `maxLength(5)` but no case normalisation or charset restriction**, while the uniqueness check against `fursuits.catch_code` is a plain `where`. Collation decides whether `abc45` and `ABC45` collide, and the `unique()` rule and the Fursuit-collision rule are two separate checks that can disagree under different collations | `app/Filament/Resources/SpecialCodeResource.php` `code` field | - |
| 120 | **RFID validation is asymmetric with the POS self-service path.** POS enforces `min:8`, `max:20`, `regex:/^[0-9]+$/` with custom messages (`MachineUserAuthController.php:163-171`); the admin relation manager accepts any string 1-255 chars. An admin can create a tag the POS reader can never produce and the POS validator would reject | `RfidTagsRelationManager.php` `content` field; `MachineUserAuthController.php:163-171` | - |
| 121 | **`pin_code` uses `->numeric()`.** A PIN with a leading zero (`012345`) survives as a string in MySQL but client-side numeric coercion drops the zero. `Staff::validatePinStrength`'s weak-PIN message is deliberately misleading for a **duplicate** PIN (`This PIN is not secure enough. Please choose a different PIN.` - source comment: "don't reveal that another user has this PIN for security"); a rewrite reporting "PIN already in use" changes the security posture | `StaffResource.php:32`; `app/Models/Staff.php` `validatePinStrength` | - |
| 122 | **`last_login_at` uses `->since()` with no `->placeholder()`** on `StaffResource`, so a member who never logged in shows a blank cell, while `RfidTagsRelationManager` does set `Never used` - inconsistent. `created_at` labelled `Added` and rendered `->since()` means the exact add time is only in the tooltip | `StaffResource.php` column 5; `RfidTagsRelationManager.php` columns 4-5 | A naive rewrite showing a formatted date changes the display contract |
| 123 | **`DbService`'s `Event::getActiveEvent()` is `self::latest('starts_at')->first()`** - the newest event by start date, **not** the header-selected event and not "currently running". The blade says "for the current event"; that is whatever event starts latest, including a future one | `app/Filament/Pages/DbService.php:50`, `:67`; `Event::getActiveEvent()` | - |
| 124 | **The DB-Service apply step is guarded only by a native `wire:confirm`** (a browser `confirm()`), not a Filament modal with a typed confirmation. One click plus one OK moves real money. `saveQuietly()` skips model events, observers and automatic activity logging; the refund is a wallet **deposit**, not a reversal, with no idempotency marker beyond `is_free_badge`, so running the repair twice on re-qualifying data double-credits. `preview()` and `repair()` re-run `analyseUser()` independently, so the confirmed count is not guaranteed to match what gets written, and raw `$e->getMessage()` is surfaced to the browser | `resources/views/filament/pages/db-service.blade.php:112`; `app/Services/FreeBadgeRepairService.php` | - |
| 125 | **`imageUrl()` swallows all S3 errors** (double try/catch → `null`), so an S3 outage silently degrades to broken images with no signal | `app/Services/FreeBadgeRepairService::imageUrl()` | - |
| 126 | **Duplicate `navigationSort` values in three groups** (`FursuitResource`/`SpecialCodeResource` = 3; `PrinterResource`/`PrintBatchResource` = 2; `PrintJobResource`/`StaffResource` = 3), and **no `->navigationGroups([...])` on the panel**, so both group order and intra-group tie-breaks are Filament-internal and partly accidental | resource `$navigationSort` properties; `AdminPanelProvider.php` | Capture the rendered order explicitly, or a rewrite will not reproduce it |
| 127 | **The 2px table-cell CSS override is the panel's only styling**, injected via a render hook from a file Vite does not build. Trivially lost | `public/css/filament-custom.css`; `AdminPanelProvider.php:67` | - |
| 128 | **`flowframe/laravel-trend` is an unused dependency** - zero references to `Flowframe` or `Trend::` in `app/` or `resources/` | `composer.json` | - |
| 129 | **Unsurfaced columns across the panel.** Printers: `condition`, `condition_message`, `condition_reported_at`, `cards_remaining`, `cards_capacity`, `condition_raw`, `last_error_message`, `handling_machine_name`, `current_job_id`. Print jobs: `print_batch_id`, `sequence`, `lease_expires_at`, `attempt_count`, `completion_source`, `verified_print_at`, `verification_source`, `verified_by_id`, `retry_of`, `file`, `queued_at`, `started_at`, `failed_at`. Machines: `is_print_server`, `auto_logout_timeout`, `pending_print_jobs_count`, `agent_last_seen_at`, `agent_version`. Users: `is_cashier`, `pin_code`, `rfid_code`, token columns. Events: `cost` is editable but `total_revenue` / `profit_margin` accessors are not shown | throughout | A rewrite mirroring "what the admin can see" keeps dropping them |
| 130 | **QZ Tray coupling is gone from the printing slice.** `2026_08_06_100000_drop_qz_columns.php` dropped `print_jobs.qz_job_name`, `last_qz_status`, `last_qz_message` and `machines.qz_connection_status`, `qz_last_seen_at`, replaced by the native print agent plus SNMP (`firmware_job_id` / `firmware_job_uuid`; `agent_last_seen_at` / `agent_version`). The only in-`app/` QZ string left is a comment | `app/Http/Controllers/PrintAgent/AgentSessionController.php:45`; `database/migrations/2026_08_06_100000_drop_qz_columns.php` | The `pos-auth.php` QZ cert/signing routes described in `CLAUDE.md` are outside this slice; verify before assuming they are live |
| 131 | **Machines cannot be deleted from admin at all** - no row Delete, no Edit-page Delete, no bulk delete - despite `MachinePolicy::delete` / `forceDelete` existing. `Machine::$timestamps = false` and `$guarded = []`, so archive/unarchive leave no `updated_at` trail and no activity log | `MachineResource.php`; `MachineResource/Pages/EditMachine.php` | - |
| 132 | **Hard deletes on hardware referenced by fiscal records.** Deleting a SumUp reader (`machines.sumup_reader_id`, `nullOnDelete`) destroys the human-readable link between a past card checkout and the terminal that took it, with no warning in the modal. Deleting a staff member cascades away their RFID tags (`onDelete('cascade')`) and can orphan `checkouts.cashier_id`. There is no uniqueness validation on TSE `remote_id` / `serial_number` and no unique index, so duplicate TSE clients can be created and assigned to different machines | `SumUpReaderResource.php`; `StaffResource.php`; `TseClientResource.php` | - |
| 133 | **`EditTseClient::getHeaderActions()` returning `[]` is the only thing preventing deletion** of a TSE client. A rewrite that omits that empty override reinstates a destructive default | `TseClientResource/Pages/EditTseClient.php:12` | - |
| 134 | **`ActivitiesRelationManager` has no `defaultSort`** - activities render in primary-key order, not newest-first, and there is no timestamp column at all. `created_at` is `->disabled()` *and* `->default(now())` *and* `->required()`, so the default and required flag are inert. The form exposes `event` and `properties` while the table shows neither, so tampering is invisible from the list; `log_name` and the morph keys are not in the form | `FursuitResource/RelationManagers/ActivitiesRelationManager.php` | - |
| 135 | **`FursuitResource`'s status filter defaults to `'pending'`**, so the list never shows the full set on first load and users may believe approved/rejected fursuits are missing. The resource also has no bulk actions, no delete action and no default sort on what is a moderation queue | `FursuitResource.php:172` | - |
| 136 | **Soft deletes are invisible everywhere.** `Badge` and `Fursuit` both use `SoftDeletes`; neither resource exposes a trashed filter or a restore action, so a soft-deleted record cannot be seen or recovered from the panel at all | `BadgeResource.php`, `FursuitResource.php` | - |

## 10. Counts

| Metric | Count |
|---|---|
| Resources | 13 |
| Relation managers | 4 |
| Custom pages (non-resource) | 3 |
| Widgets (app-owned) | 3 (plus `Filament\Widgets\StatsOverviewWidget`, the framework base class, registered explicitly and rendering nothing) |
| Total table columns | 119 |
| Total filters | 22 |
| Total actions (row + bulk + header/page + in-field suffix) | 79 |
| Landmines catalogued | 136 |
| Total LOC under `app/Filament` | 4,821 across 58 PHP files |

Per-module breakdown of columns, filters and actions:

| Module | Columns | Filters | Actions |
|---|---|---|---|
| `EventResource` | 9 | 0 | 4 |
| `BadgeResource` | 14 | 4 | 5 |
| `FursuitResource` | 7 | 1 | 11 |
| `ActivitiesRelationManager` | 2 | 0 | 5 |
| `SpecialCodeResource` | 4 | 0 | 4 |
| `CheckoutResource` | 9 | 4 | 5 |
| `ItemsRelationManager` | 6 | 0 | 0 |
| `MachineResource` | 4 | 1 | 7 |
| `PrinterResource` | 9 | 0 | 3 |
| `PrintBatchResource` | 11 | 3 | 4 |
| `PrintJobsRelationManager` | 8 | 2 | 1 |
| `PrintJobResource` | 11 | 5 | 8 |
| `StaffResource` | 6 | 1 | 6 |
| `RfidTagsRelationManager` | 5 | 1 | 4 |
| `SumUpReaderResource` | 3 | 0 | 4 |
| `TseClientResource` | 3 | 0 | 2 |
| `UserResource` | 8 | 0 | 4 |
| `PdfGenerator` | 0 | 0 | 2 |
| `BadgePreview` | 0 | 0 | 0 (controls are blade buttons) |
| `DbService` | 0 | 0 | 0 (controls are blade buttons) |
| **Total** | **119** | **22** | **79** |

LOC by area:

| Area | Files | LOC |
|---|---|---|
| `app/Filament/Resources/*.php` (13 resource classes) | 13 | 2,586 |
| `app/Filament/Resources/*/Pages/*.php` | 33 | 818 |
| `app/Filament/Resources/**/RelationManagers/*.php` | 4 | 398 |
| `app/Filament/Pages/*.php` | 3 | 701 |
| `app/Filament/Widgets/*.php` | 3 | 259 |
| `app/Filament/Traits/HasEventFilter.php` | 1 | 38 |
| `app/Filament/Components/EventSelector.php` (dead) | 1 | 21 |
| **Total** | **58** | **4,821** |

Supporting files outside `app/Filament` that belong to the panel: `AdminPanelProvider.php` (72),
`FilamentEventSelector.php` (32), `event-selector.blade.php` (34), `pdf-generator.blade.php` (32),
`badge-preview.blade.php` (51), `db-service.blade.php` (125), `badge-list-css.blade.php` (85),
`badge-list-header.blade.php` (3), `badge-list-range.blade.php` (71), `box-labels.blade.php` (59),
`badge-list.blade.php` (165, dead), `filament-custom.css` (8, committed twice).
