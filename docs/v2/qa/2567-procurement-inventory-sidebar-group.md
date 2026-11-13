# P237 — Procurement, Inventory and Warehouses target Sidebar group

- Source: V2 target matrix `P237` / actual DOCX paragraph `P226`; leaf Issue #2567, parent #2503; integration exclusively `Foodex_V2`.
- Status: **semantic P237 parent now implemented in the shared Admin Sidebar renderer; exact-final-head CI and independent full UI/source acceptance still pending**. No production or merge claim.

## Source audit and placement decision

The source calls for the business heading **المشتريات والمخزون والمستودعات**, while the current canonical `AdminNavigation::groupsFor` and `config/admin_sidebar.php` intentionally expose three adjacent, separately authorized groups: `procurement` (P238, Procurement workbench), `inventory` (P246, existing Retail and eligible Wholesale inventory workspaces) and `warehouses` (P253/P254, WMS Warehouses tab). Those children already have independently merged source and executable role/tenant tests; a cosmetic wrapper cannot replace their real HTTP authorization.

**Placement:** procurement/inventory/warehouses business domain; the user goal is to discover authorized purchasing, stock and warehouse operations. Reuse the four canonical destination keys and real routes. P237 is composed as a semantic business-domain section (a localized heading plus `role=group` and `aria-labelledby`) around the existing **already authorized** adjacent child groups in `admin/_sidebar.blade.php`. No new route, permission, group registry key or additional interactive disclosure is introduced. On layouts with reordered, nonadjacent child groups, each contiguous authorized run gets its own labelled semantic section, preserving the saved layout order and never moving another business group or leaking an unauthorized child. The existing child `details/summary` remain native keyboard-accessible and the canonical search can hide an empty semantic section. This is **Compose**, not a duplicate destination.

## Current authoritative child inventory

| Key | Current group | Destination | Authority |
| --- | --- | --- | --- |
| `erp_procurement` | `procurement` | `admin.erp.procurement.index` | `procurement.suppliers.view`, module entitlement/readiness and tenant scope |
| `b2c_inventory` | `inventory` | `admin.b2c.module?module=inventory` | `inventory.view`, active Retail store |
| `b2b_inventory` | `inventory` | `admin.b2b.module?module=inventory` | `inventory.view`, eligible Wholesale actor only |
| `erp_wms` | `warehouses` | `admin.erp.wms.index?tab=warehouses` | `inventory.view`, WMS entitlement/readiness and store/warehouse scope |

The three existing child groups retain their canonical keys and tests (P238/P246/P253/P358). The semantic parent is presentation-only: it is rendered only when at least one authorized child group exists. It neither overrides `AdminSidebarManager` saved layouts nor adds a fourth interactive Sidebar level. Empty groups are still removed by the server. Neither Sidebar visibility nor a group title is a server authorization grant.

**Navigation contract:** `SIDEBAR` / existing canonical business group links and nested semantic parent; no `DIRECT_URL_ONLY` additions; existing independent HTTP guards remain authoritative. The only UI change is to `backend/resources/views/admin/_sidebar.blade.php` and two NEW issue-scoped AR/EN translation catalogs (`v2_sidebar_domains.php`). Responsive/collapsed Sidebar uses existing shell, logical border/padding, clipped visual heading in icon-only mode, and no new JS handlers. Desktop/tablet/mobile-web rely on native disclosure/focus primitives; live visual/keyboard acceptance remains to be independently verified.

## Security/quality and app impact

`V2ProcurementInventorySidebarGroupTest` protects unique child ownership, bilingual labels, stable relative order, permission/module/channel declarations, roleless direct HTTP denial, B2B ordinary-admin no-leak, and actual Retail Inventory HTTP rendering of the P237 semantic parent in AR/EN (RTL/LTR) without Procurement/WMS group leakage. A sibling Retail store direct URL is denied 403/404. Existing P238/P246/P253 tests retain deeper module-disabled and per-store guards. Automated tests are required and are **not** reported as passing until exact-head CI succeeds.

- **Dashboard:** P237 domain heading is now rendered as a semantic parent around existing authorized child groups in the canonical Sidebar; role/tenant/module/readiness server gates, existing child links, saved ordering and non-P237 groups are unchanged.
- **Customer:** no public API, auth/role, order or Flutter change in this scoped test/documentation checkpoint.
- **Driver:** no public API, B2C Driver dispatch, auth or Flutter change.
- **Van:** no public API, B2B Van assignments, stock, auth or Flutter change.

## Blocking dependencies and completion evidence

Issue #2527 / existing PR #3321 owns `backend/app/Support/AdminNavigation.php`, `backend/config/admin_sidebar.php` and Admin AR/EN navigation keys. **None were modified.** P237's semantic parent is implemented solely in the previously unowned canonical Sidebar view with NEW separate locale files, retaining source-level child keys and avoiding the peer's file fence. Independent source review must confirm the semantic parent is sufficient for the source P226 target (rather than changing underlying saved-layout semantics), plus keyboard/mobile-web/RTL/LTR UI acceptance. Coordinate changes to shared navigation core only after #2527 releases its lease.

All required CI (Repository Policy, V2 Isolation, Laravel/Pint/PHPStan/security, AR/EN, MySQL/Redis install/upgrade/recovery, terminal Required CI) must pass on the final exact PR HEAD before any merge. No final-head checks are asserted here. Manual browser/UAT may be deferred only to final V2 under #2503; source/automated acceptance cannot. Keep PR DRAFT and issue OPEN until full source + CI gates. No `main`, production, tag, release or publishing action.
