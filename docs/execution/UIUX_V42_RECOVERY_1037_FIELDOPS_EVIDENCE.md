# UIUX v4.2 Recovery — Field Operations Evidence Manifest (#1037)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1037 — Field Operations lookups + map-first geography + row-action compliance**  
Canonical branch: `feat/1037-fieldops-map-lookup-compliance`  
PR: **#1048**

This manifest records owner-lane source, automated runtime-route tests, and dedicated browser-evidence selectors for F01–F06. It does **not** mark central matrix rows PASS; #1042/#1043 remain the independent integrated runtime/convergence gates.

| Row | Source evidence | Automated/runtime evidence | Dedicated browser evidence |
|---|---|---|---|
| F01 | Van suspension/transfer uses `select[data-van-transfer-lookup]`, never a typed Van ID | `FieldOperationsUiFoundationTest::test_super_admin_runtime_surfaces_render_lookup_map_and_compact_action_contracts` | `vans__{desktop-1280,tablet-768,mobile-390}__{ar,en}.png` |
| F02 | assignment representative/operator and Warehouse are authoritative selectors | static contract + actual authenticated GET of `/admin/field-operations/assignments` | `assignments__...__{ar,en}.png` |
| F03 | Visit Customer, Store, Route and Order are selector-based lookups | static contract + actual authenticated GET of `/admin/field-operations/visits` | `visits__...__{ar,en}.png` |
| F04 | Address Quality confirmation uses Territory selector; public review identity replaces internal numeric display | static contract + runtime route assertion for `data-territory-lookup` | `address-quality__...__{ar,en}.png` |
| F05 | Territory workflow is map-first; point markers are draggable, double-click deletes, Undo/Clear exist, polygon validation is enforced; raw GeoJSON is collapsed Advanced-only for Super Admin and hidden for ordinary users | static interaction guard + authenticated Territories runtime route assertion | `territories-map__...__{ar,en}.png`; browser guard requires map/Undo/Clear/Advanced and verifies Advanced is collapsed by default |
| F06 | Field Operations tables/cards use compact shared FOODEX ops primitives and one ellipsis action affordance where actions exist | runtime Vans page proves `foodex-ops-actions` + `⋮`; responsive brand component guard | Vans / Visits / Address Quality screenshots at desktop/tablet/mobile in AR/EN |

## Normal-flow raw-input audit

Business-facing references are selectors:
- `home_warehouse_id`
- `transfer_target_van_id`
- `van_id`
- `driver_id`
- `representative_user_id`
- `territory_key`
- `warehouse_id`
- `assignment_id`
- `customer_id`
- `store_id`
- `route_key`
- `order_id`

The names remain backend identifiers for form submission, but the UI control is a human-readable `select`, not a typed internal-ID field.

Territory `geojson` is:
- a hidden synchronization value for ordinary users, driven by the map; or
- an explicitly collapsed `Advanced GeoJSON` textarea for Super Admin only.

Routing Policy JSON belongs to the separate technical routing-policy workspace and is not the normal Territory/Visit/Van business flow owned by F01–F06.

## Runtime route evidence

`FieldOperationsUiFoundationTest` now authenticates a real Super Admin, creates deterministic Van/Territory/Address Quality fixtures, and performs real Laravel GET requests against Vans, Assignments, Visits, Territories and Address Quality routes. It validates rendered runtime HTML rather than only reading the Blade file.

## Dedicated AR/EN browser evidence

- Seeder: `backend/database/seeders/FieldOperationsScreenshotEvidenceSeeder.php`
- Capture script: `scripts/capture_field_operations_screenshots.mjs`
- Workflow: `.github/workflows/field-operations-runtime-evidence.yml`

The workflow captures 30 PNGs: five representative Field Operations surfaces × desktop 1280 / tablet 768 / mobile 390 × Arabic/English. Browser assertions fail on missing lookup/map controls, default-open Advanced GeoJSON, auth redirects, horizontal overflow, or undersized screenshots.

## Acceptance boundary

Owner implementation/evidence for F01–F06 is complete. Central rows remain `OPEN` until #1042 independently reviews the integrated runtime and #1043 converges the matrix.
