# V2 #2692 — Inventory Controller (T122R12) authority evidence

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / `T122R12`. **Parent:** #2503. **Only integration branch:** `Foodex_V2`. This is an isolated V2 change, not a production release.

## Canonical placement and scope

- **Domain/entity:** Inventory / inventories, warehouses, stock movements.
- **Persona and user goal:** `INVENTORY_CONTROLLER` (مراقب المخزون), inspect authorized stock and record a traceable physical count correction.
- **Reuse:** existing wholesale `admin.b2b.module?module=inventory` screen (route `admin.b2b.module`, module `inventory`); existing API `GET /api/v1/admin/inventory` and `POST /api/v1/admin/inventory/{inventory}/adjust`. No new route, dashboard surface, sidebar group or business form.
- **Navigation owner:** existing inventory domain / wholesale workspace; `inventory.view` is the canonical read authority. The existing desktop/tablet/mobile web UI and AR/RTL + EN/LTR rendering are reused. Inventory mutation is already a business action in that workspace.
- **Explicit authority:** role `INVENTORY_CONTROLLER` is global, tenant-level, Wholesale/B2B only. It requires an **enabled** `inventory_wms` module, has `inventory.view`, `inventory.adjust`, `catalog.view` and `reports.view`. No `inventory.manage`, `catalog.edit`, `reports.export`, finance approval, customer order dispatch or platform-security privileges.
- **B2B/B2C segregation:** the B2B channel allowlist explicitly recognizes the role, but B2C accepts only authorized store-scoped actors; the inventory GET endpoint now uses `OperationalTenantScope::allowedStoreIds(..., 'inventory.view')`, not raw store membership. Write endpoint uses the precise `inventory.adjust` action, server-side store scope and product ownership checks. A cross-channel or foreign record remains 404.
- **Lifecycle/SoD:** new grants blocked while `inventory_wms` is disabled; existing grants remain historically recorded but lose runtime effect. The inventory controller receives no maker/checker or finance approval capability. All successful adjustments create a stock movement and audit event.
- **States:** authorized read/action, stale expected stock 422, unauthorized read 403, out-of-scope or disabled-module write 404, disabled-module read 403. No new credentials, secrets or seeds.

## Executable acceptance matrix

`backend/tests/Feature/V2InventoryControllerRoleAuthorityTest.php` covers:
1. exact permissions, B2B-only store scope, explicit denied sensitive actions, AR/EN presentation, authorized API inventory listing, and existing Wholesale Dashboard inventory route;
2. positive adjustment with persisted movement/audit, stale stock correction rejection, and B2C cross-channel adjustment denial;
3. disabled WMS and retained historic assignments with effective grant revocation, HTTP denial, and refusal of new role assignments.

Also subject to existing `InventoryAdministrationTest`, `BuiltInJobRoleCatalogTest` and `TenantOperationalIsolationTest`. **Remote exact-head CI is required**; this document records planned acceptance, not a claim that tests passed.

## Customer / Driver / Van impact

- **Customer Flutter:** no endpoint or runtime contract changed; inventory admin routes are not customer-facing. No app changes.
- **Driver Flutter:** no delivery status, collection, route or auth contract changed. No app changes.
- **Van Flutter:** no Van stock/consignment APIs or runtime behavior changed. No app changes.
- No new mobile Issue is required for these bounded Inventory Admin API authorization changes. If integration reveals a mobile contract dependency, separate V2 mobile Issue ownership applies.

## Out of scope and release protection

No broad inventory-manager grant, no creation of a replacement UI, no new ERP module activation, no `main` promotion, no tags, signed distribution, deployment or production action. Final merge is forbidden until all required CI checks on the exact final PR head pass. Source-wide T122R12 acceptance and end-stage manual/UAT evidence must be evaluated before marking the Issue completed; manual final-program UAT is tracked under master #2503 / UX gate #2750.
