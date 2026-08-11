# Fiscal compliance (German market)

On-site card payments and the fiscal signing/export obligations that come with them. Read this
before touching checkout signing, `SumUpReader`, or anything under the `tse:*` / `dsfin:*` commands.

- SumUp card readers handle on-site card payments (`SumUpReader` model)
- A Fiskaly **TSE** (Technical Security System) signs transactions; managed via the `tse:*` commands
  (`tse:update-state`, `tse:change-admin-pin`), with `TseClient` mapping a machine to its TSE client
  and `App\Domain\Checkout\Services\FiskalyService` doing the signing
- DSFinV-K exports are produced by `App\Domain\Checkout\Services\DSFinVKExportService`, driven by
  `dsfin:generate-direct-export`

A signed receipt is signed against a total, which is why a desk price correction cannot edit a live
transaction - see [`desk-corrections.md`](./desk-corrections.md).

## What the receipt has to carry

KassenSichV has been in force since 30 September 2020 and makes the TSE block on the receipt
mandatory, not cosmetic. `resources/views/receipts/sale.blade.php` prints it under `TSE DATEN`:
serial number, transaction number, signature counter, start and end signature, start/end timestamps
and the process type (`Kassenbeleg-V1`). Those come off the `Checkout` model
(`tse_serial_number`, `tse_transaction_number`, `tse_signature_counter`, `tse_start_signature`,
`tse_end_signature`, `tse_start_timestamp`, `tse_end_timestamp`), populated from the Fiskaly
response - so dropping a column or skipping the signing step silently produces a non-compliant
receipt rather than an obvious error.

The DSFinV-K spec itself (`DSFinV_K_2_4.pdf`) is not kept in the repo; fetch it from the
Bundeszentralamt für Steuern when you need to check a field against it.
