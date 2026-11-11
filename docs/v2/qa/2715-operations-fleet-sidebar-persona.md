# FOODEX V2 #2715 — Operations, Distribution and Fleet Sidebar Persona (T353R6)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T353R6. **Parent:** #2503. **Integration exclusively:** `Foodex_V2`. No main merge, production deployment, tag or version release.

## Existing authority and product placement

- Actor: global `OPERATIONS` (wholesale) versus distinct B2C store-scoped `RETAIL_OPERATIONS`.
- Domain: Orders & Operations, field operations, fleet, Vans, execution, and operational reports.
- Placement decision: **Reuse** `AdminNavigation::groupsFor` and `AdminSidebarManager::apply`; use existing Overview, Operations, Field Operations and Analytics groups, not an invented persona-specific Sidebar tree.
- Canonical entry points: `admin.operations.orders.index` (`order_operations`); `admin.field-operations.overview`, `fleet`, `vans`, `assignments`; `admin.driver-live-tracking.index`; `admin.b2b.module` (`drivers`); `admin.reports.index`. All are authorized independently of Sidebar rendering.
- `FieldOperationsController` enforces `drivers.tracking.view` for fleet map and `drivers.b2b.view` for Van and assignment reads. The OPERATIONS role's baseline has `orders.view`, `drivers.b2b.view`, `drivers.tracking.view`, and `reports.view`, but does **not** carry `finance.view` or `drivers.b2c.view`. Do not convert visual reachability into finance or cross-channel privileges.
- Retail operations are store-scoped: authorized B2C orders, driver operations and reporting only within the assigned retail store. No B2B Van/assignments access just because a role is named Operations.
- **Verified route-contract repair (CI rescue):** `b2c_incoming_orders` was made visible in the Retail Sidebar presentation whitelist although `B2cWorkspaceController::PROFILE_MODULES['RETAIL_STORE']` intentionally excludes it and a pre-existing `B2cAdminWorkspaceTest` requires HTTP 404. The new visible link was a dead end, not a permission gap. The invalid whitelist entry has been omitted by safely reconciling the original #2715 PR to the current `Foodex_V2` base tree; the canonical Retail `incoming_orders` controller 404 remains unchanged. The subsequent full Laravel CI found a second real discoverability mismatch: the Operations Sidebar generator still constructed unsupported `b2c_incoming_orders` (then filtered it out) but never constructed the whitelisted, authorized `b2c_orders` entry. The final source correction changes ONLY this existing Operations group projection to `module=orders` with its existing `orders.view` permission; the real Retail orders HTTP route remains independently store-authorized. Existing `b2c_orders` is the authorized Retail orders entry.
- **Separate route parity resolved:** V2 #3070 / PR #3079 was merged into `Foodex_V2` (merge `0930ae3e7230d76fd87f421eb28d2da05f632a79`) with exact-head CI green. `field_ops_assignments` Sidebar and page now share `drivers.b2b.view`, without widening controller grants.

## Automated test coverage

`backend/tests/Feature/V2OperationsFleetSidebarPersonaContractTest.php` uses canonical `CoreReferenceSeeder` and `RefreshDatabase`:

1. Wholesale OPERATIONS displays Overview, authorized orders, live tracking, B2B drivers, Field Operations Overview/Fleet/Vans/Assignments, Reports in both AR/RTL and EN/LTR. Every specified entry has an existing named Laravel route.
2. Real authenticated GETs to the four Field Operations page routes return a successful or ordinary redirect response while testing the shared page locale/direction contract; no fake `href` only checks.
3. No accidental Retail drivers/orders, finance/treasury, or privileged Sidebar administration links.
4. Retail OPERATIONS assigned Store A can open its authorized B2C `orders` module, but must not see or open the unsupported `incoming_orders` module (404 even with `orders.view`). Foreign Store B remains denied (403/404). Direct B2B Van/assignment URLs are denied, and B2B fleet links are not leaked through Sidebar.
5. No permission grants, role mutation, live assignment changes, financial postings, artificial data or API/dispatch feature toggles.

## Impacts and release boundary

| Surface | Assessment |
| --- | --- |
| Backend / Dashboard | Focused Laravel Feature regression plus Operations group's one authorized `b2c_orders` link restoration, preserving Retail profile rejection of unsupported incoming_orders, all existing route/controller/permission/tenant checks and data |
| Customer | Not affected: no checkout, cart, order status or Customer API contract change |
| Driver | Not affected: no GPS, B2C dispatch, driver assignments, lifecycle or Flutter interface change |
| Van | Not affected: no wholesale-only dispatch, Van assignment transition, visits, route execution or Flutter interface change |
| Finance / SoD | Not affected: no collection, treasury or approval mutation, and finance links must remain denied |
| AR/EN / RTL/LTR / accessibility | Both locales and page direction checked in Laravel; no template changes. Final manual browser, screen-reader and responsive audit is deferred to overall V2 gate #2750 per owner decision on #2503 |

The persona is an **authorization projection**, not a separate permission role or independent menu security policy. Incomplete live workflow evidence must stay tracked by the correct atomic V2 child issue; this regression alone does not claim production ERP or fleet acceptance.

## Exact-head CI

One issue, one branch `test/2715-v2-ops-fleet-sidebar-persona-contract`, one PR targeting `Foodex_V2`. Verify Repository Policy, V2 Isolation Gate, PHP syntax/Pint/PHPStan, Laravel Feature tests, AR/EN localization, security, applicable DB/queue checks, and terminal `required-ci-gate` on the final PR head before merge. Fix any red job on the same branch and re-run; never override policy. Manual UAT/staging is deferred, not mandatory automated checks.

## Backend test CI rescue — November 10, 2026

At #3068 old head `fa901eb1`, Required CI #42890579057 failed two authenticated HTTP tests (2,589 passed). The former `b2c_incoming_orders` Sidebar presentation whitelist entry exposed a route that `B2cWorkspaceController::PROFILE_MODULES['RETAIL_STORE']` intentionally rejects as HTTP 404.

An initial rescue attempt opened that profile route, but further review identified the pre-existing `B2cAdminWorkspaceTest::test_retail_wholesale_purchases_shows_linked_b2b_orders_and_keeps_customer_orders_separate` explicitly expecting `/admin/b2c/incoming_orders` 404. **That expansion was reversed before merge** to protect the existing security/product contract; no final controller scope expansion is intended. The correct remedy is to remove the newly introduced Sidebar visibility entry in the #2715 original PR while keeping the unchanged controller 404. The separate #3070 worker completed PR #3079 and released its shared-file ownership. This #2715 rescue retains the current integrated `AdminNavigation.php` unchanged rather than reintroducing the invalid whitelist hunk.

The #2715 regression checks both Arabic RTL and English LTR: Retail actor can open its canonical `b2c_orders` module; unsupported `b2c_incoming_orders` remains hidden and direct HTTP 404; foreign-store access remains 403/404; B2B Van/assignment access denied. No Customer, Driver, or Van Flutter/API/dispatch/finance contract changes. #3070 (PR #3079) is verified MERGED into `Foodex_V2`; its independent assignments route-parity and authorization test are retained.

**Latest CI rescue:** After non-forced two-parent reconciliation removed the faulty `b2c_incoming_orders` whitelist entry, full Laravel CI found the second mismatch: the existing Operations group generated the unsupported incoming_orders module and omitted the correct `b2c_orders` navigation destination. The branch was reconciled again from the current integrated `Foodex_V2` tree, carrying the issue's Laravel regression, QA document and ONLY the supported Operations module projection change. Test now also asserts the exact `admin.b2c.module` route and authorized store ID. Unrelated integrated `b2c_drivers`, independent Retail Content work and #3070 field assignments rights remain untouched. Every required exact-head CI check must be rerun and pass before any merge/closure. Manual device/browser UAT remains separately deferred.
