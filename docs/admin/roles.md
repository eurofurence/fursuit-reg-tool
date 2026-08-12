# Admin panel roles

Who may reach what inside `/admin`, and why the line falls where it does.

Read this before adding a route under `routes/manage/`, before widening a policy's
`viewAny`/`view`, and before adding a card to Tools or a pane to Settings.

## The two gates

Both live in `app/Providers/AuthServiceProvider.php`:

| Gate | Rule | What it means |
|---|---|---|
| `access-manage` | `is_admin \|\| is_reviewer` | May open the panel at all |
| `manage-admin` | `is_admin` | May do anything in it beyond reviewing |

`access-manage` is on the whole group in `bootstrap/app.php`, so it is the door. It is
**not** a permission: passing it buys you the dashboard, the badge list and the fursuit
queue, and nothing else. Everything else carries `can:manage-admin` on its route group.

Guests never see a login form on `/admin`. There is no `/admin/login`; the `auth`
middleware redirects to the named `login` route, which is the Identity SSO flow. A
signed-in user holding neither flag gets a 403.

## What a reviewer gets

Four rail entries, and that is the whole panel for them:

- **Dashboard** - counts and the two charts.
- **Badges** - the list, and the detail page at `{badge}/edit`, read-only. There is no
  separate badge show page, so the edit route *is* the detail; it authorizes `view` and
  hands the page `canEdit` from `update`, which is `is_admin`. The two status selects
  render as text and the save bar is absent.
- **Fursuits** - the list, a fursuit, its activity log, and the review queue with every
  verdict: approve, reject, block publication, unblock, approve-rejected, notify, undo.
  Editing or deleting the fursuit row is not part of that and is admin-only.
- **Profiles** - the Catch-Em-All profile queue: the list, a profile, its activity log and
  the three verdicts (approve, reject with a reason, move back to pending). Reviewing what
  an attendee wrote about themselves is the same job as reviewing their photo, which is why
  the module carries no `can:manage-admin`. There is no create, edit or delete route at all;
  `UserProfilePolicy::update` and `delete` stay `is_admin` for whatever adds one later. See
  [`../catch-em-all-profiles.md`](../catch-em-all-profiles.md).

The header event selector and the per-table column preferences come with them, because
the four screens need them.

## What a reviewer does not get

Everything else, including several things that were open to them when the panel was
rebuilt and are not any more:

| Surface | Was | Is | Why |
|---|---|---|---|
| Checkouts | read | admin | Attendee payment records. Fiscal, and nothing a fursuit reviewer acts on |
| Print Batches | read | admin | The live print run. A reviewer could watch it but never touch it |
| Print Jobs, Printers, Machines, Staff, SumUp Readers, TSE Clients, Special Codes | admin | admin | Unchanged |
| Settings, all panes | read | admin | How the convention is configured. Review Reasons in particular configures the queue rather than being part of working it |
| Tools index, Badge Preview, PDF Generator | full | admin | Badge Preview renders any attendee's card from a custom id typed into a box; the PDF Generator enumerates the badge table. Lookups over everything, not review surfaces |
| `admin.badge-pdf.{view,download}` | read | admin | Same lookup, older URL, kept because staff have it bookmarked |
| `admin.uploads.store` | write | admin | Its only caller is the fursuit image field on the fursuit form, which is admin-only |
| Badge edit + save | write | admin | Moving a badge between fulfillment or payment states is desk work |
| Queue print, bulk queue print | write | admin | Sending cards to a printer is desk work |

This reverses parity checklist line 83, which kept Badge Preview and the PDF Generator
open to reviewers because the pages they replaced had no access check at all. That was
parity with an accident, not with a decision.

## How a surface is guarded

Three places, and a new module should use all three where they apply:

1. **Route group middleware.** `->middleware('can:manage-admin')` on the group in
   `routes/manage/{module}.php`. This is what a route audit can see without reading method
   bodies, and it is what `ReviewerScopeTest` enumerates.
2. **The policy.** `viewAny`/`view` answer `is_admin` for anything a reviewer must not
   read. The rail and the in-page menus filter on these, so a gated policy also removes
   the entry rather than leaving a link that 403s.
3. **The controller or form request.** `Gate::authorize('manage-admin')` in the method, or
   `authorize()` on the FormRequest. This is the half that stays attached if the routes are
   ever regrouped or re-registered elsewhere.

None of the three is redundant with the others. The panel already applied this pattern to
DB Service before reviewers were narrowed; it is now the default.

## The test that keeps this true

`tests/Feature/Manage/ReviewerScopeTest.php` walks every registered `admin.*` route and
requires each one to be either on an explicit allowlist or guarded by `can:manage-admin`.

A new module lands with no middleware on its group, is not on the allowlist, and the test
fails. That is deliberate: the panel gate admits two roles, so "I forgot" defaults to
"reviewers can reach it", and the failing test is what turns that into a decision. Adding
the middleware and adding the name are both one line; pick one on purpose and, if it is the
allowlist, say why here.
