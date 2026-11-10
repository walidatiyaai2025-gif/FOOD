# FOODEX V2 #2713 — Inventory & Warehouses Sidebar persona (T353R4)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, `T353R4`. Parent #2503. Target **Foodex_V2 ONLY**; never `main` or production.

## Placement and canonical authority

- Domain/entity: inventory, warehouse stock and the existing wholesale/retail inventory workspaces.
- Actor: canonical global `INVENTORY` (B2B), distinct store-scoped `RETAIL_INVENTORY` (B2C). `WAREHOUSE_MANAGER` and `INVENTORY_CONTROLLER` are separate enterprise job roles with `inventory_wms` as a **required module** and must obey its independent readiness/certification gates.
- Reuse → Extend: reuse `AdminNavigation::groupsFor`, `AdminSidebarManager::apply`, `B2bWorkspaceController::show`, `B2cWorkspaceController::show` and the existing Catalog grouping. No duplicate route or independent visibility grant.
- Canonical route: `admin.b2b.module` with `module=inventory`; Retail `admin.b2c.module` uses a server-verified `store_id`. The Sidebar only projects server entitlement; its configuration is presentation-only.
- Source defect: `b2b_inventory` was conditional on `usesCustomerServiceDashboardProfile`, accidentally excluding a valid `INVENTORY` persona. The fix replaces this incorrect **UI-only** dependency with a narrowly scoped specialist presentation path that preserves the already-tested compact B2B_ADMIN and SUPER_ADMIN menus; `module()` still enforces the real channel and `inventory.view`. Their hidden legacy menu does **not** revoke authorized direct HTTP. The B2B controller continues to enforce the permission independently.
- The ERP WMS entry `erp_wms` stays **hidden** unless exact-version operational certification, lifecycle and entitlements allow access. Legacy B2B Inventory is **not** represented as a certified WMS or as finished stock-count/transfer ERP functionality.

## Scope and acceptance matrix

| Role/context | Sidebar projection | Direct server behavior |
| --- | --- | --- |
| Active B2B INVENTORY | `b2b_inventory`, existing Overview | wholesale inventory HTTP 200 |
| B2B_ADMIN and SUPER_ADMIN legacy compact menus | `b2b_inventory` stays hidden, preserving existing navigation assertions | independently permitted HTTP 200 |
| Same user in AR / RTL and EN / LTR | same authorized key, localized HTML direction | wholesale inventory HTTP 200 in both |
| Same user, no `orders.view`, `finance.view`, `reports.view` | operations, treasury, reports absent | no implicit rights granted |
| INVENTORY, WMS uncertified | legacy inventory link retained; `erp_wms` absent | no false ERP operational readiness |
| B2C RETAIL_INVENTORY, Store A | own `b2c_inventory`, never `b2b_inventory` | Store A HTTP 200, Store B 403/404, B2B 403 |
| Roleless actor | no wholesale inventory entry | direct B2B inventory 403 |

New automated regression: `backend/tests/Feature/V2InventoryWarehouseSidebarPersonaContractTest.php`. It runs against seeded canonical roles and fresh database state, with authenticated Laravel HTTP requests, AR/EN and forbidden cross-channel/cross-store paths. No live tenant, inventory, finance or permissions are mutated.

## Platform impacts and authorization

- Backend/Dashboard: one navigation condition corrected and one focused Laravel feature test; existing controllers, permissions, Store/Channel scope and module readiness remain authoritative. No new UI, routes, broad report grants or finance approval shortcuts.
- Customer: **not affected** — no Customer code/API/order/catalog endpoint contract changed.
- Driver: **not affected** — no B2C Driver dispatch, assignments, GPS, identity, or notification interface changed.
- Van: **not affected** — no B2B Van-only dispatch, visits, inventory API or routing interface changed.
- Data/audit: read/navigation-only; inventory adjustments, transfers, receipts and stock movements continue to use their existing audited and store-validated handlers. Finance maker/checker remains unchanged.
- Accessibility and responsive behavior: shared Dashboard template unchanged; real browser and screen-reader acceptance remains part of the final V2 isolated UI/UAT gate under #2750.

## WMS readiness bypass boundary (added regression)

The real WMS entry already exists at `admin.erp.wms.index` with tabs `overview`, `transfers`, `counts` and `reports`; operation routes include `admin.erp.wms.counts.store` and `admin.erp.wms.transfers.store`. They are NOT alternate names for the legacy `admin.b2b.module?module=inventory` page.

- `EnsureErpOperationalReadiness` is applied to the full `admin` route group and maps `admin.erp.wms.*` to `inventory_wms`. When `enterprise_module_readiness.certified_versions` contains no WMS certification, the middleware denies even a direct GET/POST with **403**, regardless of a legacy `inventory.view` grant.
- `V2InventoryWarehouseSidebarPersonaContractTest` now checks this distinction in Arabic/RTL and English/LTR: the authorized legacy inventory page remains HTTP 200; the uncertified WMS link is absent; four WMS tab deep links and both count/transfer POST endpoints must return HTTP 403. These are automated regression assertions, **not** proof of WMS production certification.
- The default operational readiness configuration remains empty and is not changed by #2713. No workflow attestation, entitlement, permission, or stock mutation is fabricated or granted here. #2858 / PR #3065 independently owns stronger ERP operational evidence verification; its code and test fixture paths must not be edited by this worker.

**Remaining functional acceptance:** prove authorized `WAREHOUSE_MANAGER` and `INVENTORY_CONTROLLER` with real scoped enabled-and-certified WMS, warehouse/store denial boundaries, read/report/count/transfer journeys and maker/checker where required, plus downstream module version/evidence checks. WMS certification itself must await G0–G13 evidence; never force-enable WMS to make this persona look complete. The PR remains DRAFT pending those requirements and latest exact-head CI.

## Important completion boundary

This change repairs the **legacy, permission-backed B2B inventory navigation**. It does not claim that inventory WMS stock counts/transfers and inventory report experiences are operationally certified. If #2713 requires those additional actual workflows for completion, retain the Issue and PR in active/blocked-repo state until the relevant ERP readiness children and acceptance evidence are integrated. Do not unlock an uncertified module or award global `reports.view` solely to make a Sidebar item appear.

## CI and delivery protocol

- Issue-scoped branch: `test/2713-v2-inventory-warehouse-sidebar-persona-contract`. Single PR targets `Foodex_V2` exclusively.
- Required before merge: PHP syntax/Pint/PHPStan, Laravel permission/channel/tenant tests, Repository Policy, V2 Isolation Gate, Localization AR/EN, security checks and `required-ci-gate` on the **latest exact PR HEAD**. Any failure is resolved in this same branch.
- No `main` merge, production release, ZIP/API publication, deployed config or cPanel operations.
- Manual UI/UAT/staging signoff is deliberately deferred to V2 program completion by owner decision on #2503; automated checks are not deferred.
