# Fiscal compliance (German market)

On-site card payments and the fiscal signing/export obligations that come with them. Read this
before touching checkout signing, `SumUpReader`, or anything under the `tse:*` / `dsfin:*` commands.

- SumUp card readers handle on-site card payments (`SumUpReader` model)
- A Fiskaly **TSE** (Technical Security System) signs transactions; managed via the `tse:*` commands
  (`tse:update-state`, `tse:change-admin-pin`). See `TSE.md` in the repo root
- DSFinV-K exports are produced by `dsfin:generate-direct-export`. See `DSFinV_K_2_4.pdf` in the repo
  root

A signed receipt is signed against a total, which is why a desk price correction cannot edit a live
transaction - see [`desk-corrections.md`](./desk-corrections.md).
