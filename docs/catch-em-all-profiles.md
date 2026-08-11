# Catch-Em-All profiles

The attendee-written half of the game: a description, up to ten links and the avatar
mirrored from the identity provider, shown to other players on a public page. All three
are reviewed before anyone else sees them.

Read this before touching `App\Models\UserProfile\*`, `routes/manage/profiles.php`, the
`catch-em-all.profiles.*` routes, or `MirrorUserAvatarJob`.

## The record

`user_profiles` is one row per user, created with the account by
`App\Observers\UserObserver` (registered in `AppServiceProvider`), so every user has one
and no code path has to create it on the fly. The public page is addressed by `uuid`, not
by id: the URL is shared between attendees and a sequential id would enumerate the
attendee list.

`user_profile_links` holds the links, one row per URL, unique per profile.
`UserProfileLink::normalizeUrl()` lowercases the scheme and host and leaves the path
alone, so `HTTPS://EXAMPLE.COM/Me` and `https://example.com/Me` are one link but two
different paths are two. On MySQL the column is `utf8mb4_bin`, because the default
collation would fold those two paths together.

## Status is derived, not stored

There is no `status` column. `UserProfile::status` is an accessor over the two timestamps:

| `approved_at` | `rejected_at` | State |
|---|---|---|
| set | null | `Approved` - description, links and avatar are public |
| null | set | `Rejected` - hidden, and `rejection_reason` is shown to the owner |
| null | null | `Pending` - hidden, waiting in the queue |

It is still a Spatie state (`App\Models\UserProfile\States`), so verdicts go through
`transitionTo()` and each transition writes its own activity-log entry. A query that wants
one of the three filters on the timestamps rather than on a value -
`whereNull('approved_at')->whereNull('rejected_at')` is the pending queue.

A new profile is created **approved**: it is empty, so there is nothing to review. It
becomes review work the moment the attendee writes something.

## Anything the attendee changes sends it back

Three paths call `requiresReapproval()` and all three exist because the content they guard
is public:

- editing the description (`UserProfile::updating`)
- adding, changing or removing a link (`UserProfileLink::booted`)
- a changed mirrored avatar (`MirrorUserAvatarJob`)

So an approved profile cannot be rewritten into something else after the fact. This is
also why approval is all-or-nothing: the verdict publishes the description, every link and
the avatar together, and the reviewer is told so on the button.

## The avatar mirror

`users.avatar` is the identity provider's URL and is refreshed on every login.
`MirrorUserAvatarJob` downloads it, normalizes it to a 512x512 webp on the storage disk and
records the path in `users.avatar_path`; `User::avatar_url` signs a temporary URL for that
path and falls back to `Storage::url()` on a disk that cannot sign. `AuthController` only
dispatches the job when the URL is new, changed, or has never been mirrored, so an ordinary
login costs nothing.

The job compares the rendered bytes rather than the URL, so an IdP that reissues the same
picture under a new URL does not send the profile back for review.

## The review queue

`/admin/profiles`, open to reviewers and admins (see [`admin/roles.md`](admin/roles.md)).
The list defaults to the pending filter and to the oldest change first, because it is a
backlog; "Review pending" hands the reviewer the first record without going through the
list.

The verdicts authorize `view`, not `update`: `UserProfilePolicy::update` is `is_admin` and
means editing the row, which no route does.

**The claim is enforced**, unlike the fursuit queue, which replaced its lock with advisory
presence. Opening a profile takes a five-minute claim (renewed on every load) and a verdict
without it is refused with a toast. A profile is four short fields, so two reviewers landing
on the same one would decide it seconds apart; the claim expires on its own, so a closed
browser costs five minutes and not the row. `next` skips profiles somebody else is holding.

There is no undo window and no queued delivery: nothing is mailed to the attendee, so a
mistaken verdict is corrected by deciding again. A rejected profile can be moved back to
pending from the same page.
