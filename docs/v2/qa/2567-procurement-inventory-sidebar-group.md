# P237 — Procurement, Inventory and Warehouses target Sidebar group

- Source: V2 target matrix `P237` / actual DOCX paragraph `P226`; leaf Issue #2567, parent #2503; integration exclusively `Foodex_V2`.
- Status: **source/structural acceptance pending**. This is an isolated contract checkpoint, not a declaration that the requested parent Sidebar group has been implemented.

## Source audit and placement decision

The source calls for the business heading **المشتريات والمخزون والمستودعات**, while the current canonical `AdminNavigation::groupsFor` and `config/admin_sidebar.php` intentionally expose three adjacent, separately authorized groups: `procurement` (P238, Procurement workbench), `inventory` (P246, existing Retail and eligible Wholesale inventory workspaces) and `warehouses` (P253/P254, WMS Warehouses tab). Those children already have independently merged source and executable role/tenant tests; a cosmetic wrapper cannot replace their real HTTP authorization.

**Placement:** procurement/inventory/warehouses business domain; the user goal is to discover authorized purchasing, stock and warehouse operations. Reuse the four canonical destination keys and real routes. The source-level parent P237 hierarchy needs an explicit, compatible composition decision before changing existing top-level group keys, renderer depth, saved layouts or permissions. A new stand-alone screen, route, API, hard-coded role grant or unready WMS shortcut is **not** justified.

## Current authoritative child inventory

| Key | Current group | Destination | Authority |
| --- | --- | --- | --- |
| `erp_procurement` | `procurement` | `admin.erp.procurement.index` | `procurement.suppliers.view`, module entitlement/readiness and tenant scope |
| `b2c_inventory` | `inventory` | `admin.b2c.module?module=inventory` | `inventory.view`, active Retail store |
| `b2b_inventory` | `inventory` | `admin.b2b.module?module=inventory` | `inventory.view`, eligible Wholesale actor only |
| `erp_wms` | `warehouses` | `admin.erp.wms.index?tab=warehouses` | `inventory.view`, WMS entitlement/readiness and store/warehouse scope |

The three existing groups stay separate until an approved P237 composition can preserve P238/P246/P253/P358 test invariants, `AdminSidebarManager` saved layouts, no fourth Sidebar level and all current user-role/channel boundaries. Empty groups continue to be removed. Neither Sidebar visibility nor a group title is a server authorization grant.

**Navigation contract:** `SIDEBAR` / existing business groups; canonical Admin shell; no `DIRECT_URL_ONLY` additions; authorized actors should discover real workbench routes; unauthorized access must be denied by the independent HTTP guard. Desktop/tablet/mobile-web and AR/EN RTL/LTR inherit canonical shared Admin shell; no new UI source has been changed in this checkpoint.

## Security/quality and app impact

`V2ProcurementInventorySidebarGroupTest` protects unique child ownership, bilingual labels, stable relative order, permission/module/channel declarations, roleless direct HTTP denial and B2B ordinary-admin no-leak projections. Neighbor P238/P246/P253 tests cover active-role permission, Retail same/foreign store, module disable, locale and URL behavior; these are not substitutes for the complete parent hierarchy validation.

- **Dashboard:** target P237 parent composition remains unimplemented until shared navigation file ownership is released and structure is reconciled without weakening role/tenant/module/readiness gates.
- **Customer:** no public API, auth/role, order or Flutter change in this scoped test/documentation checkpoint.
- **Driver:** no public API, B2C Driver dispatch, auth or Flutter change.
- **Van:** no public API, B2B Van assignments, stock, auth or Flutter change.

## Blocking dependencies and completion evidence

Issue #2527 / existing PR #3321 currently owns `backend/app/Support/AdminNavigation.php`, `backend/config/admin_sidebar.php` and Admin AR/EN navigation keys. Do not modify these concurrently. Obtain disjoint handoff or let that exact-head checked PR integrate, then inspect latest `Foodex_V2` and implement/verify compatible P237 hierarchy in **this same Issue/branch/PR**, with effective role and direct HTTP tests, shared Dashboard keyboard/mobile RTL/LTR acceptance and no fourth level.

All required CI (Repository Policy, V2 Isolation, Laravel/Pint/PHPStan/security, AR/EN, MySQL/Redis install/upgrade/recovery, terminal Required CI) must pass on the final exact PR HEAD before any merge. CI results are not asserted here. Manual browser/UAT can be deferred only to final V2 under #2503; source/automated acceptance cannot. **Do not close #2567 from this checkpoint.** No `main`, production, tag, release or publishing action.
