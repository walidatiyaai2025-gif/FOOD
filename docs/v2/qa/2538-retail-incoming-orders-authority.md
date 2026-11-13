# V2 P208 — Retail incoming orders authority (#2538)

- **Source:** FOODEX Unified RBAC/ERP/Sidebar DOCX matrix P208, actual DOCX P197; parent #2503; only integration target `Foodex_V2`.
- **Placement:** **Reuse** existing Operations → Retail Orders (`b2c_orders`) and its pending-status filter. No new page, Sidebar node, route, duplicate controller, or `incoming_orders` ghost module.
- **Actor / entity / goal:** authorized Retail Store Admin reads only pending incoming Retail/B2C orders within the selected, active, assigned store.
- **Entry point:** canonical Operations business group → `admin.b2c.module` (`module=orders`, scoped `store_id`) resolving to `admin.b2c.orders.canonical` (`/admin/b2c/orders`); existing Orders page filter `status=pending`. Normal Operations Orders also uses `admin.operations.orders.index`.
- **Navigation owner/type:** Operations / PAGE_TAB or in-page Orders filter (existing, not a new Sidebar item); Back path is existing Orders group. Route visibility derives from `orders.view`, active store, selected B2C channel and effective role/override; unauthorized link absence is not the security boundary.
- **HTTP authority:** `OrderOperationsController::b2cIndex` pins channel `b2c`; `index` checks `OperationalTenantScope` and selected store, returns 403 for forbidden channel/permission and 404 for foreign store/order. Existing B2C workspace rejects unsupported `incoming_orders` module; do not add it to the navigation.
- **State / UI:** existing localized Orders page handles pending and empty result sets; AR/RTL + EN/LTR, existing accessibility and 320px–1280px responsive shell reused unmodified. Tests exercise real HTML and dataset, not an isolated mock.
- **Master data:** no new business-facing fields or hard-coded IDs; status is a canonical existing enum/filter and store comes from authorized tenancy context.

## Executable security and functional acceptance

`backend/tests/Feature/V2RetailIncomingOrdersAuthorityTest.php` covers:

1. Real pending B2C query in AR and EN, scoped own pending order visible, delivered and foreign orders absent, correct `selectedStatus`/`selectedChannel`, route/Sidebar alias not broken.
2. Forged B2B channel on the B2C canonical alias stays B2C; foreign store and selected foreign order fail; the generic B2B order route denies Retail-only actors.
3. Unauthorized roleless and explicit `orders.view` deny have no Retail Orders navigation and receive HTTP 403; inactive store no longer projects the destination.
4. Unsupported `incoming_orders` module is a real 404, not a dead navigation destination.

## Cross-surface and data/operations impact

| Surface | Classification | Explanation |
| --- | --- | --- |
| Backend | Test-only | No route/API/controller/schema/security policy mutation |
| Dashboard | Existing UI reused | No new markup or styles; existing filters, locale and responsive navigation retained |
| Customer | Not affected | No Customer order API, identity or checkout changes |
| Driver | Not affected | B2C dispatch and driver APIs untouched |
| Van | Not affected | B2B van fulfillment, routes and APIs untouched |

No setup ZIP/update ZIP or production release changes; no database migration, data wipe or live deployment. B2B = VAN ONLY; B2C = DRIVER ONLY.

**Verification policy:** This document records source/coverage intent, not a claim of green CI or manual device review. Before merge, require exact-head Laravel Feature test, Pint/static analysis, Repository Policy, V2 Isolation Gate and Required CI. Existing full responsive/a11y runtime sweep remains with #2750 for unchanged UI. If tests expose a real source bug, coordinate before editing shared Orders code owned by another Issue, then repair the original PR safely.
