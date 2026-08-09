# Public site navigation

The chrome around the public (attendee-facing) pages: header, pill rail, bottom tab bar and footer.
Read this before adding, hiding or reordering a public destination, and before touching anything in
`resources/js/Components/SiteNav/`.

## Shape

The public chrome is thumb-first: a 56px header that carries brand and account only, a pill rail
under it on `md+`, and a **fixed bottom tab bar below `md`**. All of it lives in
`resources/js/Components/SiteNav/`, wired up by `Layouts/Layout.vue`.

Pages under the layout need no bottom padding of their own; the layout spacer clears the tab bar.

## Destinations are declared once

**Destinations are declared once**, in `SiteNav/navItems.js`, and rendered three times (rail, tab
bar, footer) through `useSiteNav()`. Add a link there, not in a component - the old header and
footer each had their own list and disagreed about what the site contained.

The tab bar has four slots plus "More", filled by `primary.slice(0, TAB_SLOTS)`. A hidden entry
promotes the next one instead of leaving a gap, so never hard-code which four.

## Active state comes from `page.url`

**Active state must be derived from `page.url`, never from `window.location`.** Ziggy's
`route().current()` is still the matcher, but it reads `window.location`, which Vue cannot track -
and Inertia reuses the same `Layout` instance across visits, so a template calling a `current()`
helper never re-renders and the highlight sticks on whatever page was loaded from the server (it
only looks right on a full reload). `useSiteNav()` therefore builds a Ziggy router with `location`
set from Inertia's reactive `page.url`. Test nav changes by **clicking**, not by loading each URL.
Ziggy's `current()` also takes one pattern and reads its second argument as route params, so `match`
patterns are tested one at a time.

## Info pages and the Catch-Em-All entry

`/faq`, `/pickup` and `/catch-em-all` are `InfoController` (`info.*`). `/catch-em-all` is an info
page with a button into the game subdomain; `/fcea` stays a bare redirect for QR codes and print.
The Catch-Em-All nav entry is gated on the `catchEmAllActive` shared prop, which
`HandleInertiaRequests::share()` computes from the event it already loaded
(`Event::isCatchEmAllActive()`) - do not add a second query for it.
