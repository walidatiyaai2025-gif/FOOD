# V2 #2524 — P194 Orders navigation and HTTP authorization

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P194 / Customer & Sales. Parent #2503. Target: `Foodex_V2` **only**.

## Placement and discoverability

- Domain / entity: Customer & Sales / Order; user goal: view and track authorized orders.
- Existing parent / canonical route: Operations → Orders (`admin.operations.orders.index`, `order_operations`); Retail store's existing `b2c_orders` module (`admin.b2c.module?module=orders&store_id=<authorized>`).
- Placement decision: **REUSE** the operational orders grid and the already-scoped Retail workspace. A third route, duplicated sidebar entry, placeholder or inactive ERP module would be incorrect.
- Navigation type: `SIDEBAR`, existing Operations group; actual order details/timeline/actions remain contextual. Dashboard mobile uses the same authorized sidebar tree/off-canvas and shared Orders grid UI. Arabic/English direction and HTTP rendering are tested without changing views.
- Permissions: read `orders.view` at the server, distinct from `orders.manage`, `orders.dispatch` and `orders.create`. An explicit effective DENY removes read access and both links for the relevant user.
- Scope: Retail `B2C` active store assignment plus allowed Retail business profile for store module; B2B/Wholesale handled by separate operational scope, never granted to a Retail-only operator. A foreign store ID is a masked 404; no cross-channel escalation. Disabled/inactive store and unsupported Restaurant orders module are unavailable.

## Executable acceptance

`backend/tests/Feature/V2OrdersSidebarAuthorizationContractTest.php` exercises the exact production navigation service, named HTTP routes, persisted scoped order, read-only rendered grid, and AR/EN RTL/LTR:

1. One authorized Retail store has both canonical links and can open each actual business surface.
2. Cross-store and Retail-to-Wholesale reads (including a forged direct order details URL) are denied.
3. Effective global `orders.view` DENY removes both links and returns HTTP denial.
4. A Restaurant profile cannot open the unsupported Retail Orders module; an inactive Retail store cannot provide a valid operational read scope.
5. A roleless principal cannot acquire any Orders sidebar or direct-route permission.

These tests are regression and contract evidence, **not** a claim of manual browser/device, load, release, or production acceptance. The existing Orders page supplies its shared empty/filter/error states and accessibility composition; final responsive/mobile browser UAT remains under #2503/#2750. No new ERP entitlement or readiness state is invented for preexisting operational orders.

## Backend / Dashboard / mobile impact

- Backend: **test-only**; existing `OperationalTenantScope`, route permissions and Orders controller untouched.
- Dashboard: **test-only**; reuse of shared existing Orders UI, Sidebar projection, AR/EN; no visual redesign.
- Customer Flutter: **no code/API change**; customer order APIs and account-scoped status retained.
- Driver Flutter: **no code/API change**; B2C-only driver dispatch remains unchanged.
- Van Flutter: **no code/API change**; B2B-only Van fulfillment remains unchanged.
- No migrations, data updates, schema change, release artifact, user mutation, `main` integration, or production deployment.

## Merge contract

One Issue (#2524), one branch `test/2524-v2-orders-authorized-sidebar-contract`, one PR with base `Foodex_V2`. Verify exact final PR head required gates, including Laravel/security, localization and V2 isolation. Close #2524 only after verified green CI and merge; manual acceptance deferred to V2 final as authorized by master #2503.
