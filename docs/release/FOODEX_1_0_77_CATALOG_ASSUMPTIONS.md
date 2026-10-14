# FOODEX 1.0.77 Catalog Assumptions

Issue: #1383  
Release: 1.0.77  
Authoritative source SHA-256: `1333292c13e6bbddacc26f728485021459f914964af29ec589894086241cc523`

## Policy

Official source values are preserved unchanged. Only source-null or explicitly ambiguous launch blockers are resolved. Every launch field is recorded in `backend/database/seeders/fixtures/foodex-launch-catalog-provenance.json` with field-level provenance. Image reconstruction is owned by #1382; this issue records the expected production path and `reconstructed_asset` provenance without fabricating image bytes.

## Deterministic assumptions

| SKU | Field | Final | Rule |
| --- | --- | ---: | --- |
| FDX-LAUNCH-022 | price | 165 | Median of official 5 kg sauce prices: 150, 180, 185, 150, 165. |
| FDX-LAUNCH-023 | carton_count | 9 | Ambiguous [9, 12] pair: choose the lower carton option. |
| FDX-LAUNCH-023 | price | 1485 | Preserve option pairing by position: carton 9 maps to 1485. |
| FDX-LAUNCH-024 | carton_count | 9 | Reuse the resolved adjacent 900 g poultry carton count. |
| FDX-LAUNCH-025 | carton_count | 9 | Reuse the resolved adjacent 900 g poultry carton count. |
| FDX-LAUNCH-025 | price | 1485 | Reuse the resolved adjacent 900 g poultry price. |

The official Pane price of 30 is intentionally **not corrected or normalized** because the source supplied it as an authoritative non-null value.

## Image provenance

All 25 production image paths are represented in the ledger as `reconstructed_asset`. Existing source images are below the 800×800 launch minimum and several rows have no source image. #1382 is responsible for producing the actual content-faithful assets. #1383 does not alter or invent those image bytes.

## Re-run contract

The fixture contains no runtime-generated catalog values. The inference decisions above are materialized as constants and the provenance ledger is static/versioned, so repeated seeding resolves the same SKU, price and carton values. Seeder `updateOrInsert` keys remain unchanged.

## Launch-data result

- Price blockers resolved: **yes**
- Carton blockers resolved: **yes**
- Source official values modified: **no**
- Field-level provenance present: **yes**
- Image reconstruction bytes complete: **dependency on #1382**
- Customer app changed: **no**
