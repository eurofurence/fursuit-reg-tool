# Desk corrections and the manager gate

What a cashier at the POS may change on a badge, what needs a manager, and why a price change cannot
touch a live transaction. Read this before editing `POS\BadgeEditController`, `ManagerApprovalService`
or `CheckoutService`.

## Two edits, two bars

Two POS edits, two different bars, both in `POS\BadgeEditController`:

- **Details** (fursuit name, species, `dual_side_print`, `published`, `catch_em_all`) - any cashier,
  no approval. Reached from the attendee page by selecting **exactly one** badge, which then enables
  "Edit badge" in the commit bar; deliberately not a button on every row. Unlike the attendee-facing
  `BadgeController@update`, this does **not** send the fursuit back to Pending review, and it does
  not clear the print file: `GenerateBadgePrintFileJob` keys off a content hash, so a renamed badge
  re-renders on its next print by itself.
- **Price** - needs a manager. `staff.is_manager` is the flag; `ManagerApprovalService::approve()`
  passes a manager who is already signed in at the till, and otherwise takes a manager PIN or a
  scanned RFID tag in the same field (PIN first, then tag - the two namespaces do not overlap).
  Failed attempts are rate limited per machine.

## A price change rebuilds the checkout

**A price change cannot edit a live transaction.** The Fiskaly receipt is signed against a total, so
`POST /pos/badges/prices` reprices the badges, then `CheckoutService::rebuild()` cancels the open
checkout (end signature) and opens a fresh one holding the same badges, redirecting to it. Only the
ACTIVE checkout **on the same machine** is rebuilt. Already-paid badges are refused outright - that
is a refund, not a correction. Every override is written to the activity log with from/to, the
approving manager and an optional reason.

`CheckoutService` is where a checkout is built; `CheckoutController@store` is a thin caller.
