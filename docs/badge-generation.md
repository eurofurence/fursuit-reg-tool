# Badge generation

How a badge becomes artwork. Read this before adding a badge type for a new event or changing
positioning, fonts or image handling in `app/Badges/`.

- Badges are rendered as PDFs using custom badge classes in `app/Badges/`
- Each badge type (e.g. `EF28_Badge`, `EF29_Badge`) extends `BadgeBase_V1` (in `Bases/`) and defines
  positioning/fonts; reusable field/layout helpers live in `app/Badges/Components/`
- PDF generation uses `mpdf/mpdf`; images are processed with Intervention/Imagine and stored on S3
- QR codes are generated for the Catch-Em-All game integration (see `CATCH.md` in the repo root)

Rendering is server side and one-shot: `GenerateBadgePrintFileJob` writes the print file and its
content hash. Downscaling of attendee uploads before they reach the PDF is covered in
[`printing.md`](./printing.md#print-files).
