# FOODEX V2 #2715 — Operations, Distribution and Fleet Sidebar Persona (T353R6)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T353R6. **Parent:** #2503. **Integration exclusively:** `Foodex_V2`. No main merge, production deployment, tag or version release.

## Existing authority and product placement

- Actor: global `OPERATIONS` (wholesale) versus distinct B2C store-scoped `RETAIL_OPERATIONS`.
- Domain: Orders & Operations, field operations, fleet, Vans, execution, and operational reports.
- Placement decision: **Reuse** `AdminNavigation::groupsFor` and `AdminSidebarManager::apply`; use existing Overview, Operations, Field Operations and Analytics groups, not an invented persona-specific Sidebar tree.
- Canonical entry points: `admin.operations.orders.index` (`order_operations`); `admin.field-operations.overview`, `fleet`, `vans`, `assignments`; `admin.driver-live-tracking.index`; `admin.b2b.module` (`drivers`); `admin.reports.index`. All are authorized independently of Sidebar rendering.
- `FieldOperationsController` enforces `drivers.tracking.view` for fleet map and `drivers.b2b.view` for Van and assignment reads. The OPERATIONS role's baseline has `orders.view`, `drivers.b2b.view`, `drivers.tracking.view`, and `reports.view`, but does **not** carry `finance.view` or `drivers.b2c.view`. Do not convert visual reachability into finance or cross-channel privileges.
- Retail operations are store-scoped: authorized B2C orders, driver operations and reporting only within the assigned retail store. No B2B Van/assignments access just because a role is named Operations.
- **Fix:** `AdminNavigation::groupsFor` generated `b2c_incoming_orders` from the legitimate `orders.view` retail module, but the later `$retailB2cKeys` allow-list omitted that canonical key and silently removed it. Add only `b2c_incoming_orders` to the existing presentation allow-list; keep store-scoped `module()` + controller checks authoritative. This fix does not mint another store's permission. Shared file ownership was transferred explicitly from #2713 via GitHub issue comments before editing; the two commits change distinct hunks and require conflict review on merge.
- **Known separate mismatch:** `field_ops_assignments` Sidebar can be exposed by `field_ops.manage` while its page requires `drivers.b2b.view`. Registered as V2 #3070, ready for a distinct worker after this shared file is released. Do not widen controller grants or mark this parent persona fully accepted until this defect is resolved.

## Automated test coverage

`backend/tests/Feature/V2OperationsFleetSidebarPersonaContractTest.php` uses canonical `CoreReferenceSeeder` and `RefreshDatabase`:

1. Wholesale OPERATIONS displays Overview, authorized orders, live tracking, B2B drivers, Field Operations Overview/Fleet/Vans/Assignments, Reports in both AR/RTL and EN/LTR. Every specified entry has an existing named Laravel route.
2. Real authenticated GETs to the four Field Operations page routes return a successful or ordinary redirect response while testing the shared page locale/direction contract; no fake `href` only checks.
3. No accidental Retail drivers/orders, finance/treasury, or privileged Sidebar administration links.
4. Retail OPERATIONS assigned Store A can open its authorized B2C incoming orders; foreign Store B is denied (403/404). Direct B2B Van/assignment URLs are denied, and B2B fleet links are not leaked through Sidebar.
5. No permission grants, role mutation, live assignment changes, financial postings, artificial data or API/dispatch feature toggles.

## Impacts and release boundary

| Surface | Assessment |
| --- | --- |
| Backend / Dashboard | Focused Laravel Feature regression plus one-line addition to the existing authorized Retail Sidebar item allow-list. Routes, controllers, permission model, and data stores unchanged |
| Customer | Not affected: no checkout, cart, order status or Customer API contract change |
| Driver | Not affected: no GPS, B2C dispatch, driver assignments, lifecycle or Flutter interface change |
| Van | Not affected: no wholesale-only dispatch, Van assignment transition, visits, route execution or Flutter interface change |
| Finance / SoD | Not affected: no collection, treasury or approval mutation, and finance links must remain denied |
| AR/EN / RTL/LTR / accessibility | Both locales and page direction checked in Laravel; no template changes. Final manual browser, screen-reader and responsive audit is deferred to overall V2 gate #2750 per owner decision on #2503 |

The persona is an **authorization projection**, not a separate permission role or independent menu security policy. Incomplete live workflow evidence must stay tracked by the correct atomic V2 child issue; this regression alone does not claim production ERP or fleet acceptance.

## Exact-head CI

One issue, one branch `test/2715-v2-ops-fleet-sidebar-persona-contract`, one PR targeting `Foodex_V2`. Verify Repository Policy, V2 Isolation Gate, PHP syntax/Pint/PHPStan, Laravel Feature tests, AR/EN localization, security, applicable DB/queue checks, and terminal `required-ci-gate` on the final PR head before merge. Fix any red job on the same branch and re-run; never override policy. Manual UAT/staging is deferred, not mandatory automated checks.
