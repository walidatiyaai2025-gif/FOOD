# FOODEX 1.0.113 Synchronized Release

## Scope

Synchronized Dashboard and mobile release following the owner-confirmed manual Dashboard 1.0.112 production checkpoint.

## Included changes

- Unified Order Operations enhancements with compact inline state actions, assignment/routing controls, CS ownership and terminal-state presentation.
- Scoped manual B2B Driver dispatch while preserving Van-first automatic B2B routing.
- Driver reassignment and execution ownership safeguards.
- Dynamic order details, invoice and timeline dialogs with localized printable output.
- Expanded customer and Driver lifecycle notification coverage.
- New Order channel, warehouse and in-stock product scoping.
- Driver App support for manually assigned B2B work.
- Driver Home live refresh every 5 seconds with foreground popups for new assignments and subsequent assignment/order changes.
- Driver lifecycle push coverage for assignment, reassignment, unassignment, status, order-detail, Customer Service, invoice and payment changes with per-event deduplication.
- Compact no-scroll Driver Home fitted above bottom navigation, reduced card heights, notification action in the header, legacy footer/diagnostics chrome removed, and the `picked_up` status card hidden while preserving the underlying workflow state.
- Debug-only local development bypass for Driver version/location gates and Van location tracking; production/release enforcement remains unchanged.
- Red Driver Home Pending orders card showing the current `assigned` workload with direct assigned-only navigation.

## Release identity

- Repository / Dashboard: `1.0.113`.
- Customer: `1.0.113+113`.
- Driver: `1.0.113+113`.
- Van: `1.0.113+113`.
- Production API: `https://foodex.50sols.com`.

## 1.0.112 lineage note

Production Dashboard 1.0.112 was already installed manually by the owner before this repository convergence. It is treated as an operational deployment checkpoint, not as a canonical synchronized repository release. Repository publication therefore advances directly to 1.0.113.

## Validation

Publish only from the exact merged `main` source after required CI, synchronized mobile builds, Dashboard Update Center bundle generation, preview generation, Setup/fresh-install acceptance and release provenance checks pass.
